<?php
/**
 * @package Supertext_Wpml
 */

namespace Supertext\Wpml\Content;

defined( 'ABSPATH' ) || exit;

use Supertext\Wpml\Admin\Settings;
use Supertext\Wpml\Wpml\Wpml;
use WP_Post;

/**
 * What Supertext does to links and images when it writes a translation (Writer::save):
 * links to the site's own posts and pages point to their translation (Links), the
 * translation's own images stay (Images, record in the post meta `_supertext_images`).
 *
 * @since 1.5.0
 */
final class Post_Links {
	/** Post meta of a translation: the image record of the last translation (Images). */
	public const META = '_supertext_images';

	/**
	 * Localizes the links of new translated content and merges its images with the
	 * translation's current ones.
	 *
	 * @param string       $content     The new translated content (images and links of the source).
	 * @param WP_Post|null $current     The existing translation, null for a new one.
	 * @param string       $source_code WPML code of the source language.
	 * @param string       $target_code WPML code of the target language.
	 * @return array{content: string, records: list<array<string, mixed>>}
	 */
	public static function apply( string $content, ?WP_Post $current, string $source_code, string $target_code ): array {
		if ( Settings::translate_links() ) {
			$content = Links::localize( $content, self::resolver( $source_code, $target_code ) );
		}
		if ( null === $current ) {
			return array(
				'content' => $content,
				'records' => Images::record( $content ),
			);
		}
		$written = get_post_meta( $current->ID, self::META, true );
		$merged  = Images::merge( $content, (string) $current->post_content, is_array( $written ) ? array_values( $written ) : null );

		return array(
			'content' => $merged['html'],
			'records' => $merged['records'],
		);
	}

	/**
	 * Stores the image record of a translation.
	 *
	 * @param int                         $post_id Translation.
	 * @param list<array<string, mixed>>  $records Records.
	 * @return void
	 */
	public static function save_records( int $post_id, array $records ): void {
		if ( array() === $records ) {
			delete_post_meta( $post_id, self::META );
			return;
		}
		update_post_meta( $post_id, self::META, wp_slash( $records ) );
	}

	/**
	 * How many images of a translation are marked to check.
	 *
	 * @param int $post_id Translation.
	 * @return int
	 */
	public static function images_to_check( int $post_id ): int {
		return Images::to_check( get_post_meta( $post_id, self::META, true ) );
	}

	/**
	 * The address of the same page in the target language, for Links::localize(): the
	 * permalink of the post's translation, the target language's home page for the home page,
	 * null for other websites and for posts without a translation into the target language.
	 *
	 * @param string $source_code WPML code of the source language.
	 * @param string $target_code WPML code of the target language.
	 * @return callable(string): ?string
	 */
	public static function resolver( string $source_code, string $target_code ): callable {
		$cache = array();

		return static function ( string $href ) use ( $source_code, $target_code, &$cache ): ?string {
			if ( array_key_exists( $href, $cache ) ) {
				return $cache[ $href ];
			}
			$url = self::absolute( $href );
			if ( null === $url ) {
				$cache[ $href ] = null;
				return null;
			}
			$found = Wpml::in_language(
				$source_code,
				static function () use ( $url ): array {
					$home = untrailingslashit( (string) home_url( '/' ) );
					if ( untrailingslashit( $url ) === $home ) {
						return array( 'home', 0 );
					}
					return array( 'post', (int) url_to_postid( $url ) );
				}
			);
			$result = null;
			if ( 'home' === $found[0] ) {
				$result = Wpml::in_language( $target_code, static fn(): string => (string) home_url( '/' ) );
			} elseif ( $found[1] > 0 && Wpml::post_language( $found[1] ) === $source_code ) {
				$translation = Wpml::post_translation( $found[1], $target_code );
				if ( $translation > 0 ) {
					$link   = Wpml::in_language( $target_code, static fn() => get_permalink( $translation ) );
					$result = is_string( $link ) && '' !== $link ? $link : null;
				}
			}
			$cache[ $href ] = $result;

			return $result;
		};
	}

	/**
	 * A link's absolute address on this site, null for other websites.
	 *
	 * @param string $href Link without query and anchor.
	 * @return string|null
	 */
	private static function absolute( string $href ): ?string {
		$home = wp_parse_url( (string) home_url( '/' ) );
		if ( ! is_array( $home ) || empty( $home['host'] ) ) {
			return null;
		}
		$origin = ( $home['scheme'] ?? 'https' ) . '://' . $home['host'] . ( isset( $home['port'] ) ? ':' . $home['port'] : '' );
		if ( str_starts_with( $href, '//' ) ) {
			$href = ( $home['scheme'] ?? 'https' ) . ':' . $href;
		} elseif ( str_starts_with( $href, '/' ) ) {
			return $origin . $href;
		}
		$parts = wp_parse_url( $href );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return null;
		}
		$host = strtolower( (string) $parts['host'] );
		$own  = strtolower( (string) $home['host'] );
		if ( $host !== $own && 'www.' . $host !== $own && $host !== 'www.' . $own ) {
			return null;
		}
		if ( ( $parts['port'] ?? null ) !== ( $home['port'] ?? null ) ) {
			return null;
		}

		return $origin . ( $parts['path'] ?? '/' );
	}
}
