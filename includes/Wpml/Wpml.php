<?php
/**
 * @package Supertext_Wpml
 */

namespace Supertext\Wpml\Wpml;

defined( 'ABSPATH' ) || exit;

use WP_Post;

/**
 * Everything this plugin asks WPML, through WPML's public hooks only
 * (https://wpml.org/documentation/support/wpml-coding-api/wpml-hooks-reference/):
 * the active languages, the language and translations of a post or term, linking a
 * new translation to its source, and WPML's translation setting of a custom field.
 *
 * WPML keeps posts and terms in "translation groups" (`trid`); a translation is an
 * ordinary post (or term) in another language with the same `trid`. Element types
 * are `post_<post type>` and `tax_<taxonomy>`; a term's element id is its
 * term_taxonomy_id.
 *
 * @since 0.1.0
 */
class Wpml {
	/** WPML custom field settings (Settings → Custom fields translation). */
	const FIELD_IGNORE    = 0;
	const FIELD_COPY      = 1;
	const FIELD_TRANSLATE = 2;
	const FIELD_COPY_ONCE = 3;

	/**
	 * Per-request cache of the active languages.
	 *
	 * @var array<string, Language>|null
	 */
	private static ?array $languages = null;

	/**
	 * Per-request cache of WPML's custom field settings.
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $field_settings = null;

	/**
	 * Whether WPML (the WPML Multilingual CMS plugin, "sitepress") is active.
	 *
	 * @return bool
	 */
	public static function is_active(): bool {
		return defined( 'ICL_SITEPRESS_VERSION' ) && has_filter( 'wpml_active_languages' );
	}

	/**
	 * WPML's version, '' when WPML isn't active.
	 *
	 * @return string
	 */
	public static function version(): string {
		return defined( 'ICL_SITEPRESS_VERSION' ) ? (string) constant( 'ICL_SITEPRESS_VERSION' ) : '';
	}

