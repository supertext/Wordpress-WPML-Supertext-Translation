<?php
/**
 * @package Supertext_Wpml
 */

namespace Supertext\Wpml\Content;

defined( 'ABSPATH' ) || exit;

use Supertext\Wpml\Integrations\YooTheme\Layout;
use Supertext\Wpml\Wpml\Language;
use Supertext\Wpml\Wpml\Wpml;
use WP_Error;
use WP_Post;
use WP_Term;

/**
 * Writes translations (context => text, see Collector) into a post's translation:
 * creates it as a draft and links it to the source in WPML when there is none yet,
 * or updates the existing translation. Shared by AI translation, human orders and
 * translation file imports.
 *
 * - Title, excerpt and custom fields: the translation where there is one; a new
 *   translation takes the source's value for the rest, an existing one keeps its own.
 * - Content: rebuilt from the source with the translated parts (a part without a
 *   translation keeps the source text); YOOtheme layouts field by field.
 * - Custom fields WPML *copies* are copied, *copy once* only into a new translation
 *   (plus the featured image and page template of a new translation).
 * - Terms in taxonomies WPML translates: the existing term translation, else a new
 *   term from the translated name (linked in WPML); a term without either is left out.
 *   Terms of other taxonomies are copied.
 * - Parent page: the parent's translation, when there is one.
 *
 * @since 0.1.0
 */
class Writer {
	/**
	 * Writes translations into the translation of a post.
	 *
	 * @param WP_Post               $source The source post.
	 * @param Language              $target The target language.
	 * @param array<string, string> $map    Context => translated text (plain text for text contexts).
	 * @param string|null           $status Post status for the translation: 'draft', 'publish',
	 *                                      or null (new: draft; existing: unchanged).
	 * @return array{post_id: int, filled: int, created: bool, errors: string[]}
	 */
	public static function save( WP_Post $source, Language $target, array $map, ?string $status = null ): array {
		$result = array(
			'post_id' => 0,
			'filled'  => 0,
			'created' => false,
			'errors'  => array(),
		);

		$source_code = Wpml::post_language( $source->ID );
		if ( '' === $source_code || $source_code === $target->code ) {
			$result['errors'][] = 'no_language';
			return $result;
		}

		// Read in the source's language (WPML filters term queries by the current language).
		$source_terms = Wpml::in_language( $source_code, static fn() => self::source_terms( $source ) );

		return Wpml::in_language(
			$target->code,
			static function () use ( $source, $target, $map, $status, $source_terms, $result ) {
				$existing = Wpml::post_translation( $source->ID, $target->code );
				$current  = $existing > 0 ? get_post( $existing ) : null;
				$current  = $current instanceof WP_Post ? $current : null;

				$filled = 0;
				$pick   = static function ( string $context, string $fallback ) use ( $map, &$filled ): string {
					if ( isset( $map[ $context ] ) && '' !== trim( $map[ $context ] ) ) {
						++$filled;
						return $map[ $context ];
					}
					return $fallback;
				};

				$title   = $pick( 'title', null !== $current ? $current->post_title : $source->post_title );
				$excerpt = $pick( 'excerpt', null !== $current ? $current->post_excerpt : $source->post_excerpt );
				$content = self::content( (string) $source->post_content, $map, $filled, $target->code );

				// Not filtered by kses beyond what WordPress does for the current user.
				$data = array(
					'post_title'   => $title,
					'post_content' => $content,
					'post_excerpt' => $excerpt,
				);

				if ( null === $current ) {
					$parent = (int) $source->post_parent > 0 ? Wpml::post_translation( (int) $source->post_parent, $target->code ) : 0;
					$author = get_current_user_id();
					$data  += array(
						'post_type'      => $source->post_type,
						'post_status'    => 'publish' === $status ? 'publish' : 'draft',
						'post_author'    => $author > 0 ? $author : (int) $source->post_author,
						'post_parent'    => $parent,
						'menu_order'     => (int) $source->menu_order,
						'comment_status' => $source->comment_status,
						'ping_status'    => $source->ping_status,
						'post_password'  => $source->post_password,
					);
					$id = wp_insert_post( wp_slash( $data ), true );
				} else {
					$data['ID'] = $current->ID;
					if ( null !== $status ) {
						$data['post_status'] = 'publish' === $status ? 'publish' : 'draft';
					}
					$id = wp_update_post( wp_slash( $data ), true );
				}

				if ( $id instanceof WP_Error ) {
					$result['errors'][] = $id->get_error_message();
					return $result;
				}

				if ( null === $current ) {
					Wpml::link_post( $id, $source->ID, $target->code );
				}

				$filled += self::fields( $source, $id, null === $current, $map );
				$filled += self::terms( $source, $id, $target, $source_terms, $map );

				/**
				 * Fires after Supertext wrote a translation.
				 *
				 * @param int                   $id     The translation's post id.
				 * @param WP_Post               $source The source post.
				 * @param Language              $target The target language.
				 * @param array<string, string> $map    Context => translated text.
				 */
				do_action( 'supertext_wpml_translation_saved', $id, $source, $target, $map );

				$result['post_id'] = $id;
				$result['filled']  = $filled;
				$result['created'] = null === $current;

				return $result;
			}
		);
	}

