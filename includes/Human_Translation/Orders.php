<?php
/**
 * @package Supertext_Wpml
 */

namespace Supertext\Wpml\Human_Translation;

defined( 'ABSPATH' ) || exit;

use WP_Query;

/**
 * Registry of human-translation orders placed through the plugin.
 *
 * Stored in the `supertext_wpml_orders` option, keyed by Supertext order id,
 * so the Orders admin page can list current and past orders, refresh their status,
 * and cancel ongoing ones.
 *
 * @since 0.1.0
 */
class Orders {
	/**
	 * Option name.
	 *
	 * @var string
	 */
	const OPTION = 'supertext_wpml_orders';

	/**
	 * Returns all orders, newest first.
	 *
	 * @return array<int, array>
	 */
	public static function all(): array {
		$orders = get_option( self::OPTION, array() );
		if ( ! is_array( $orders ) ) {
			return array();
		}

		uasort(
			$orders,
			static function ( $a, $b ) {
				return strcmp( (string) ( $b['created_at'] ?? '' ), (string) ( $a['created_at'] ?? '' ) );
			}
		);

		return $orders;
	}

	/**
	 * Returns one order, or null.
	 *
	 * @param int $id Order id.
	 * @return array|null
	 */
	public static function get( int $id ) {
		$orders = get_option( self::OPTION, array() );
		return is_array( $orders ) && isset( $orders[ $id ] ) ? $orders[ $id ] : null;
	}

	/**
	 * Records (or replaces) an order.
	 *
	 * @param array $order Order data (must contain `order_id`).
	 * @return void
	 */
	public static function record( array $order ): void {
		if ( empty( $order['order_id'] ) ) {
			return;
		}

		$orders = get_option( self::OPTION, array() );
		if ( ! is_array( $orders ) ) {
			$orders = array();
		}

		$id            = (int) $order['order_id'];
		$orders[ $id ] = array_merge(
			array(
				'order_id'     => $id,
				'post_id'      => 0,
				'lang'         => '',
				'target'       => '',
				'type_id'      => 0,
				'delivery_id'  => 0,
				'order_name'   => '',
				'status'       => 'New',
				'created_at'   => gmdate( 'c' ),
				'completed_at' => null,
			),
			$order
		);

		update_option( self::OPTION, $orders, false );
	}

	/**
	 * Backfills the registry from the `_supertext_order_{lang}` post metas that
	 * orders wrote before the registry existed.
	 *
	 * @return int Number of orders imported.
	 */
	public static function backfill(): int {
		$imported = 0;

		foreach ( array_keys( \Supertext\Wpml\Wpml\Wpml::languages() ) as $code ) {
			$meta_key = '_supertext_order_' . $code;

			$query = new WP_Query(
				array(
					'post_type'        => 'any',
					'post_status'      => 'any',
					'posts_per_page'   => -1,
					'fields'           => 'ids',
					'meta_key'         => $meta_key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'no_found_rows'    => true,
					'suppress_filters' => true,
				)
			);

			foreach ( $query->posts as $post_id ) {
				$data = json_decode( (string) get_post_meta( (int) $post_id, $meta_key, true ), true );
				if ( ! is_array( $data ) || empty( $data['order_ids'] ) ) {
					continue;
				}

				foreach ( (array) $data['order_ids'] as $order_id ) {
					$order_id = (int) $order_id;
					if ( $order_id <= 0 || null !== self::get( $order_id ) ) {
						continue; // Invalid, or already in the registry.
					}

					self::record(
						array(
							'order_id'    => $order_id,
							'post_id'     => (int) $post_id,
							'lang'        => $code,
							'target'      => (string) ( $data['target'] ?? '' ),
							'type_id'     => (int) ( $data['service_id'] ?? 0 ),
							'delivery_id' => (int) ( $data['delivery_id'] ?? 0 ),
							'order_name'  => get_the_title( (int) $post_id ),
							'status'      => 'Unknown',
							'created_at'  => (string) ( $data['ordered_at'] ?? gmdate( 'c' ) ),
						)
					);
					$imported++;
				}
			}
		}

		return $imported;
	}

	/**
	 * Updates fields of an existing order.
	 *
	 * @param int   $id    Order id.
	 * @param array $patch Fields to merge.
	 * @return void
	 */
	public static function update( int $id, array $patch ): void {
		$orders = get_option( self::OPTION, array() );
		if ( ! is_array( $orders ) || ! isset( $orders[ $id ] ) ) {
			return;
		}

		$orders[ $id ] = array_merge( $orders[ $id ], $patch );
		update_option( self::OPTION, $orders, false );
	}
}