	/**
	 * The active languages, WPML's order, keyed by WPML code.
	 *
	 * @return array<string, Language>
	 */
	public static function languages(): array {
		if ( null !== self::$languages ) {
			return self::$languages;
		}
		$list = self::is_active() ? apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) ) : array();
		$out  = array();
		foreach ( is_array( $list ) ? $list : array() as $code => $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$code = (string) ( $item['code'] ?? $code );
			if ( '' === $code ) {
				continue;
			}
			$name   = (string) ( $item['translated_name'] ?? '' );
			$name   = '' !== $name ? $name : (string) ( $item['native_name'] ?? $code );
			$locale = trim( (string) ( $item['default_locale'] ?? '' ) );

			$out[ $code ] = new Language( $code, $name, '' !== $locale ? $locale : $code );
		}
		self::$languages = $out;

		return $out;
	}

	/**
	 * Forgets the cached languages and field settings (tests).
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$languages      = null;
		self::$field_settings = null;
	}

	/**
	 * One active language by WPML code.
	 *
	 * @param string $code WPML code.
	 * @return Language|null
	 */
	public static function language( string $code ): ?Language {
		return self::languages()[ $code ] ?? null;
	}

	/**
	 * The site's default language code ('' without WPML).
	 *
	 * @return string
	 */
	public static function default_code(): string {
		return self::is_active() ? (string) apply_filters( 'wpml_default_language', null ) : '';
	}

	/**
	 * Whether WPML translates this post type.
	 *
	 * @param string $post_type Post type.
	 * @return bool
	 */
	public static function is_translated_post_type( string $post_type ): bool {
		return self::is_active() && (bool) apply_filters( 'wpml_is_translated_post_type', false, $post_type );
	}

	/**
	 * Whether WPML translates this taxonomy.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @return bool
	 */
	public static function is_translated_taxonomy( string $taxonomy ): bool {
		return self::is_active() && (bool) apply_filters( 'wpml_is_translated_taxonomy', false, $taxonomy );
	}

	/**
	 * The WPML language details of an element: trid, language code, source language.
	 *
	 * @param int    $element_id Post id or term_taxonomy_id.
	 * @param string $type       Post type or taxonomy (without prefix).
	 * @return array{trid: int, language_code: string, source_language_code: string}|null
	 */
	public static function details( int $element_id, string $type ): ?array {
		if ( ! self::is_active() || $element_id <= 0 ) {
			return null;
		}
		$details = apply_filters(
			'wpml_element_language_details',
			null,
			array(
				'element_id'   => $element_id,
				'element_type' => $type,
			)
		);
		if ( ! is_object( $details ) && ! is_array( $details ) ) {
			return null;
		}
		$details = (array) $details;
		$code    = (string) ( $details['language_code'] ?? '' );
		if ( '' === $code ) {
			return null;
		}

		return array(
			'trid'                 => (int) ( $details['trid'] ?? 0 ),
			'language_code'        => $code,
			'source_language_code' => (string) ( $details['source_language_code'] ?? '' ),
		);
	}

	/**
	 * A post's language code, '' when it has none (or WPML doesn't translate its type).
	 *
	 * @param int $post_id Post id.
	 * @return string
	 */
	public static function post_language( int $post_id ): string {
		$type = (string) get_post_type( $post_id );
		if ( '' === $type || ! self::is_translated_post_type( $type ) ) {
			return '';
		}

		return self::details( $post_id, $type )['language_code'] ?? '';
	}

	/**
	 * All posts of a post's translation group: language code => post id (the post itself included).
	 *
	 * @param int $post_id Post id.
	 * @return array<string, int>
	 */
	public static function post_translations( int $post_id ): array {
		$type    = (string) get_post_type( $post_id );
		$details = '' !== $type ? self::details( $post_id, $type ) : null;
		if ( null === $details ) {
			return array();
		}
		if ( $details['trid'] <= 0 ) {
			return array( $details['language_code'] => $post_id );
		}
		$list = apply_filters( 'wpml_get_element_translations', null, $details['trid'], 'post_' . $type );
		$out  = array();
		foreach ( is_array( $list ) ? $list : array() as $code => $item ) {
			$item = (array) $item;
			$id   = (int) ( $item['element_id'] ?? 0 );
			$code = (string) ( $item['language_code'] ?? $code );
			if ( $id > 0 && '' !== $code && get_post( $id ) instanceof WP_Post ) {
				$out[ $code ] = $id;
			}
		}
		$out[ $details['language_code'] ] = $post_id;

		return $out;
	}

	/**
	 * The id of a post's translation into a language, 0 when there is none.
	 *
	 * @param int    $post_id Post id.
	 * @param string $code    WPML code.
	 * @return int
	 */
	public static function post_translation( int $post_id, string $code ): int {
		$id = self::post_translations( $post_id )[ $code ] ?? 0;
		return $id !== $post_id ? $id : 0;
	}

	/**
	 * Puts a post into a language and into its source's translation group.
	 *
	 * @param int    $translation_id The translation's post id.
	 * @param int    $source_id      The source post's id.
	 * @param string $code           The translation's WPML code.
	 * @return void
	 */
	public static function link_post( int $translation_id, int $source_id, string $code ): void {
		$type    = (string) get_post_type( $source_id );
		$details = self::details( $source_id, $type );
		do_action(
			'wpml_set_element_language_details',
			array(
				'element_id'           => $translation_id,
				'element_type'         => 'post_' . $type,
				'trid'                 => $details['trid'] ?? false,
				'language_code'        => $code,
				'source_language_code' => $details['language_code'] ?? null,
			)
		);
	}

	/**
	 * The id of a term's translation into a language, 0 when there is none.
	 *
	 * @param int    $term_id  Term id.
	 * @param string $taxonomy Taxonomy.
	 * @param string $code     WPML code.
	 * @return int
	 */
	public static function term_translation( int $term_id, string $taxonomy, string $code ): int {
		$id = apply_filters( 'wpml_object_id', $term_id, $taxonomy, false, $code );
		return null === $id ? 0 : (int) $id;
	}

	/**
	 * A term's language code, '' when it has none.
	 *
	 * @param int    $term_id  Term id.
	 * @param string $taxonomy Taxonomy.
	 * @return string
	 */
	public static function term_language( int $term_id, string $taxonomy ): string {
		$term = get_term( $term_id, $taxonomy );
		if ( ! $term instanceof \WP_Term ) {
			return '';
		}

		return self::details( (int) $term->term_taxonomy_id, $taxonomy )['language_code'] ?? '';
	}

	/**
	 * Puts a term into a language and into its source's translation group.
	 *
	 * @param int    $translation_id The translation's term id.
	 * @param int    $source_id      The source term's id.
	 * @param string $taxonomy       Taxonomy.
	 * @param string $code           The translation's WPML code.
	 * @return void
	 */
	public static function link_term( int $translation_id, int $source_id, string $taxonomy, string $code ): void {
		$source      = get_term( $source_id, $taxonomy );
		$translation = get_term( $translation_id, $taxonomy );
		if ( ! $source instanceof \WP_Term || ! $translation instanceof \WP_Term ) {
			return;
		}
		$details = self::details( (int) $source->term_taxonomy_id, $taxonomy );
		do_action(
			'wpml_set_element_language_details',
			array(
				'element_id'           => (int) $translation->term_taxonomy_id,
				'element_type'         => 'tax_' . $taxonomy,
				'trid'                 => $details['trid'] ?? false,
				'language_code'        => $code,
				'source_language_code' => $details['language_code'] ?? null,
			)
		);
	}

	/**
	 * Runs a callback with WPML's current language switched (WPML filters post and
	 * term queries by it), then switches back. '' or no WPML: just runs it.
	 *
	 * @template T
	 * @param string          $code     WPML code.
	 * @param callable(): T   $callback Callback.
	 * @return T
	 */
	public static function in_language( string $code, callable $callback ) {
		if ( '' === $code || ! self::is_active() ) {
			return $callback();
		}
		$previous = apply_filters( 'wpml_current_language', null );
		do_action( 'wpml_switch_language', $code );
		try {
			return $callback();
		} finally {
			do_action( 'wpml_switch_language', $previous );
		}
	}

	/**
	 * WPML's translation setting of a custom field (WPML → Settings → Custom fields
	 * translation, or a plugin's wpml-config.xml): FIELD_TRANSLATE, FIELD_COPY,
	 * FIELD_COPY_ONCE or FIELD_IGNORE (also for fields WPML has no setting for).
	 *
	 * @param string $key Meta key.
	 * @return int
	 */
	public static function field_setting( string $key ): int {
		if ( null === self::$field_settings ) {
			$tm                   = self::is_active() ? apply_filters( 'wpml_setting', array(), 'translation-management' ) : array();
			self::$field_settings = is_array( $tm ) && isset( $tm['custom_fields_translation'] ) && is_array( $tm['custom_fields_translation'] ) ? $tm['custom_fields_translation'] : array();
		}
		$setting = (int) ( self::$field_settings[ $key ] ?? self::FIELD_IGNORE );

		return in_array( $setting, array( self::FIELD_COPY, self::FIELD_TRANSLATE, self::FIELD_COPY_ONCE ), true ) ? $setting : self::FIELD_IGNORE;
	}
}
