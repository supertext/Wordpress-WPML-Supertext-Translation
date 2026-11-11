<?php
/**
 * @package Supertext_Wpml
 */

namespace Supertext\Wpml\Human_Translation;

defined( 'ABSPATH' ) || exit;

use DOMDocument;
use DOMXPath;
use WP_Post;
use Supertext\Wpml\Admin\Settings;
use Supertext\Wpml\Content\Writer;
use Supertext\Wpml\Wpml\Language;
use Supertext\Wpml\Wpml\Wpml;

/**
 * Writes a completed human-translation order back into the target post.
 *
 * Hooked on the callback's `supertext_wpml_order_completed` action: downloads
 * the Final file(s), parses them by `data-st-ctx` (= base64 context, see Content)
 * and saves through Content\Writer — the same path AI translation uses (creates or
 * updates the WPML translation).
 *
 * @since 0.1.0
 */
class Writeback {
	/**
	 * Registers the hook.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'supertext_wpml_order_completed', array( self::class, 'process' ), 10, 5 );
	}

	/**
	 * Processes a completed order callback.
	 *
	 * @param int[]  $order_ids Completed order id(s).
	 * @param int    $post_id   Source post id (from ReferenceData).
	 * @param string $lang      Target language slug (from ReferenceData).
	 * @param mixed  $body      Raw decoded callback body (the order object).
	 * @param string $type      Entity type; this writer only handles 'post'.
	 * @return void
	 */
	public static function process( $order_ids, $post_id, $lang, $body, $type = 'post' ): void {
		if ( 'post' !== $type ) {
			return;
		}
		$post   = get_post( (int) $post_id );
		$target = Wpml::language( (string) $lang );

		if ( ! $post instanceof WP_Post || null === $target ) {
			self::debug_log( sprintf( 'writeback aborted: post %d or language %s not found', (int) $post_id, $lang ) );
			return;
		}

		// Respect the "allow multiple write-backs" setting: if disabled and this
		// post/language was already written back, ignore subsequent callbacks.
		if ( ! Settings::allow_multiple_writebacks() && get_post_meta( (int) $post_id, '_supertext_order_completed_' . $lang, true ) ) {
			self::debug_log( sprintf( 'writeback skipped: post %d (%s) already written and multiple write-backs disabled', (int) $post_id, $lang ) );
			return;
		}

		$files = self::final_files( $body );
		if ( empty( $files ) ) {
			self::debug_log( 'writeback: no Final files in callback, nothing to do' );
			return;
		}

		// Download + parse the translated file(s) into a context => translation map.
		$client = new Client();
		$map    = array();
		foreach ( $files as $file ) {
			$html = $client->download_file( (int) $file['Id'], (string) $file['Name'] );
			if ( is_wp_error( $html ) ) {
				self::debug_log( sprintf( 'writeback: failed to download file %d: %s', (int) $file['Id'], $html->get_error_message() ) );
				continue;
			}
			$map += self::parse_html( $html );
		}

		if ( empty( $map ) ) {
			self::debug_log( 'writeback: no translatable strings parsed from the downloaded file(s)' );
			return;
		}

		// "Published" publishes the translation; "Draft" creates new translations as drafts
		// and leaves the status of an existing one alone.
		$saved  = self::save_map( $post, $target, $map, 'publish' === Settings::writeback_status() ? 'publish' : null );
		$filled = $saved['filled'];
		foreach ( $saved['errors'] as $error ) {
			self::debug_log( 'writeback save errors: ' . $error );
		}

		self::debug_log( sprintf( 'writeback done: post=%d lang=%s filled=%d status=%s', (int) $post_id, $lang, $filled, Settings::writeback_status() ) );

		// Update the order registry status (from the callback body if present).
		$status = is_array( $body ) && ! empty( $body['Status'] ) ? (string) $body['Status'] : 'Completed';
		foreach ( (array) $order_ids as $oid ) {
			Orders::update( (int) $oid, array( 'status' => $status, 'completed_at' => gmdate( 'c' ) ) );
		}

		update_post_meta(
			(int) $post_id,
			'_supertext_order_completed_' . $lang,
			wp_json_encode(
				array(
					'order_ids'   => array_map( 'intval', (array) $order_ids ),
					'file_ids'    => array_map( static fn( $f ) => (int) $f['Id'], $files ),
					'strings'     => $filled,
					'completed_at' => gmdate( 'c' ),
				)
			)
		);

		// Delivery is done: drop the open-order lock so this page + language can be
		// ordered again without a manual Reset on the Orders page. The
		// `_supertext_order_completed_<lang>` marker set above still guards against a
		// re-sent callback for THIS order overwriting the result.
		delete_post_meta( (int) $post_id, '_supertext_order_' . $lang );
	}

