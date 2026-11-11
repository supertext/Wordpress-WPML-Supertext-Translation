<?php
/**
 * @package Supertext_Wpml
 */

namespace Supertext\Wpml\Content;

defined( 'ABSPATH' ) || exit;

/**
 * Splits post content into the parts that are translated and the parts that are
 * kept as they are, and puts it back together. No WordPress dependency
 * (tests/unit/cases/ContentSplitterTest.php).
 *
 * HTML comments — the block editor's `<!-- wp:… -->` delimiters with their JSON
 * attributes, YOOtheme's layout comment, `<!--more-->` — are never translated, and
 * neither is a part without visible text (only tags such as `<hr />` or an image)
 * or the white space around a part. Everything else, typically one block's HTML, is
 * one text: the block editor keeps one paragraph, heading or list per block, so a
 * sentence is never split at its formatting.
 *
 * @since 0.1.0
 */
class Content_Splitter {
	/** A part that is translated. */
	const TEXT = 'text';

	/** A part that is kept as it is. */
	const RAW = 'raw';

	/**
	 * Splits content into parts.
	 *
	 * @param string $content Post content.
	 * @return array<int, array{0: string, 1: string}> List of [ TEXT|RAW, value ]; their values joined are the content.
	 */
	public static function split( string $content ): array {
		$parts = array();
		$chunks = preg_split( '/(<!--.*?-->)/s', $content, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );
		foreach ( false === $chunks ? array( $content ) : $chunks as $chunk ) {
			if ( str_starts_with( $chunk, '<!--' ) && str_ends_with( $chunk, '-->' ) ) {
				$parts[] = array( self::RAW, $chunk );
				continue;
			}
			if ( ! self::has_text( $chunk ) ) {
				$parts[] = array( self::RAW, $chunk );
				continue;
			}
			// Keep the white space around the text out of it.
			preg_match( '/^(\s*)(.*?)(\s*)$/s', $chunk, $m );
			if ( '' !== $m[1] ) {
				$parts[] = array( self::RAW, $m[1] );
			}
			$parts[] = array( self::TEXT, $m[2] );
			if ( '' !== $m[3] ) {
				$parts[] = array( self::RAW, $m[3] );
			}
		}

		return $parts;
	}

	/**
	 * The texts to translate: part index => text.
	 *
	 * @param string $content Post content.
	 * @return array<int, string>
	 */
	public static function texts( string $content ): array {
		$out = array();
		foreach ( self::split( $content ) as $i => $part ) {
			if ( self::TEXT === $part[0] ) {
				$out[ $i ] = $part[1];
			}
		}

		return $out;
	}

	/**
	 * Puts the content together with translations (part index => text); a text
	 * without a translation stays as it is.
	 *
	 * @param string             $content      The source content.
	 * @param array<int, string> $translations Part index => translated text.
	 * @return string
	 */
	public static function join( string $content, array $translations ): string {
		$out = '';
		foreach ( self::split( $content ) as $i => $part ) {
			$out .= self::TEXT === $part[0] && isset( $translations[ $i ] ) ? $translations[ $i ] : $part[1];
		}

		return $out;
	}

	/**
	 * Whether a chunk has visible text (anything but tags, shortcodes, white space and `&nbsp;`).
	 *
	 * @param string $chunk HTML.
	 * @return bool
	 */
	public static function has_text( string $chunk ): bool {
		$text = (string) preg_replace( '/<[^>]*>/', '', $chunk );
		// A chunk with nothing but shortcodes ([gallery ids="1,2"]) is kept as it is.
		$text = (string) preg_replace( '/\[\/?[a-zA-Z][\w-]*(?:\s[^\]]*)?\]/', '', $text );
		$text = str_replace( array( '&nbsp;', '&#160;', "\xC2\xA0" ), ' ', $text );

		return '' !== trim( $text );
	}
}
