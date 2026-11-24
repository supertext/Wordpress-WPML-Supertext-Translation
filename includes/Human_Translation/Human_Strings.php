<?php
/**
 * @package Supertext_Wpml
 */

namespace Supertext\Wpml\Human_Translation;

defined( 'ABSPATH' ) || exit;

use Supertext\Wpml\Admin\Settings;
use Supertext\Wpml\Strings\String_Store;
use Supertext\Wpml\Wpml\Language;
use Supertext\Wpml\Wpml\Wpml;
use WP_Error;

/**
 * Professional (human) translation orders for WPML strings (Supertext → String
 * translations): the checked strings go to Supertext as one HTML file per source
 * language, each string in its own `<div data-st-ctx="base64(string:<id>)">` (same file
 * format as post orders, see Content), with ReferenceData `str:0:<target>` so the
 * callback routes the result here. The returned translations are saved with
 * String_Store, by string id.
 *
 * @since 1.2.0
 */
final class Human_Strings {
	/** AJAX action for the live price quote. */
	public const QUOTE_ACTION = 'supertext_wpml_str_quote';

	/** Context prefix of a string in the order file. */
	public const PREFIX = 'string:';

	/** ReferenceData entity type. */
	public const TYPE = 'str';

	/**
	 * Registers the writeback and the price-quote endpoint.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'supertext_wpml_order_completed', array( self::class, 'writeback' ), 10, 5 );
		add_action( 'wp_ajax_' . self::QUOTE_ACTION, array( self::class, 'handle_quote' ) );
	}

	/**
	 * AJAX: price quote (word count, delivery options and prices) for the checked strings.
	 *
	 * @return void
	 */
	public static function handle_quote(): void {
		check_ajax_referer( self::QUOTE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'supertext-wpml' ) ), 403 );
		}
		if ( ! Settings::is_configured() ) {
			wp_send_json_error( array( 'message' => __( 'Enter your Supertext account email and Order API Key on the Settings page first.', 'supertext-wpml' ) ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce checked above.
		$target     = sanitize_text_field( wp_unslash( $_POST['target_lang'] ?? '' ) );
		$service_id = (int) ( $_POST['service_id'] ?? 0 );
		$ids        = ( isset( $_POST['sources'] ) && is_array( $_POST['sources'] ) ) ? array_map( 'intval', (array) $_POST['sources'] ) : array();
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( '' === $target || ! Services::exists( $service_id ) || array() === array_filter( $ids ) ) {
			wp_send_json_error( array( 'message' => __( 'Select a translation type, a target language, and at least one row.', 'supertext-wpml' ) ) );
		}

		$quote = self::quote( $ids, $target, $service_id );
		if ( is_wp_error( $quote ) ) {
			wp_send_json_error( array( 'message' => $quote->get_error_message() ) );
		}
		wp_send_json_success( $quote );
	}

	/**
	 * Price quote for strings into one language (the first source language's batch;
	 * strings of several source languages are ordered separately, so the price shown
	 * is that of the largest batch).
	 *
	 * @param int[]  $ids        String ids.
	 * @param string $target     Target language code.
	 * @param int    $service_id OrderTypeConfigurationId.
	 * @return array{currency: string, currencySymbol: string, wordCount: int, deliveries: array<int, array<string, mixed>>}|WP_Error
	 */
	public static function quote( array $ids, string $target, int $service_id ) {
		$batches = self::batches( $ids, $target );
		if ( is_wp_error( $batches ) ) {
			return $batches;
		}
		usort( $batches, static fn( $a, $b ): int => count( $b['entries'] ) <=> count( $a['entries'] ) );
		$batch = $batches[0];

		$client      = new Client();
		$document_id = $client->upload_file( Content::render( $batch['entries'] ), 'strings-quote.html' );
		if ( is_wp_error( $document_id ) ) {
			return $document_id;
		}
		$body     = array(
			'OrderTypeConfigurationId' => $service_id,
			'OrderTypeId'              => Services::order_type_id( $service_id ),
			'SourceLang'               => $batch['source']->w3c(),
			'TargetLanguages'          => array( $batch['target']->w3c() ),
			'Files'                    => array(
				array(
					'Id'      => (int) $document_id,
					'Comment' => 'WPML strings',
				),
			),
		);
		$currency = (string) apply_filters( 'supertext_wpml_quote_currency', '' );
		if ( '' !== $currency ) {
			$body['Currency'] = $currency;
		}
		$quote = $client->get_quote( $body );
		if ( is_wp_error( $quote ) ) {
			return $quote;
		}

		$option     = ( isset( $quote['Options'][0] ) && is_array( $quote['Options'][0] ) ) ? $quote['Options'][0] : array();
		$deliveries = array();
		foreach ( (array) ( $option['DeliveryOptions'] ?? array() ) as $delivery ) {
			$did = (int) ( $delivery['DeliveryId'] ?? 0 );
			if ( $did > 0 ) {
				$deliveries[ $did ] = array(
					'delivery_id' => $did,
					'name'        => (string) ( $delivery['Name'] ?? '' ),
					'price'       => (float) ( $delivery['Price'] ?? 0 ),
					'date'        => (string) ( $delivery['DeliveryDate'] ?? '' ),
					'is_default'  => (bool) ( $delivery['IsDefaultDeliveryOption'] ?? false ),
				);
			}
		}
		ksort( $deliveries );
		$symbol = (string) ( $quote['CurrencySymbol'] ?? '' );

		return array(
			'currency'       => (string) ( $quote['Currency'] ?? '' ),
			'currencySymbol' => '' !== $symbol ? $symbol : (string) ( $quote['Currency'] ?? '' ),
			'wordCount'      => (int) ( $quote['WordCount'] ?? 0 ),
			'deliveries'     => array_values( $deliveries ),
		);
	}

	/**
	 * Orders the translation of strings into one language: one order per source language.
	 *
	 * @param int[]  $ids        String ids.
	 * @param string $target     Target language code.
	 * @param int    $service_id OrderTypeConfigurationId.
	 * @param string $express    DeliveryId.
	 * @return int[]|WP_Error Order ids.
	 */
	public static function place_order( array $ids, string $target, int $service_id, string $express ) {
		if ( ! Settings::is_configured() ) {
			return new WP_Error( 'supertext_not_configured', __( 'Enter your Supertext account email and Order API Key on the Settings page first.', 'supertext-wpml' ) );
		}
		$batches = self::batches( $ids, $target );
		if ( is_wp_error( $batches ) ) {
			return $batches;
		}

		$client    = new Client();
		$order_ids = array();
		foreach ( $batches as $batch ) {
			$name        = __( 'WPML strings', 'supertext-wpml' );
			$document_id = $client->upload_file( Content::render( $batch['entries'] ), 'wpml-strings.html' );
			if ( is_wp_error( $document_id ) ) {
				return $document_id;
			}
			$result = $client->create_order(
				array(
					'DeliveryId'               => (int) $express,
					'OrderName'                => $name,
					'OrderTypeConfigurationId' => $service_id,
					'ContentType'              => 'text/html',
					'Referrer'                 => 'Supertext for WPML',
					'SystemName'               => 'WordPress',
					'SystemVersion'            => get_bloginfo( 'version' ),
					'ComponentName'            => 'supertext-wpml',
					'ComponentVersion'         => SUPERTEXT_WPML_VERSION,
					'SourceLang'               => (string) strtok( $batch['source']->w3c(), '-' ),
					'TargetLanguages'          => array( $batch['target']->w3c() ),
					'ReferenceData'            => Callback::reference_data_for( self::TYPE, 0, $batch['target']->code ),
					'CallbackUrl'              => Callback::url(),
					'Files'                    => array(
						array(
							'Comment' => 'WPML strings',
							'Id'      => (int) $document_id,
						),
					),
				)
			);
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			foreach ( $result as $entry ) {
				if ( is_array( $entry ) && isset( $entry['Id'] ) ) {
					$order_ids[] = (int) $entry['Id'];
					Orders::record(
						array(
							'order_id'    => (int) $entry['Id'],
							'post_id'     => 0,
							'kind'        => self::TYPE,
							'entity_id'   => 0,
							'lang'        => $batch['target']->code,
							'target'      => $batch['target']->w3c(),
							'type_id'     => $service_id,
							'delivery_id' => (int) $express,
							'order_name'  => $name,
							'status'      => 'New',
						)
					);
				}
			}
		}

		return $order_ids;
	}

	/**
	 * Completion callback for string orders: saves the translations by string id.
	 *
	 * @param int[]  $order_ids Completed order ids.
	 * @param int    $entity_id Unused (0).
	 * @param string $lang      Target language code.
	 * @param mixed  $body      Decoded callback body.
	 * @param string $type      Entity type.
	 * @return void
	 */
	public static function writeback( $order_ids, $entity_id, $lang, $body, $type = 'post' ): void {
		if ( self::TYPE !== $type ) {
			return;
		}
		// Plain-text strings travel HTML-escaped (Content::render()) and are decoded.
		$rows  = String_Store::rows();
		$pairs = self::pairs( Writeback::download_map( $body ) );
		foreach ( $pairs as $id => $text ) {
			if ( isset( $rows[ $id ] ) && ! $rows[ $id ]['html'] ) {
				$pairs[ $id ] = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			}
		}
		String_Store::save( (string) $lang, $pairs );

		$status = is_array( $body ) && ! empty( $body['Status'] ) ? (string) $body['Status'] : 'Completed';
		foreach ( (array) $order_ids as $oid ) {
			Orders::update(
				(int) $oid,
				array(
					'status'       => $status,
					'completed_at' => gmdate( 'c' ),
				)
			);
		}
	}

	/**
	 * Context => translation (from an order file) to string id => translation.
	 *
	 * @param array<string, string> $map Context => translated HTML.
	 * @return array<int, string>
	 */
	public static function pairs( array $map ): array {
		$out = array();
		foreach ( $map as $context => $text ) {
			$context = (string) $context;
			if ( str_starts_with( $context, self::PREFIX ) && '' !== trim( (string) $text ) ) {
				$out[ (int) substr( $context, strlen( self::PREFIX ) ) ] = (string) $text;
			}
		}

		return $out;
	}

	/**
	 * The strings to order, grouped by source language (strings already in the target
	 * language are left out).
	 *
	 * @param int[]  $ids    String ids.
	 * @param string $target Target language code.
	 * @return array<int, array{source: Language, target: Language, entries: array<int, array{context: string, singular: string, html: bool}>}>|WP_Error
	 */
	private static function batches( array $ids, string $target ) {
		$target_language = Wpml::language( $target );
		if ( null === $target_language ) {
			return new WP_Error( 'supertext_bad_lang', __( 'Unknown target language.', 'supertext-wpml' ) );
		}
		$rows    = String_Store::rows();
		$batches = array();
		foreach ( array_unique( array_map( 'intval', $ids ) ) as $id ) {
			$row = $rows[ $id ] ?? null;
			if ( null === $row || $row['lang'] === $target ) {
				continue;
			}
			$source = Wpml::language( $row['lang'] );
			if ( null === $source ) {
				continue;
			}
			$batches[ $source->code ]['source']    = $source;
			$batches[ $source->code ]['target']    = $target_language;
			$batches[ $source->code ]['entries'][] = array(
				'context'  => self::PREFIX . $id,
				'singular' => $row['source'],
				'html'     => $row['html'],
			);
		}
		if ( array() === $batches ) {
			return new WP_Error( 'supertext_empty', __( 'No strings selected to translate into this language.', 'supertext-wpml' ) );
		}

		return array_values( $batches );
	}
}
