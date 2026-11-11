<?php
/**
 * @package Supertext_Wpml
 */

namespace Supertext\Wpml\Human_Translation;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;
use WP_REST_Response;

/**
 * REST callback that Supertext calls when a human-translation order is done.
 *
 * The request body looks like the order object(s). We identify and authenticate
 * the callback via the `ReferenceData` field we set on the order — a signed
 * `{post_id}:{lang}:{hmac}` string (HMAC of post+lang with a site secret). Because
 * the secret travels in the signed token (not the URL) and comes back with the
 * callback, an attacker can't forge a valid ReferenceData, so the public endpoint
 * only acts on genuine callbacks.
 *
 * Fetching/writing back the translated files is the next step; for now this
 * authenticates, identifies the post + language, and extracts the order id(s).
 *
 * @since 0.1.0
 */
class Callback {
	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	const NS = 'supertext-wpml/v1';

	/**
	 * REST route.
	 *
	 * @var string
	 */
	const ROUTE = '/order-callback';

	/**
	 * Option storing the site secret used to sign ReferenceData.
	 *
	 * @var string
	 */
	const SECRET_OPTION = 'supertext_wpml_callback_token';

	/**
	 * Option storing the most recent raw callbacks (for inspection).
	 *
	 * @var string
	 */
	const LOG_OPTION = 'supertext_wpml_callback_log';

	/**
	 * How many recent callbacks to keep.
	 *
	 * @var int
	 */
	const LOG_MAX = 1;

