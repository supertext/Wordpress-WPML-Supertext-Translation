<?php
/**
 * @package Supertext_Wpml
 */

namespace Supertext\Wpml\Admin;

defined( 'ABSPATH' ) || exit;

use Supertext\Wpml\Human_Translation\Client as Human_Client;
use Supertext\Wpml\Human_Translation\Orders;

/**
 * "Orders" submenu under Supertext: lists human-translation orders placed through
 * the plugin, lets you refresh their status and cancel ongoing ones.
 *
 * @since 0.1.0
 */
class Orders_Page {
	/**
	 * Submenu slug.
	 *
	 * @var string
	 */
	const SLUG = 'supertext-wpml-orders';

	/**
	 * admin-post action: cancel an order.
	 *
	 * @var string
	 */
	const CANCEL_ACTION = 'supertext_wpml_cancel_order';

	/**
	 * admin-post action: refresh order statuses.
	 *
	 * @var string
	 */
	const REFRESH_ACTION = 'supertext_wpml_refresh_orders';

	/**
	 * Transient key for one-off notices.
	 *
	 * @var string
	 */
	const NOTICE_TRANSIENT = 'supertext_wpml_orders_notice';

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( self::class, 'register_menu' ), 11 );
		add_action( 'admin_post_' . self::CANCEL_ACTION, array( self::class, 'handle_cancel' ) );
		add_action( 'admin_post_' . self::REFRESH_ACTION, array( self::class, 'handle_refresh' ) );
	}

	/**
	 * Registers the submenu.
	 *
	 * @return void
	 */
	public static function register_menu(): void {
		add_submenu_page(
			Page::SLUG,
			__( 'Orders', 'supertext-wpml' ),
			__( 'Orders', 'supertext-wpml' ),
			'manage_options',
			self::SLUG,
			array( self::class, 'render' )
		);
	}

	/**
	 * Handles cancelling an order.
	 *
	 * This is an internal cancel: it marks the order Cancelled and removes the
	 * per-post lock so the post can be ordered again. It does not call Supertext.
	 *
	 * @return void
	 */
	public static function handle_cancel(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'supertext-wpml' ) );
		}
		check_admin_referer( self::CANCEL_ACTION );

		$order_id = isset( $_POST['order_id'] ) ? (int) $_POST['order_id'] : 0;
		$order    = $order_id > 0 ? Orders::get( $order_id ) : null;

		if ( null === $order ) {
			self::notice( 'error', __( 'Order not found.', 'supertext-wpml' ) );
		} else {
			// Remove the per-post/language lock so the post can be ordered again.
			if ( ! empty( $order['post_id'] ) && ! empty( $order['lang'] ) ) {
				delete_post_meta( (int) $order['post_id'], '_supertext_order_' . $order['lang'] );
			}
			Orders::update( $order_id, array( 'status' => 'Cancelled' ) );
			self::notice( 'success', __( 'Order reset locally. You can order this post again. (The Supertext order was not cancelled.)', 'supertext-wpml' ) );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) );
		exit;
	}

	/**
	 * Handles refreshing all order statuses from Supertext.
	 *
	 * @return void
	 */
	public static function handle_refresh(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'supertext-wpml' ) );
		}
		check_admin_referer( self::REFRESH_ACTION );

		$client  = new Human_Client();
		$updated = 0;
		foreach ( Orders::all() as $order ) {
			$data = $client->get_order( (int) $order['order_id'] );
			if ( is_wp_error( $data ) || empty( $data['Status'] ) ) {
				continue;
			}
			Orders::update( (int) $order['order_id'], array( 'status' => (string) $data['Status'] ) );
			$updated++;
		}

		/* translators: %d is a number of orders. */
		self::notice( 'success', sprintf( __( 'Refreshed %d order(s).', 'supertext-wpml' ), $updated ) );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) );
		exit;
	}

	/**
	 * Stores a one-off notice.
	 *
	 * @param string $type    'success' or 'error'.
	 * @param string $message Message.
	 * @return void
	 */
	private static function notice( string $type, string $message ): void {
		set_transient( self::NOTICE_TRANSIENT . '_' . get_current_user_id(), array( 'type' => $type, 'text' => $message ), 60 );
	}

	/**
	 * Statuses considered "collected" (hidden by the "In progress" filter).
	 *
	 * @return string[] Lower-cased status strings.
	 */
	private static function collected_statuses(): array {
		/** @var string[] $statuses */
		$statuses = apply_filters( 'supertext_wpml_collected_statuses', array( 'Collected' ) );
		return array_map( 'strtolower', array_map( 'strval', $statuses ) );
	}

	/**
	 * Renders the orders table.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$notice_key = self::NOTICE_TRANSIENT . '_' . get_current_user_id();
		$notice     = get_transient( $notice_key );
		if ( is_array( $notice ) ) {
			delete_transient( $notice_key );
			printf(
				'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
				esc_attr( 'error' === $notice['type'] ? 'error' : 'success' ),
				esc_html( $notice['text'] )
			);
		}

		$orders = Orders::all();

		// Status filter: "in_progress" (default) hides collected orders; "all" shows everything.
		$view         = ( isset( $_GET['view'] ) && 'all' === sanitize_key( wp_unslash( $_GET['view'] ) ) ) ? 'all' : 'in_progress'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$collected    = self::collected_statuses();
		$is_collected = static function ( $order ) use ( $collected ) {
			return in_array( strtolower( (string) ( $order['status'] ?? '' ) ), $collected, true );
		};
		$in_progress_count = count( array_filter( $orders, static fn( $o ) => ! $is_collected( $o ) ) );
		$visible           = 'all' === $view ? $orders : array_filter( $orders, static fn( $o ) => ! $is_collected( $o ) );
		?>
		<div class="wrap supertext-admin">
			<?php
			Page::hero(
				__( 'Supertext Orders', 'supertext-wpml' ),
				__( 'Human-translation orders placed from WordPress through this plugin.', 'supertext-wpml' )
			);
			?>

			<p class="description" style="max-width:640px;">
				<?php
				printf(
					/* translators: %s is a link to the Supertext orders overview. */
					esc_html__( 'This list shows only translation orders placed from WordPress through this plugin. For a list of all your orders, go to %s.', 'supertext-wpml' ),
					sprintf(
						'<a href="%s" target="_blank" rel="noopener">%s</a>',
						esc_url( Settings::orders_url() ),
						esc_html__( 'your Supertext orders', 'supertext-wpml' )
					)
				); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- link built from escaped parts.
				?>
			</p>

			<p style="margin:1em 0;">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::REFRESH_ACTION ); ?>" />
					<?php wp_nonce_field( self::REFRESH_ACTION ); ?>
					<?php
					submit_button(
						__( 'Refresh statuses', 'supertext-wpml' ),
						'secondary',
						'submit',
						false,
						array( 'title' => esc_attr__( 'Refreshes each order\'s status from the Supertext system.', 'supertext-wpml' ) )
					);
					?>
				</form>
			</p>

			<?php if ( empty( $orders ) ) : ?>
				<p><?php esc_html_e( 'No orders yet.', 'supertext-wpml' ); ?></p>
			<?php else : ?>
				<ul class="subsubsub">
					<li>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ); ?>" class="<?php echo 'in_progress' === $view ? 'current' : ''; ?>">
							<?php esc_html_e( 'In progress', 'supertext-wpml' ); ?> <span class="count">(<?php echo (int) $in_progress_count; ?>)</span>
						</a> |
					</li>
					<li>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&view=all' ) ); ?>" class="<?php echo 'all' === $view ? 'current' : ''; ?>">
							<?php esc_html_e( 'All', 'supertext-wpml' ); ?> <span class="count">(<?php echo (int) count( $orders ); ?>)</span>
						</a>
					</li>
				</ul>
				<table class="widefat striped" style="clear:both;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Order', 'supertext-wpml' ); ?></th>
							<th><?php esc_html_e( 'Post', 'supertext-wpml' ); ?></th>
							<th><?php esc_html_e( 'Target', 'supertext-wpml' ); ?></th>
							<th><?php esc_html_e( 'Type', 'supertext-wpml' ); ?></th>
							<th><?php esc_html_e( 'Delivery', 'supertext-wpml' ); ?></th>
							<th><?php esc_html_e( 'Status', 'supertext-wpml' ); ?></th>
							<th><?php esc_html_e( 'Ordered', 'supertext-wpml' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'supertext-wpml' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php if ( empty( $visible ) ) : ?>
							<tr><td colspan="8"><?php esc_html_e( 'No orders to show in this view.', 'supertext-wpml' ); ?></td></tr>
						<?php endif; ?>
						<?php foreach ( $visible as $order ) : ?>
							<?php
							$type_label     = Bulk_Actions::service_label( (int) $order['type_id'] );
							$delivery_label = Bulk_Actions::EXPRESS_OPTIONS[ (string) $order['delivery_id'] ] ?? (string) $order['delivery_id'];
							$is_open        = empty( $order['completed_at'] ) && 'Cancelled' !== ( $order['status'] ?? '' );
							?>
							<tr>
								<td>
									<a href="<?php echo esc_url( Settings::order_url( (int) $order['order_id'] ) ); ?>" target="_blank" rel="noopener">
										<?php echo esc_html( (string) $order['order_id'] ); ?>
									</a>
								</td>
								<td>
									<?php if ( $order['post_id'] ) : ?>
										<a href="<?php echo esc_url( get_edit_post_link( (int) $order['post_id'] ) ); ?>">
											<?php echo esc_html( $order['order_name'] !== '' ? $order['order_name'] : get_the_title( (int) $order['post_id'] ) ); ?>
										</a>
									<?php else : ?>
										<?php echo esc_html( (string) $order['order_name'] ); ?>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( (string) $order['target'] ); ?></td>
								<td><?php echo esc_html( (string) $type_label ); ?></td>
								<td><?php echo esc_html( (string) $delivery_label ); ?></td>
								<td><?php echo esc_html( (string) ( $order['status'] ?? '' ) ); ?></td>
								<td><?php echo esc_html( (string) ( $order['created_at'] ?? '' ) ); ?></td>
								<td>
									<?php if ( $is_open ) : ?>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( "Reset this order locally?\n\nThis is for debugging only. It removes the local lock so you can order this post again — it does NOT cancel the order at Supertext.", 'supertext-wpml' ) ); ?>');">
											<input type="hidden" name="action" value="<?php echo esc_attr( self::CANCEL_ACTION ); ?>" />
											<input type="hidden" name="order_id" value="<?php echo esc_attr( (string) $order['order_id'] ); ?>" />
											<?php wp_nonce_field( self::CANCEL_ACTION ); ?>
											<?php
											submit_button(
												__( 'Reset', 'supertext-wpml' ),
												'secondary small',
												'submit',
												false,
												array( 'title' => esc_attr__( 'Debug only: removes the local lock so the post can be ordered again. Does not cancel the Supertext order.', 'supertext-wpml' ) )
											);
											?>
										</form>
									<?php else : ?>
										—
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}
}