	/**
	 * The translated content: the source rebuilt with the translated parts, block
	 * texts (ACF block fields) put back into their blocks, the YOOtheme layout field by field.
	 *
	 * @param string                $source      The source content.
	 * @param array<string, string> $map         Context => text.
	 * @param int                   $filled      Counter of the texts used.
	 * @param string                $target_code WPML code of the target language (for block texts).
	 * @return string
	 */
	public static function content( string $source, array $map, int &$filled = 0, string $target_code = '' ): string {
		$parts = array();
		foreach ( $map as $context => $text ) {
			if ( str_starts_with( (string) $context, 'content:' ) ) {
				$parts[ (int) substr( (string) $context, 8 ) ] = (string) $text;
			}
		}
		$texts = Content_Splitter::texts( $source );
		$parts = array_intersect_key( $parts, $texts );
		$filled += count( $parts );

		$content = Content_Splitter::join( $source, $parts );
		$content = Block_Strings::apply( $content, $map, $target_code, $filled );

		$layout = Layout::decode( $content );
		if ( null !== $layout ) {
			$layout  = Layout::map(
				$layout,
				static function ( string $path, string $value ) use ( $map, &$filled ): string {
					$key = 'yootheme:' . $path;
					if ( isset( $map[ $key ] ) && '' !== trim( $map[ $key ] ) ) {
						++$filled;
						return $map[ $key ];
					}
					return $value;
				}
			);
			$content = Layout::encode( $content, $layout );
		}

		return $content;
	}

	/**
	 * Custom fields: translated ones, copied ones, copied-once ones (new translations only).
	 *
	 * @param WP_Post               $source  Source post.
	 * @param int                   $id      Translation id.
	 * @param bool                  $created Whether the translation is new.
	 * @param array<string, string> $map     Context => text.
	 * @return int Translated fields written.
	 */
	private static function fields( WP_Post $source, int $id, bool $created, array $map ): int {
		$filled = 0;
		foreach ( $map as $context => $text ) {
			if ( str_starts_with( (string) $context, 'meta:' ) && '' !== trim( (string) $text ) ) {
				update_post_meta( $id, substr( (string) $context, 5 ), wp_slash( (string) $text ) );
				++$filled;
			}
		}

		$always_once = $created ? array( '_thumbnail_id', '_wp_page_template' ) : array();
		foreach ( array_keys( (array) get_post_meta( $source->ID ) ) as $key ) {
			$key     = (string) $key;
			$setting = Wpml::field_setting( $key );
			$copy    = Wpml::FIELD_COPY === $setting || ( $created && ( Wpml::FIELD_COPY_ONCE === $setting || in_array( $key, $always_once, true ) ) );
			if ( ! $copy || isset( $map[ 'meta:' . $key ] ) ) {
				continue;
			}
			delete_post_meta( $id, $key );
			foreach ( (array) get_post_meta( $source->ID, $key, false ) as $value ) {
				add_post_meta( $id, $key, wp_slash( maybe_unserialize( $value ) ) );
			}
		}

		return $filled;
	}