	/**
	 * Registers the REST route.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'rest_api_init', array( self::class, 'register_route' ) );
	}

	/**
	 * Registers the callback route. It is public; authentication is done via the
	 * signed `ReferenceData` in the body.
	 *
	 * @return void
	 */
	public static function register_route(): void {
		register_rest_route(
			self::NS,
			self::ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'handle' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Returns the callback URL to send with orders.
	 *
	 * @return string
	 */
	public static function url(): string {
		return rest_url( self::NS . self::ROUTE );
	}

	/**
	 * Builds the signed `ReferenceData` value for a post order: `{post_id}:{lang}:{hmac}`.
	 *
	 * @param int    $post_id Source post ID.
	 * @param string $lang    Target language slug.
	 * @return string
	 */
	public static function reference_data( int $post_id, string $lang ): string {
		return $post_id . ':' . $lang . ':' . self::sign( $post_id, $lang );
	}

	/**
	 * Builds the signed `ReferenceData` for a non-post entity: `{type}:{id}:{lang}:{hmac}`.
	 *
	 * Used for orders that aren't WordPress posts (e.g. Gravity Forms forms), so the
	 * completion callback can route the result to the right writer.
	 *
	 * @param string $type Entity type (e.g. `gf`).
	 * @param int    $id   Entity id (e.g. form id).
	 * @param string $lang Target language slug.
	 * @return string
	 */
	public static function reference_data_for( string $type, int $id, string $lang ): string {
		return $type . ':' . $id . ':' . $lang . ':' . self::sign_typed( $type, $id, $lang );
	}

	/**
	 * Handles the callback: authenticates via ReferenceData, then extracts the
	 * order id(s) and identifies the target post + language.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function handle( WP_REST_Request $request ): WP_REST_Response {
		// Record the raw payload first, so we capture exactly what Supertext sends
		// even if it fails validation or has an unexpected shape.
		self::record( $request );

		$body    = $request->get_json_params();
		$context = self::parse_reference_data( self::extract_reference_data( $body ) );

		if ( null === $context ) {
			self::debug_log( 'callback rejected: invalid or missing ReferenceData' );
			return new WP_REST_Response( array( 'ok' => false, 'message' => 'Invalid or missing ReferenceData.' ), 403 );
		}

		$order_ids = self::extract_order_ids( $body );

		self::debug_log(
			sprintf( 'callback ok: type=%s id=%d lang=%s orders=%s', $context['type'], $context['id'], $context['lang'], wp_json_encode( $order_ids ) )
		);

		/**
		 * Fires when Supertext reports a completed human-translation order.
		 *
		 * Writers hook this, fetch the translated files for each order id, and write
		 * them back into the target entity. The `$type` tells each writer whether the
		 * order is theirs ('post' for WordPress posts, 'gf' for Gravity Forms forms).
		 *
		 * @since 0.1.0
		 *
		 * @param int[]  $order_ids The completed order id(s).
		 * @param int    $id        The source entity id (post id, or form id for 'gf').
		 * @param string $lang      The target language slug (from ReferenceData).
		 * @param mixed  $body      The raw decoded request body.
		 * @param string $type      The entity type ('post' or 'gf').
		 */
		do_action( 'supertext_wpml_order_completed', $order_ids, $context['id'], $context['lang'], $body, $context['type'] );

		return new WP_REST_Response(
			array(
				'ok'       => true,
				'type'     => $context['type'],
				'id'       => $context['id'],
				'lang'     => $context['lang'],
				'received' => $order_ids,
			),
			200
		);
	}

	/**
	 * Records the raw incoming callback for later inspection (keeps the last few).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return void
	 */
	private static function record( WP_REST_Request $request ): void {
		$entry = array(
			'time'         => gmdate( 'c' ),
			'method'       => $request->get_method(),
			'content_type' => (string) $request->get_header( 'content_type' ),
			'query'        => $request->get_query_params(),
			'body'         => mb_substr( (string) $request->get_body(), 0, 65535 ),
		);

		$log = self::get_log();
		array_unshift( $log, $entry );
		$log = array_slice( $log, 0, self::LOG_MAX );

		update_option( self::LOG_OPTION, $log, false );
	}

	/**
	 * Returns the recorded callbacks (most recent first).
	 *
	 * @return array[]
	 */
	public static function get_log(): array {
		$log = get_option( self::LOG_OPTION, array() );
		return is_array( $log ) ? $log : array();
	}

	/**
	 * Clears the recorded callbacks.
	 *
	 * @return void
	 */
	public static function clear_log(): void {
		delete_option( self::LOG_OPTION );
	}

	/**
	 * Returns the site secret, creating it on first use.
	 *
	 * @return string
	 */
	public static function secret(): string {
		$secret = get_option( self::SECRET_OPTION );
		if ( ! is_string( $secret ) || '' === $secret ) {
			$secret = wp_generate_password( 32, false );
			update_option( self::SECRET_OPTION, $secret, false );
		}
		return $secret;
	}

	/**
	 * Computes the HMAC for a post + language.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $lang    Language slug.
	 * @return string
	 */
	private static function sign( int $post_id, string $lang ): string {
		return hash_hmac( 'sha256', $post_id . ':' . $lang, self::secret() );
	}

	/**
	 * Computes the HMAC for a typed (non-post) entity.
	 *
	 * @param string $type Entity type.
	 * @param int    $id   Entity id.
	 * @param string $lang Language slug.
	 * @return string
	 */
	private static function sign_typed( string $type, int $id, string $lang ): string {
		return hash_hmac( 'sha256', $type . ':' . $id . ':' . $lang, self::secret() );
	}

	/**
	 * Validates a `ReferenceData` string and returns its type, id + language.
	 *
	 * Accepts the typed 4-part form `{type}:{id}:{lang}:{hmac}` (e.g. `gf:…`) and the
	 * legacy 3-part post form `{post_id}:{lang}:{hmac}` (implicit type `post`).
	 *
	 * @param string $reference The reference string.
	 * @return array{type: string, id: int, lang: string}|null Null if invalid.
	 */
	private static function parse_reference_data( string $reference ) {
		$parts = explode( ':', $reference );

		// Typed form: {type}:{id}:{lang}:{hmac}.
		if ( count( $parts ) === 4 ) {
			list( $type, $id, $lang, $token ) = $parts;
			$id = (int) $id;
			// id 0 is valid here: string-translation orders (type `str`) have no
			// backing entity, so they legitimately carry id 0. Only a negative id is
			// malformed. The HMAC below still authenticates the whole tuple.
			if ( '' === $type || $id < 0 || '' === $lang ) {
				return null;
			}
			if ( ! hash_equals( self::sign_typed( $type, $id, $lang ), (string) $token ) ) {
				return null;
			}
			return array(
				'type' => $type,
				'id'   => $id,
				'lang' => $lang,
			);
		}

		// Legacy post form: {post_id}:{lang}:{hmac}.
		if ( count( $parts ) === 3 ) {
			list( $post_id, $lang, $token ) = $parts;
			$post_id = (int) $post_id;
			if ( $post_id <= 0 || '' === $lang ) {
				return null;
			}
			if ( ! hash_equals( self::sign( $post_id, $lang ), (string) $token ) ) {
				return null;
			}
			return array(
				'type' => 'post',
				'id'   => $post_id,
				'lang' => $lang,
			);
		}

		return null;
	}

	/**
	 * Extracts the `ReferenceData` value from the callback body (single order
	 * object or an array of them).
	 *
	 * @param mixed $body Decoded JSON body.
	 * @return string
	 */
	private static function extract_reference_data( $body ): string {
		if ( ! is_array( $body ) ) {
			return '';
		}

		if ( isset( $body['ReferenceData'] ) && is_string( $body['ReferenceData'] ) ) {
			return $body['ReferenceData'];
		}

		foreach ( $body as $entry ) {
			if ( is_array( $entry ) && isset( $entry['ReferenceData'] ) && is_string( $entry['ReferenceData'] ) ) {
				return $entry['ReferenceData'];
			}
		}

		return '';
	}

	/**
	 * Extracts order id(s) from the callback body.
	 *
	 * @param mixed $body Decoded JSON body.
	 * @return int[]
	 */
	private static function extract_order_ids( $body ): array {
		$ids = array();

		if ( ! is_array( $body ) ) {
			return $ids;
		}

		if ( isset( $body['Id'] ) ) {
			$ids[] = (int) $body['Id'];
		} else {
			foreach ( $body as $entry ) {
				if ( is_array( $entry ) && isset( $entry['Id'] ) ) {
					$ids[] = (int) $entry['Id'];
				}
			}
		}

		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/**
	 * Logs a diagnostic line when WP_DEBUG is on.
	 *
	 * @param string $message The message.
	 * @return void
	 */
	private static function debug_log( string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[supertext-wpml][callback] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
