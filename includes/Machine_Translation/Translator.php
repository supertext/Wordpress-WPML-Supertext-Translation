<?php
/**
 * @package Supertext_Wpml
 */

namespace Supertext\Wpml\Machine_Translation;

defined( 'ABSPATH' ) || exit;

use Supertext\Wpml\Content\Collector;
use Supertext\Wpml\Content\Writer;
use Supertext\Wpml\Wpml\Wpml;
use WP_Error;
use WP_Post;

/**
 * AI translation of one post into one language: collect the texts (Collector), send
 * them to Supertext in one file (Client), write the translation (Writer). Used by the
 * *Supertext AI Translation* bulk action and the Supertext box in the editor.
 *
 * @since 0.1.0
 */
class Translator {
	/** Post meta on the translation: when and from which source it was AI-translated. */
	const META = '_supertext_ai_translation';

	/**
	 * Checks that a post can be translated into a language by the current user.
	 *
	 * @param int    $post_id     Source post id.
	 * @param string $target_code WPML code.
	 * @return true|WP_Error
	 */
	public static function check( int $post_id, string $target_code ) {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || 'trash' === $post->post_status ) {
			return new WP_Error( 'supertext_not_found', __( 'The post was not found.', 'supertext-wpml' ) );
		}
		if ( ! Wpml::is_translated_post_type( $post->post_type ) ) {
			return new WP_Error(
				'supertext_not_translatable',
				sprintf(
					/* translators: %s: post title. */
					__( '"%s": WPML does not translate this post type. Turn translation on under WPML → Settings → Post Types Translation.', 'supertext-wpml' ),
					$post->post_title
				)
			);
		}
		$source = Wpml::post_language( $post_id );
		$target = Wpml::language( $target_code );
		if ( '' === $source || null === $target ) {
			return new WP_Error( 'supertext_invalid', __( 'Invalid post, or missing source/target language.', 'supertext-wpml' ) );
		}
		if ( $source === $target_code ) {
			return new WP_Error(
				'supertext_same_language',
				sprintf(
					/* translators: 1: post title, 2: language code. */
					__( '"%1$s": source and target language are the same (%2$s).', 'supertext-wpml' ),
					$post->post_title,
					$target_code
				)
			);
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'supertext_not_allowed', __( 'Sorry, you are not allowed to edit this post.', 'supertext-wpml' ) );
		}
		$existing = Wpml::post_translation( $post_id, $target_code );
		if ( $existing > 0 ? ! current_user_can( 'edit_post', $existing ) : ! current_user_can( (string) get_post_type_object( $post->post_type )?->cap->edit_posts ) ) {
			return new WP_Error( 'supertext_not_allowed', __( 'Sorry, you are not allowed to edit this post.', 'supertext-wpml' ) );
		}

		return true;
	}

	/**
	 * Translates a post into a language with Supertext AI.
	 *
	 * @param int    $post_id     Source post id.
	 * @param string $target_code WPML code of the target language.
	 * @param bool   $overwrite   Whether to overwrite an existing translation (else it is an error).
	 * @return int|WP_Error The translation's post id.
	 */
	public static function translate( int $post_id, string $target_code, bool $overwrite = false ) {
		$check = self::check( $post_id, $target_code );
		if ( $check instanceof WP_Error ) {
			return $check;
		}
		$post   = get_post( $post_id );
		$target = Wpml::language( $target_code );
		$source = Wpml::language( Wpml::post_language( $post_id ) );
		if ( ! $post instanceof WP_Post || null === $target ) {
			return new WP_Error( 'supertext_invalid', __( 'Invalid post, or missing source/target language.', 'supertext-wpml' ) );
		}

		$existing = Wpml::post_translation( $post_id, $target_code );
		if ( $existing > 0 && self::is_original_of( $existing, $post_id ) ) {
			return new WP_Error(
				'supertext_would_overwrite_original',
				sprintf(
					/* translators: 1: post title, 2: language name. */
					__( '"%1$s" is a translation of the %2$s original. Translate the original instead: its text would be overwritten.', 'supertext-wpml' ),
					$post->post_title,
					$target->name
				)
			);
		}
		if ( ! $overwrite && $existing > 0 ) {
			return new WP_Error(
				'supertext_already_translated',
				sprintf(
					/* translators: 1: post title, 2: language name. */
					__( '"%1$s" already has a translation into %2$s. Open it in the editor\'s Supertext box to translate it again.', 'supertext-wpml' ),
					$post->post_title,
					$target->name
				)
			);
		}

		if ( ! Ai_Settings::has_api_key() ) {
			return new WP_Error( 'supertext_authentication_failure', __( 'No Supertext API key is set. Enter it under Supertext → Settings → AI translation.', 'supertext-wpml' ) );
		}

		$entries = Collector::collect( $post, $target_code );
		if ( array() === $entries ) {
			return new WP_Error(
				'supertext_empty',
				sprintf(
					/* translators: %s: post title. */
					__( '"%s": no translatable content found.', 'supertext-wpml' ),
					$post->post_title
				)
			);
		}

		$translated = Ai_Settings::client()->translate( Collector::segments( $entries ), $target, $source );
		if ( $translated instanceof WP_Error ) {
			return $translated;
		}

		$map = array();
		foreach ( $translated as $context => $text ) {
			$map[ (string) $context ] = (string) $text;
		}

		$saved = Writer::save( $post, $target, $map );
		if ( $saved['post_id'] <= 0 ) {
			return new WP_Error(
				'supertext_save_failed',
				sprintf(
					/* translators: 1: post title, 2: error message. */
					__( '"%1$s": the translation could not be saved (%2$s).', 'supertext-wpml' ),
					$post->post_title,
					implode( ', ', $saved['errors'] )
				)
			);
		}

		update_post_meta(
			$saved['post_id'],
			self::META,
			array(
				'source'        => $post_id,
				'translated_at' => gmdate( 'c' ),
			)
		);

		return $saved['post_id'];
	}

	/**
	 * Whether a post is the original (source) of a translation's group, i.e.
	 * translating "into" it would overwrite the text the translation came from.
	 *
	 * @param int $candidate   The post in the target language.
	 * @param int $translation The post being translated.
	 * @return bool
	 */
	public static function is_original_of( int $candidate, int $translation ): bool {
		$type     = (string) get_post_type( $translation );
		$own      = Wpml::details( $translation, $type );
		$other    = Wpml::details( $candidate, $type );
		return null !== $own && null !== $other
			&& '' !== $own['source_language_code']
			&& '' === $other['source_language_code'];
	}
}
