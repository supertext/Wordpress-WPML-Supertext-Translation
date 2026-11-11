<?php
/**
 * @package Supertext_Wpml
 */

namespace Supertext\Wpml\Machine_Translation;

defined( 'ABSPATH' ) || exit;

use Supertext\Wpml\Sdk\V1\Languages;
use Supertext\Wpml\Sdk\V1\Politeness;
use Supertext\Wpml\Sdk\V1\SupertextException;

/**
 * WPML languages → Supertext language codes.
 *
 * Supertext accepts only the codes of its `GET features` list (the SDK's
 * `Languages`): the source as a bare language (`en`), the target as listed
 * (`de-CH`, `en-US`, `zh-Hans`, but `es`, `nl`). A WPML language is
 * resolved by its locale (`de_CH`, `de_DE_formal`): the administrator's
 * mapping (the drop-down under Supertext → Language mapping) wins, else an
 * exact match of the locale (case and `_`/`-` ignored: `de_CH` → `de-CH`),
 * else the SDK's default (`de` → `de-DE`, `es_ES` → `es`, `zh_TW` → `zh-Hant`),
 * else, for WordPress's formal/informal locale variants (`de_DE_formal`,
 * `de_CH_informal`, `nl_NL_formal`), the same for the locale without the
 * variant (`de_DE` → `de-DE`), else the SDK error `unsupported_language` and
 * nothing is sent. Nothing else is guessed (`ca_valencia`, `pt_PT_ao90`,
 * `en_IE`, `gsw` need a mapping). One mapping serves both directions (a
 * mapped `de-CH` is sent as `de` when it is the source).
 *
 * Has no WordPress dependency (unit-tested on its own).
 */
class Language_Codes {
	/**
	 * Target code to send.
	 *
	 * @param string                $locale  The WPML language's locale (`de_CH`, `de_DE_formal`).
	 * @param array<string, string> $mapping Locale → Supertext code (the settings' language mapping).
	 * @return string
	 * @throws SupertextException `unsupported_language` (with targetLang) when the locale has neither a mapping nor a match nor a default.
	 */
	public static function target( string $locale, array $mapping = array() ): string {
		try {
			return Languages::target( $locale, self::clean( $mapping ) );
		} catch ( SupertextException $e ) {
			$suggestion = self::suggest( $locale );
			if ( null === $suggestion ) {
				throw $e;
			}
			return $suggestion;
		}
	}

	/**
	 * What the mapping drop-down preselects for a locale (and what is sent while
	 * nobody has chosen one): the SDK's suggestion (exact match, else default),
	 * else the SDK's suggestion for the locale without its WordPress
	 * formal/informal variant (`de_DE_formal` → `de-DE`), else null ("— choose —").
	 *
	 * @param string $locale The WordPress locale.
	 * @return string|null
	 */
	public static function suggest( string $locale ): ?string {
		$suggestion = Languages::suggestTarget( $locale );
		if ( null !== $suggestion ) {
			return $suggestion;
		}
		$base = self::without_variant( $locale );

		return null !== $base ? Languages::suggestTarget( $base ) : null;
	}

	/**
	 * The formality a locale implies: `more` for `*_formal`, `less` for `*_informal`, else ''.
	 *
	 * @param string $locale The WordPress locale.
	 * @return string
	 */
	public static function locale_politeness( string $locale ): string {
		if ( preg_match( '/_formal$/i', $locale ) ) {
			return Politeness::MORE;
		}
		if ( preg_match( '/_informal$/i', $locale ) ) {
			return Politeness::LESS;
		}

		return '';
	}

	/**
	 * Whether a code is one of Supertext's targets; returns it in Supertext's spelling (`de_ch` → `de-CH`), else null.
	 *
	 * @param string $code The code.
	 * @return string|null
	 */
	public static function known_target( string $code ): ?string {
		return Languages::matchTarget( $code );
	}

	/**
	 * The locale without WordPress's formal/informal variant (`de_DE_formal` → `de_DE`), or null when it has none.
	 *
	 * @param string $locale The WordPress locale.
	 * @return string|null
	 */
	private static function without_variant( string $locale ): ?string {
		if ( preg_match( '/^(.+)_(?:formal|informal)$/i', trim( $locale ), $m ) ) {
			return $m[1];
		}

		return null;
	}