	/**
	 * The source's terms per taxonomy.
	 *
	 * @param WP_Post $source Source post.
	 * @return array<string, WP_Term[]>
	 */
	private static function source_terms( WP_Post $source ): array {
		$out = array();
		foreach ( get_object_taxonomies( $source->post_type ) as $taxonomy ) {
			$terms = wp_get_object_terms( $source->ID, $taxonomy );
			$out[ $taxonomy ] = is_array( $terms ) ? array_values( $terms ) : array();
		}

		return $out;
	}

	/**
	 * Sets the translation's terms (see the class comment).
	 *
	 * @param WP_Post                  $source       Source post.
	 * @param int                      $id           Translation id.
	 * @param Language                 $target       Target language.
	 * @param array<string, WP_Term[]> $source_terms Taxonomy => the source's terms.
	 * @param array<string, string>    $map          Context => text.
	 * @return int Translated term names used.
	 */
	private static function terms( WP_Post $source, int $id, Language $target, array $source_terms, array $map ): int {
		$filled = 0;
		foreach ( $source_terms as $taxonomy => $terms ) {
			if ( ! Wpml::is_translated_taxonomy( $taxonomy ) ) {
				wp_set_object_terms( $id, array_map( static fn( WP_Term $t ) => (int) $t->term_id, $terms ), $taxonomy );
				continue;
			}
			$ids = array();
			foreach ( $terms as $term ) {
				$translation = Wpml::term_translation( (int) $term->term_id, $taxonomy, $target->code );
				if ( 0 === $translation ) {
					$name = trim( (string) ( $map[ 'term:' . $taxonomy . ':' . $term->term_id ] ?? '' ) );
					if ( '' === $name ) {
						continue;
					}
					$translation = self::create_term( $term, $name, (string) ( $map[ 'term_description:' . $taxonomy . ':' . $term->term_id ] ?? '' ), $target );
					if ( $translation > 0 ) {
						++$filled;
					}
				}
				if ( $translation > 0 ) {
					$ids[] = $translation;
				}
			}
			wp_set_object_terms( $id, $ids, $taxonomy );
		}

		return $filled;
	}

	/**
	 * Creates a term's translation and links it in WPML.
	 *
	 * @param WP_Term  $term        Source term.
	 * @param string   $name        Translated name.
	 * @param string   $description Translated description ('' = none).
	 * @param Language $target      Target language.
	 * @return int The new term's id, 0 on failure.
	 */
	private static function create_term( WP_Term $term, string $name, string $description, Language $target ): int {
		$parent = (int) $term->parent > 0 ? Wpml::term_translation( (int) $term->parent, $term->taxonomy, $target->code ) : 0;
		$args   = array(
			'description' => $description,
			'parent'      => $parent,
			'slug'        => sanitize_title( $name ),
		);
		$new = wp_insert_term( $name, $term->taxonomy, $args );
		if ( $new instanceof WP_Error ) {
			// Same name or slug as an existing term (e.g. the source term itself): a language suffix.
			$args['slug'] = sanitize_title( $name . '-' . $target->code );
			$new          = wp_insert_term( $name, $term->taxonomy, $args );
		}
		if ( $new instanceof WP_Error ) {
			return 0;
		}
		$new_id = (int) $new['term_id'];
		Wpml::link_term( $new_id, (int) $term->term_id, $term->taxonomy, $target->code );

		return $new_id;
	}
}
