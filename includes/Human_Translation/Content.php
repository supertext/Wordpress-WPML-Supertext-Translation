<?php
/**
 * @package Supertext_Wpml
 */

namespace Supertext\Wpml\Human_Translation;

defined( 'ABSPATH' ) || exit;

use Supertext\Wpml\Content\Collector;
use WP_Post;

/**
 * Builds the HTML document uploaded to Supertext for a human-translation order:
 * the same texts AI translation sends (Content\Collector), each in a
 * `<div data-st-ctx="…">` whose value is the base64-encoded context, so Writeback
 * can put the returned translation into the right field. Plain-text fields
 * (title, term names, custom fields without tags) are HTML-escaped and decoded again
 * on the way back.
 *
 * @since 0.1.0
 */
class Content {
	/** The attribute that carries the context. */
	const ATTRIBUTE = 'data-st-ctx';

	/**
	 * Collects the texts of a post (Content\Collector).
	 *
	 * @param WP_Post $post        Source post.
	 * @param string  $target_code WPML code of the target language.
	 * @return array<int, array{context: string, singular: string, html: bool}>
	 */
	public static function collect( WP_Post $post, string $target_code ): array {
		return Collector::collect( $post, $target_code );
	}

	/**
	 * Renders collected entries into the HTML document for upload.
	 *
	 * @param array<int, array{context: string, singular: string, html?: bool}> $entries Entries.
	 * @return string
	 */
	public static function render( array $entries ): string {
		$html = "<!DOCTYPE html>\n<html><head><meta charset=\"utf-8\"></head><body>\n";

		foreach ( $entries as $entry ) {
			$marker = base64_encode( $entry['context'] );
			$text   = ( $entry['html'] ?? true ) ? $entry['singular'] : esc_html( $entry['singular'] );
			$html  .= '<div ' . self::ATTRIBUTE . '="' . esc_attr( $marker ) . '">' . $text . "</div>\n";
		}

		$html .= '</body></html>';

		return $html;
	}
}
