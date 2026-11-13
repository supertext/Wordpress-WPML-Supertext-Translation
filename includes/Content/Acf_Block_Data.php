<?php
/**
 * @package Supertext_Wpml
 */

namespace Supertext\Wpml\Content;

defined( 'ABSPATH' ) || exit;

/**
 * The texts in an ACF block's `data` attribute, for sites without ACF Multilingual
 * (with ACFML, its own rules decide; see Block_Strings). Plain PHP, no WordPress.
 *
 * A block's data holds each field's value under the field name and the field's key
 * under `_<name>` (`"text": "<h1>…</h1>", "_text": "field_69a5…"`); repeaters and
 * groups nest arrays. A value is translated when it is a string with letters in it,
 * not a field reference (`_…` names, `field_…` values), not a number or a switch
 * (`"0"`, `"1"`), and not a single word that is a link, e-mail address, path, file
 * name or colour. Shortcodes stay inside the
 * text, so Supertext keeps them as they are.
 *
 * The id of a text is the one ACFML uses (md5 of block name and text), so a site that
 * adds ACFML later keeps the same contexts.
 *
 * @since 1.1.0
 */
final class Acf_Block_Data {
	/**
	 * The texts of a block's data.
	 *
	 * @param string               $block_name Block name, e.g. `acf/text`.
	 * @param array<string, mixed> $data       The block's `data` attribute.
	 * @return array<string, array{value: string, html: bool}> Id => text.
	 */
	public static function strings( string $block_name, array $data ): array {
		$out = array();
		self::each(
			$data,
			static function ( string $value ) use ( $block_name, &$out ): string {
				$out[ self::id( $block_name, $value ) ] = array(
					'value' => $value,
					'html'  => strip_tags( $value ) !== $value,
				);
				return $value;
			}
		);

		return $out;
	}

	/**
	 * The data with translated texts.
	 *
	 * @param string               $block_name   Block name.
	 * @param array<string, mixed> $data         The block's `data` attribute.
	 * @param array<string, string> $translations Id => translated text.
	 * @return array<string, mixed>
	 */
	public static function apply( string $block_name, array $data, array $translations ): array {
		return self::each(
			$data,
			static function ( string $value ) use ( $block_name, $translations ): string {
				return $translations[ self::id( $block_name, $value ) ] ?? $value;
			}
		);
	}

	/**
	 * The id of a text (ACFML's: md5 of block name and text).
	 *
	 * @param string $block_name Block name.
	 * @param string $value      Text.
	 * @return string
	 */
	public static function id( string $block_name, string $value ): string {
		return md5( $block_name . $value );
	}

	/**
	 * Whether a value is a text to translate.
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	public static function is_text( string $value ): bool {
		$plain = trim( self::plain( $value ) );
		if ( '' === $plain || is_numeric( $plain ) || ! preg_match( '/\p{L}/u', $plain ) ) {
			return false;
		}
		if ( str_starts_with( $value, 'field_' ) || preg_match( '/^#[0-9a-f]{3,8}$/i', $plain ) ) {
			return false;
		}
		// One word that is a link, an e-mail address, a path, a file name or a slug_like_this.
		if ( ! preg_match( '/\s/u', $plain ) && ( filter_var( $plain, FILTER_VALIDATE_URL ) || filter_var( $plain, FILTER_VALIDATE_EMAIL ) || preg_match( '#[/_]|\.[a-z0-9]{2,4}$#i', $plain ) ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Maps every text value (skipping `_…` names), nested arrays included.
	 *
	 * @param array<string|int, mixed> $data     Data.
	 * @param callable                 $callback Text => text.
	 * @return array<string|int, mixed>
	 */
	private static function each( array $data, callable $callback ): array {
		foreach ( $data as $name => $value ) {
			if ( is_string( $name ) && str_starts_with( $name, '_' ) ) {
				continue;
			}
			if ( is_array( $value ) ) {
				$data[ $name ] = self::each( $value, $callback );
			} elseif ( is_string( $value ) && self::is_text( $value ) ) {
				$data[ $name ] = $callback( $value );
			}
		}

		return $data;
	}

	/**
	 * Text without tags, script and style contents (like wp_strip_all_tags()).
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function plain( string $text ): string {
		return strip_tags( (string) preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $text ) );
	}
}