	/**
	 * Source code to send (bare: `de_CH` → `de`); '' stays '' (auto-detect).
	 *
	 * @param string                $locale  The WPML language's locale.
	 * @param array<string, string> $mapping Locale → Supertext code.
	 * @return string
	 * @throws SupertextException `unsupported_language` (with sourceLang).
	 */
	public static function source( string $locale, array $mapping = array() ): string {
		return Languages::source( $locale, self::clean( $mapping ) );
	}

	/**
	 * The target code a locale resolves to, or null when it would fail with `unsupported_language`.
	 *
	 * @param string                $locale  The locale.
	 * @param array<string, string> $mapping Locale → Supertext code.
	 * @return string|null
	 */
	public static function resolve( string $locale, array $mapping = array() ): ?string {
		try {
			return self::target( $locale, $mapping );
		} catch ( SupertextException $e ) {
			return null;
		}
	}

	/**
	 * Whether the formal/informal choice is offered for a target (only where Supertext supports it).
	 *
	 * @param string $target Supertext target code.
	 * @return bool
	 */
	public static function supports_politeness( string $target ): bool {
		return true === Languages::supportsPoliteness( $target );
	}

	/**
	 * The politeness to send: the language's setting (`more`/`less`), else what the
	 * WordPress locale says (`*_formal` → `more`, `*_informal` → `less`), else `default`.
	 * Always `default` for a target without formal/informal (sending it would fail with
	 * `unsupported_feature`).
	 *
	 * @param string $target  Supertext target code.
	 * @param string $setting The language's politeness setting: `more`, `less` or '' (automatic).
	 * @param string $locale  The WordPress locale.
	 * @return string `default`, `more` or `less`.
	 */
	public static function politeness( string $target, string $setting, string $locale ): string {
		if ( ! self::supports_politeness( $target ) ) {
			return Politeness::DEFAULT;
		}
		if ( in_array( $setting, array( Politeness::MORE, Politeness::LESS ), true ) ) {
			return $setting;
		}
		$implied = self::locale_politeness( $locale );

		return '' !== $implied ? $implied : Politeness::DEFAULT;
	}

	/**
	 * The targets for the mapping drop-down, code => "Name (code)", sorted by name.
	 * The name is in the given interface locale where PHP's intl extension knows it
	 * (`de-CH` → "Deutsch (Schweiz)"), else Supertext's English name.
	 *
	 * @param string $ui_locale The interface locale (`de_DE`), '' for English.
	 * @return array<string, string>
	 */
	public static function targets( string $ui_locale = '' ): array {
		$out = array();
		foreach ( Languages::targetOptions() as $target ) {
			$code         = (string) $target['code'];
			$out[ $code ] = self::display_name( $code, (string) $target['name'], $ui_locale ) . ' (' . $code . ')';
		}
		if ( class_exists( \Collator::class ) && '' !== $ui_locale ) {
			$collator = new \Collator( $ui_locale );
			uasort( $out, static fn ( string $a, string $b ): int => (int) $collator->compare( $a, $b ) );
		} else {
			uasort( $out, 'strcasecmp' );
		}

		return $out;
	}

	/**
	 * A target's name in the interface language, else its English name.
	 *
	 * @param string $code      Supertext code.
	 * @param string $english   Supertext's English name.
	 * @param string $ui_locale Interface locale, '' for English.
	 * @return string
	 */
	private static function display_name( string $code, string $english, string $ui_locale ): string {
		// Swiss German's dialect extension (-u-sd-chzh) has no readable display name.
		if ( '' === $ui_locale || str_starts_with( strtolower( $ui_locale ), 'en' ) || str_contains( $code, '-u-' ) || ! class_exists( \Locale::class ) ) {
			return $english;
		}
		$name = \Locale::getDisplayName( $code, $ui_locale );
		if ( ! is_string( $name ) || 0 === strcasecmp( $name, $code ) ) {
			return $english;
		}

		return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( mb_substr( $name, 0, 1 ) ) . mb_substr( $name, 1 ) : ucfirst( $name );
	}

	/**
	 * Drops blank mapping values (a blank select means "— choose —": the suggestion, if any).
	 *
	 * @param array<mixed> $mapping Raw mapping.
	 * @return array<string, string>
	 */
	private static function clean( array $mapping ): array {
		$out = array();
		foreach ( $mapping as $key => $value ) {
			if ( is_string( $value ) && '' !== trim( $value ) ) {
				$out[ (string) $key ] = trim( $value );
			}
		}

		return $out;
	}
}
