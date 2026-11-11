<?php
/**
 * @package Supertext_Wpml
 */

namespace Supertext\Wpml\Content;

defined( 'ABSPATH' ) || exit;

use Supertext\Wpml\Integrations\YooTheme\Layout;
use Supertext\Wpml\Sdk\V1\Segment;
use Supertext\Wpml\Wpml\Wpml;
use WP_Post;
use WP_Term;

/**
 * Collects the texts of a post that are translated: the one place that decides what
 * AI translation, human orders and translation files send (and Writer puts back).
 *
 * Each text has a *context* (its key, stable for the same source):
 *
 * | Context                              | Text                                           | Sent as |
 * | ------------------------------------ | ---------------------------------------------- | ------- |
 * | `title`                              | the title                                      | text    |
 * | `excerpt`                            | the excerpt                                    | HTML    |
 * | `content:<n>`                        | part n of the content (Content_Splitter)       | HTML    |
 * | `yootheme:<path>`                    | a YOOtheme Pro layout text (Layout)            | HTML    |
 * | `meta:<key>`                         | a custom field WPML translates                 | text, HTML if it has tags |
 * | `term:<taxonomy>:<id>`               | the name of a term without a translation yet   | text    |
 * | `term_description:<taxonomy>:<id>`   | its description                                | HTML    |
 *
 * Custom fields: those set to *Translate* in WPML (WPML → Settings → Custom fields
 * translation, or a plugin's wpml-config.xml, e.g. Yoast SEO's title and description),
 * with a single non-numeric string value; the `supertext_wpml_meta_keys` filter
 * changes the list. Terms: of the taxonomies WPML translates, the ones the post has
 * that have no translation in the target language yet (an existing term translation
 * is used as it is).
 *
 * @since 0.1.0
 */
class Collector {
	/**
	 * Collects a post's texts for one target language.
	 *
	 * @param WP_Post $post        Source post.
	 * @param string  $target_code WPML code of the target language.
	 * @return array<int, array{context: string, singular: string, html: bool}>
	 */
	public static function collect( WP_Post $post, string $target_code ): array {
		// Read the post's terms in its own language (WPML filters term queries by the current language).
		return Wpml::in_language( Wpml::post_language( $post->ID ), static fn() => self::read( $post, $target_code ) );
	}

	/**
	 * Collects the texts (see collect()).
	 *
	 * @param WP_Post $post        Source post.
	 * @param string  $target_code WPML code of the target language.
	 * @return array<int, array{context: string, singular: string, html: bool}>
	 */
	private static function read( WP_Post $post, string $target_code ): array {
		$entries = array();
		$add     = static function ( string $context, string $text, bool $html ) use ( &$entries ): void {
			if ( '' === trim( $text ) ) {
				return;
			}
			$entries[] = array(
				'context'  => $context,
				'singular' => $text,
				'html'     => $html,
			);
		};

		$add( 'title', (string) $post->post_title, false );
		$add( 'excerpt', (string) $post->post_excerpt, true );

		$content = (string) $post->post_content;
		foreach ( Content_Splitter::texts( $content ) as $i => $text ) {
			$add( 'content:' . $i, $text, true );
		}
		$layout = Layout::decode( $content );
		if ( null !== $layout ) {
			foreach ( Layout::collect( $layout ) as $path => $text ) {
				$add( 'yootheme:' . $path, (string) $text, true );
			}
		}

		foreach ( self::meta_keys( $post ) as $key ) {
			$values = get_post_meta( $post->ID, $key, false );
			if ( ! is_array( $values ) || 1 !== count( $values ) || ! is_string( $values[0] ) || is_numeric( $values[0] ) || is_serialized( $values[0] ) ) {
				continue;
			}
			$add( 'meta:' . $key, $values[0], self::looks_like_html( $values[0] ) );
		}

		foreach ( self::untranslated_terms( $post, $target_code ) as $term ) {
			$add( 'term:' . $term->taxonomy . ':' . $term->term_id, $term->name, false );
			$add( 'term_description:' . $term->taxonomy . ':' . $term->term_id, $term->description, true );
		}

		/**
		 * Filters the texts sent for a post.
		 *
		 * @param array   $entries     List of { context, singular, html }.
		 * @param WP_Post $post        The source post.
		 * @param string  $target_code WPML code of the target language.
		 */
		$entries = apply_filters( 'supertext_wpml_collected_entries', $entries, $post, $target_code );

		return array_values( $entries );
	}

	/**
	 * The texts as SDK segments, keyed by context.
	 *
	 * @param array<int, array{context: string, singular: string, html: bool}> $entries Entries.
	 * @return array<string, Segment>
	 */
	public static function segments( array $entries ): array {
		$out = array();
		foreach ( $entries as $entry ) {
			$out[ $entry['context'] ] = $entry['html'] ? Segment::html( $entry['singular'] ) : Segment::text( $entry['singular'] );
		}

		return $out;
	}

	/**
	 * The custom fields of a post that are translated.
	 *
	 * @param WP_Post $post Post.
	 * @return string[]
	 */
	public static function meta_keys( WP_Post $post ): array {
		$keys = array();
		foreach ( array_keys( (array) get_post_meta( $post->ID ) ) as $key ) {
			if ( Wpml::FIELD_TRANSLATE === Wpml::field_setting( (string) $key ) ) {
				$keys[] = (string) $key;
			}
		}

		/**
		 * Filters the custom fields of a post that are translated (default: those WPML
		 * translates).
		 *
		 * @param string[] $keys Meta keys.
		 * @param WP_Post  $post The post.
		 */
		$keys = apply_filters( 'supertext_wpml_meta_keys', $keys, $post );

		return array_values( array_unique( array_map( 'strval', $keys ) ) );
	}

	/**
	 * The post's terms (in taxonomies WPML translates) without a translation in the target language.
	 *
	 * @param WP_Post $post        Post.
	 * @param string  $target_code WPML code.
	 * @return WP_Term[]
	 */
	public static function untranslated_terms( WP_Post $post, string $target_code ): array {
		$out = array();
		foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
			if ( ! Wpml::is_translated_taxonomy( $taxonomy ) ) {
				continue;
			}
			$terms = wp_get_object_terms( $post->ID, $taxonomy );
			foreach ( is_array( $terms ) ? $terms : array() as $term ) {
				if ( 0 === Wpml::term_translation( (int) $term->term_id, $taxonomy, $target_code ) ) {
					$out[] = $term;
				}
			}
		}

		return $out;
	}

	/**
	 * Whether a field value has HTML tags.
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	public static function looks_like_html( string $value ): bool {
		return 1 === preg_match( '/<[a-zA-Z][^>]*>/', $value );
	}
}
