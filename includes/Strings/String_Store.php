<?php
/**
 * @package Supertext_Wpml
 */

namespace Supertext\Wpml\Strings;

defined( 'ABSPATH' ) || exit;

use Supertext\Wpml\Wpml\Wpml;

/**
 * The strings of WPML String Translation that live in **string packages**: one package
 * per form, field group or similar, registered by WPML's add-ons (WPForms Multilingual,
 * Gravity Forms Multilingual, Contact Form 7 Multilingual, ACF Multilingual, …) and by
 * other plugins. Packages that belong to a post (page builder content) are left out:
 * they are translated with their post.
 *
 * Only WPML's public hooks:
 * - `wpml_pt_all_packages`: every package (WPML_Package objects; their
 *   `get_package_strings()` and `get_package_language()`);
 * - `wpml_get_translated_strings`: a package's translations (admin only);
 * - `wpml_add_string_translation`: saves one translation, status complete.
 *
 * Domain strings (theme and plugin texts, widgets, admin texts) are not packages and
 * stay in WPML → String Translation.
 *
 * @since 1.2.0
 */
final class String_Store {
	/** WPML's "translation complete" status (ICL_TM_COMPLETE). */
	private const COMPLETE = 10;

	/**
	 * Whether WPML String Translation is active.
	 *
	 * @return bool
	 */
	public static function available(): bool {
		return defined( 'WPML_ST_VERSION' ) && Wpml::is_active();
	}

	/**
	 * Every string of every package (not owned by a post).
	 *
	 * @return array<int, array{id: int, name: string, group: string, source: string, lang: string, html: bool, package: object}> String id => row.
	 */
	public static function rows(): array {
		$rows     = array();
		$packages = self::packages();
		$labels   = array();
		foreach ( $packages as $package ) {
			$labels[] = self::label( $package );
		}
		$counts = array_count_values( $labels );
		foreach ( $packages as $i => $package ) {
			$lang  = (string) ( method_exists( $package, 'get_package_language' ) ? $package->get_package_language() : '' );
			$lang  = '' !== $lang ? $lang : Wpml::default_code();
			$group = $labels[ $i ];
			if ( $counts[ $group ] > 1 ) {
				// Two forms with the same title: tell them apart by the package name (e.g. the form id).
				$group .= ' (' . (string) ( $package->name ?? '' ) . ')';
			}
			foreach ( (array) $package->get_package_strings() as $string ) {
				$value = (string) ( $string->value ?? '' );
				if ( '' === trim( $value ) ) {
					continue;
				}
				$id          = (int) ( $string->id ?? 0 );
				$rows[ $id ] = array(
					'id'      => $id,
					'name'    => (string) ( $string->title ?? '' ) !== '' ? (string) $string->title : (string) ( $string->name ?? '' ),
					'group'   => $group,
					'source'  => $value,
					'lang'    => $lang,
					'html'    => 'VISUAL' === ( $string->type ?? '' ) || 'AREA' === ( $string->type ?? '' ),
					'package' => $package,
				);
			}
		}

		return $rows;
	}

	/**
	 * Translations of the given rows.
	 *
	 * @param array<int, array{package: object}> $rows Rows (from rows()).
	 * @return array<int, array<string, string>> String id => [ language => translation ].
	 */
	public static function translations( array $rows ): array {
		$out  = array();
		$done = array();
		foreach ( $rows as $row ) {
			$package = $row['package'];
			$key     = spl_object_id( $package );
			if ( isset( $done[ $key ] ) ) {
				continue;
			}
			$done[ $key ] = true;

			$by_name = array();
			foreach ( (array) $package->get_package_strings() as $string ) {
				$by_name[ (string) ( $string->name ?? '' ) ] = (int) ( $string->id ?? 0 );
			}
			$translated = apply_filters( 'wpml_get_translated_strings', array(), $package );
			foreach ( is_array( $translated ) ? $translated : array() as $name => $languages ) {
				$id = $by_name[ (string) $name ] ?? 0;
				if ( ! $id || ! is_array( $languages ) ) {
					continue;
				}
				foreach ( $languages as $lang => $data ) {
					$value = is_array( $data ) ? (string) ( $data['value'] ?? '' ) : '';
					if ( '' !== $value ) {
						$out[ $id ][ (string) $lang ] = $value;
					}
				}
			}
		}

		return $out;
	}

	/**
	 * Saves translations (empty values are skipped, never deleted).
	 *
	 * @param string             $lang  WPML language code.
	 * @param array<int, string> $pairs String id => translation.
	 * @return int How many were saved.
	 */
	public static function save( string $lang, array $pairs ): int {
		$saved = 0;
		foreach ( $pairs as $id => $value ) {
			$value = (string) $value;
			if ( (int) $id <= 0 || '' === trim( $value ) ) {
				continue;
			}
			/**
			 * WPML String Translation: saves a string translation.
			 *
			 * @param int    $string_id String id.
			 * @param string $language  Language code.
			 * @param string $value     Translation.
			 * @param int    $status    10 = complete.
			 */
			do_action( 'wpml_add_string_translation', (int) $id, $lang, $value, self::COMPLETE );
			++$saved;
		}

		return $saved;
	}

	/**
	 * A package's group label: *Kind: Title*.
	 *
	 * @param object $package WPML package.
	 * @return string
	 */
	private static function label( object $package ): string {
		return trim( (string) ( $package->kind ?? '' ) . ': ' . (string) ( $package->title ?? $package->name ?? '' ), ': ' );
	}

	/**
	 * The packages listed on the page: every package not owned by a post.
	 *
	 * @return object[]
	 */
	private static function packages(): array {
		if ( ! self::available() ) {
			return array();
		}
		$packages = apply_filters( 'wpml_pt_all_packages', array() );
		$out      = array();
		foreach ( is_array( $packages ) ? $packages : array() as $package ) {
			if ( ! is_object( $package ) || ! method_exists( $package, 'get_package_strings' ) || ! empty( $package->post_id ) ) {
				continue;
			}
			$out[] = $package;
		}
		usort(
			$out,
			static fn( $a, $b ): int => strcasecmp( (string) ( $a->kind ?? '' ) . (string) ( $a->title ?? '' ), (string) ( $b->kind ?? '' ) . (string) ( $b->title ?? '' ) )
		);

		/**
		 * Filters the string packages shown under Supertext → String translations.
		 *
		 * @param object[] $out WPML_Package objects.
		 */
		return (array) apply_filters( 'supertext_wpml_string_packages', $out );
	}
}