	/**
	 * Writes translations into a post's translation (Content\Writer: creates the WPML
	 * translation as a draft or updates it). Texts of plain-text contexts arrive
	 * HTML-escaped (see Content::render()) and are decoded. Used by human-translation
	 * orders and by translation file imports.
	 *
	 * @param WP_Post               $post    Source post.
	 * @param Language              $target  Target language.
	 * @param array<string, string> $map     Context => translated string.
	 * @param string|null           $status  'draft', 'publish' or null (new: draft, existing: unchanged).
	 * @param bool                  $escaped Whether plain-text contexts are HTML-escaped (order files).
	 * @return array{filled: int, errors: string[], post_id: int}
	 */
	public static function save_map( WP_Post $post, Language $target, array $map, ?string $status = null, bool $escaped = true ): array {
		if ( $escaped ) {
			foreach ( Content::collect( $post, $target->code ) as $entry ) {
				if ( ! $entry['html'] && isset( $map[ $entry['context'] ] ) ) {
					$map[ $entry['context'] ] = html_entity_decode( $map[ $entry['context'] ], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				}
			}
		}

		// WordPress's admin post functions aren't loaded during a REST callback.
		if ( ! function_exists( 'get_default_post_to_edit' ) ) {
			require_once ABSPATH . 'wp-admin/includes/post.php';
		}

		$saved = Writer::save( $post, $target, $map, $status );

		return array(
			'filled'  => $saved['filled'],
			'errors'  => $saved['errors'],
			'post_id' => $saved['post_id'],
		);
	}

	/**
	 * Returns the Final (translated) files from the callback body.
	 *
	 * @param mixed $body Decoded callback body.
	 * @return array[] List of { Id, Name } file descriptors.
	 */
	private static function final_files( $body ): array {
		$files = array();

		if ( ! is_array( $body ) || empty( $body['Files'] ) || ! is_array( $body['Files'] ) ) {
			return $files;
		}

		foreach ( $body['Files'] as $file ) {
			if ( ! is_array( $file ) || ! isset( $file['Id'] ) ) {
				continue;
			}
			if ( ( $file['DocumentType'] ?? '' ) !== 'Final' ) {
				continue;
			}
			$files[] = array(
				'Id'   => (int) $file['Id'],
				'Name' => (string) ( $file['Name'] ?? ( $file['Id'] . '.html' ) ),
			);
		}

		return $files;
	}

	/**
	 * Parses translated HTML into a map of translation context => translated string.
	 *
	 * @param string $html Translated HTML document.
	 * @return array<string, string>
	 */
	private static function parse_html( string $html ): array {
		$out = array();

		$dom      = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		$xpath = new DOMXPath( $dom );
		$nodes = $xpath->query( '//div[@' . Content::ATTRIBUTE . ']' );
		if ( false === $nodes ) {
			return $out;
		}

		foreach ( $nodes as $node ) {
			if ( ! $node instanceof \DOMElement ) {
				continue;
			}

			$context = base64_decode( $node->getAttribute( Content::ATTRIBUTE ), true );
			if ( false === $context ) {
				continue;
			}

			$inner = '';
			foreach ( $node->childNodes as $child ) {
				$inner .= (string) $dom->saveHTML( $child );
			}

			$out[ $context ] = trim( $inner );
		}

		return $out;
	}

	/**
	 * Logs a diagnostic line when WP_DEBUG is on.
	 *
	 * @param string $message The message.
	 * @return void
	 */
	private static function debug_log( string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[supertext-wpml][writeback] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
