<?php
/**
 * @package Supertext_Wpml
 */

namespace Supertext\Wpml\Integrations\YooTheme;

defined( 'ABSPATH' ) || exit;

/**
 * Reads, walks and rewrites a YOOtheme Pro page-builder layout.
 *
 * YOOtheme stores the layout as JSON inside an HTML comment in `post_content`:
 * `<!-- {"type":"layout","children":[...],"version":"5.0.24"} -->`. This class
 * extracts that JSON, walks the node tree to collect/replace the translatable
 * text props, and writes the JSON back into the comment.
 *
 * Only **string-valued** props whose key is in the translatable set are touched —
 * containers, selects, CSS, ids, urls, etc. are left byte-for-byte intact, which
 * is what keeps the JSON structure valid.
 *
 * @since 0.1.0
 */
class Layout {
	/**
	 * Field type used in the export ref / translation context.
	 *
	 * @var string
	 */
	const FIELD_TYPE = 'yootheme';

	/**
	 * Matches the JSON payload inside YOOtheme's content comment.
	 *
	 * @var string
	 */
	const PATTERN = '/<!--\s?(\{.*\})\s?-->/s';

	/**
	 * Returns the translatable prop keys (string values only).
	 *
	 * @return string[]
	 */
	public static function translatable_keys(): array {
		/**
		 * Filters the YOOtheme layout prop keys whose string values are translated.
		 *
		 * Only props with one of these keys *and* a non-empty string value are sent
		 * for translation; everything else (structure, CSS, ids, URLs, selects…) is
		 * left untouched. Add a key here to translate an extra text prop.
		 *
		 * Example:
		 *     add_filter( 'supertext_wpml_yootheme_fields', function ( array $keys ) {
		 *         $keys[] = 'subtitle';
		 *         return $keys;
		 *     } );
		 *
		 * @since 0.1.0
		 *
		 * @param string[] $keys Default translatable prop keys.
		 */
		$keys = apply_filters(
			'supertext_wpml_yootheme_fields',
			array( 'content', 'title', 'meta', 'alt', 'image_alt', 'link_text' )
		);

		return array_map( 'strval', $keys );
	}

	/**
	 * Returns element types whose `content` prop must NOT be translated (e.g. raw code).
	 *
	 * @return string[]
	 */
	private static function content_skip_types(): array {
		/**
		 * Filters the YOOtheme element types whose `content` prop must NOT be translated.
		 *
		 * Some elements store non-prose in `content` (e.g. the `code` element holds raw
		 * code). Their `content` is skipped so it isn't mangled by the translator.
		 *
		 * @since 0.1.0
		 *
		 * @param string[] $types Element type names to skip for the `content` prop.
		 */
		$types = apply_filters( 'supertext_wpml_yootheme_skip_content_types', array( 'code' ) );

		return array_map( 'strval', $types );
	}

	/**
	 * Tells whether the given post content holds a YOOtheme layout.
	 *
	 * @param string $content Post content.
	 * @return bool
	 */
	public static function is_layout( string $content ): bool {
		return null !== self::decode( $content );
	}

	/**
	 * Decodes the layout JSON from the post content.
	 *
	 * @param string $content Post content.
	 * @return array|null The decoded layout, or null if not a YOOtheme layout.
	 */
	public static function decode( string $content ) {
		if ( ! preg_match( self::PATTERN, $content, $m ) ) {
			return null;
		}

		$data = json_decode( $m[1], true );
		if ( ! is_array( $data ) ) {
			return null;
		}

		// Light sanity check that this looks like a YOOtheme layout.
		if ( ! isset( $data['version'] ) && ! isset( $data['children'] ) && ! isset( $data['type'] ) ) {
			return null;
		}

		return $data;
	}

	/**
	 * Writes the (modified) layout back into the post content comment.
	 *
	 * @param string $content Original post content (used as the template).
	 * @param array  $data    Modified layout data.
	 * @return string
	 */
	public static function encode( string $content, array $data ): string {
		$json = wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $json ) {
			return $content;
		}

		return (string) preg_replace( self::PATTERN, '<!-- ' . $json . ' -->', $content, 1 );
	}

	/**
	 * Collects translatable strings keyed by node path.
	 *
	 * @param array $data Layout data.
	 * @return array<string, string> Map of path => source string.
	 */
	public static function collect( array $data ): array {
		$found = array();
		self::walk(
			$data,
			'',
			null,
			function ( string $path, string $value ) use ( &$found ) {
				$found[ $path ] = $value;
				return $value;
			}
		);

		return $found;
	}

	/**
	 * Returns a copy of the layout with each translatable string replaced by the
	 * callback's return value.
	 *
	 * @param array    $data Layout data.
	 * @param callable $cb   `fn( string $path, string $value ): string`.
	 * @return array
	 */
	public static function map( array $data, callable $cb ): array {
		return self::walk( $data, '', null, $cb );
	}

	/**
	 * Recursively walks the layout, invoking `$cb` on each translatable string prop.
	 *
	 * @param mixed         $value       Current node/value.
	 * @param string        $path        Current path.
	 * @param string|null   $parent_type Nearest enclosing element type.
	 * @param callable      $cb          `fn( string $path, string $value ): string`.
	 * @return mixed
	 */
	private static function walk( $value, string $path, $parent_type, callable $cb ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		$type = isset( $value['type'] ) && is_string( $value['type'] ) ? $value['type'] : $parent_type;

		foreach ( $value as $key => $item ) {
			$child_path = '' === $path ? (string) $key : $path . '/' . $key;

			if ( is_array( $item ) ) {
				// Propagate the element type into its props/children.
				$next_type        = ( 'props' === $key || 'children' === $key ) ? $type : $parent_type;
				$value[ $key ]    = self::walk( $item, $child_path, $next_type, $cb );
			} elseif ( is_string( $item ) && '' !== $item && self::is_translatable( (string) $key, $type ) ) {
				$value[ $key ] = (string) call_user_func( $cb, $child_path, $item );
			}
		}

		return $value;
	}

	/**
	 * Tells whether a prop key (within an element of the given type) is translatable.
	 *
	 * @param string      $key  Prop key.
	 * @param string|null $type Enclosing element type.
	 * @return bool
	 */
	private static function is_translatable( string $key, $type ): bool {
		if ( ! in_array( $key, self::translatable_keys(), true ) ) {
			return false;
		}

		if ( 'content' === $key && is_string( $type ) && in_array( $type, self::content_skip_types(), true ) ) {
			return false;
		}

		return true;
	}
}
