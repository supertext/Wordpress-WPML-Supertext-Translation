<?php
/**
 * @package Supertext_Wpml
 */

namespace Supertext\Wpml\Admin;

defined( 'ABSPATH' ) || exit;

use WP_Error;
use WP_Post;
use WP_Screen;
use Supertext\Wpml\Human_Translation\Callback as Human_Callback;
use Supertext\Wpml\Human_Translation\Client as Human_Client;
use Supertext\Wpml\Human_Translation\Content as Human_Content;
use Supertext\Wpml\Human_Translation\Orders as Human_Orders;
use Supertext\Wpml\Human_Translation\Services as Human_Services;
use Supertext\Wpml\Machine_Translation\Ai_Settings;
use Supertext\Wpml\Machine_Translation\Translator;
use Supertext\Wpml\Wpml\Wpml;

/**
 * Adds "Supertext AI Translation" and "Supertext Human Translation" bulk actions
 * to the posts list table, with the target-language / service-type / delivery
 * dropdowns between the bulk-action select and the Apply button.
 *
 * The AI path translates each post with Machine_Translation\Translator (creates
 * or — never in bulk — overwrites the WPML translation); the human path uploads the
 * same texts (Content\Collector) and places an order. Posts that already have a
 * translation in the target language are skipped by the AI action (retranslate from
 * the editor's Supertext box).
 *
 * @since 0.1.0
 */
class Bulk_Actions {
	const ACTION_AI    = 'supertext_ai_translation';
	const ACTION_HUMAN = 'supertext_human_translation';

	/** User meta storing the last-selected order options (to pre-fill next time). */
	const PREFS_META = 'supertext_wpml_last_order';

	/** AJAX action name for fetching a live price quote. */
	const QUOTE_ACTION = 'supertext_wpml_quote';

	// Human translation product types (the "Translation type" dropdown) are loaded
	// from the configured Supertext ServiceID — see Human_Services.

	/** Human translation delivery options (Supertext DeliveryId => label). */
	const EXPRESS_OPTIONS = array(
		'1' => 'Express',
		'2' => '24h',
		'3' => '48h',
		'4' => '3 Tage',
		'5' => '1 Woche',
	);

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'current_screen', array( self::class, 'hook_screen' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_assets' ) );
		add_action( 'admin_notices', array( self::class, 'render_notice' ) );
		add_action( 'wp_ajax_' . self::QUOTE_ACTION, array( self::class, 'handle_quote' ) );
	}

	/**
	 * Enqueues the list-table script that toggles the dropdowns.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public static function enqueue_assets( string $hook ): void {
		if ( 'edit.php' !== $hook ) {
			return;
		}

		wp_enqueue_script(
			'supertext-wpml-bulk',
			plugins_url( 'assets/js/bulk-actions.js', SUPERTEXT_WPML_FILE ),
			array( 'jquery' ),
			SUPERTEXT_WPML_VERSION,
			true
		);

		wp_localize_script(
			'supertext-wpml-bulk',
			'SupertextBulk',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'quoteAction' => self::QUOTE_ACTION,
				'quoteNonce'  => wp_create_nonce( self::QUOTE_ACTION ),
				'i18n'        => array(
					'quoting'            => __( 'Getting price…', 'supertext-wpml' ),
					'words'              => __( 'words', 'supertext-wpml' ),
					'from'               => __( 'from', 'supertext-wpml' ),
					'quoteFail'          => __( 'Could not get a price.', 'supertext-wpml' ),
					'delivery'           => __( 'Delivery', 'supertext-wpml' ),
					'noOptions'          => __( 'No delivery options', 'supertext-wpml' ),
					'submittingBtnHuman' => __( 'Submitting order…', 'supertext-wpml' ),
					'submittingBtnAi'    => __( 'Submitting…', 'supertext-wpml' ),
					'submittingMsgHuman' => __( "Placing your Supertext order(s) — uploading content and capturing screenshots. This can take a moment; please don't close this tab.", 'supertext-wpml' ),
					'submittingMsgAi'    => __( "Submitting your Supertext AI translation(s) — this can take a moment; please don't close this tab.", 'supertext-wpml' ),
				),
			)
		);
	}

	/**
	 * Hooks the per-screen bulk-action filters once we know the current screen.
	 *
	 * @param WP_Screen $screen Current screen.
	 * @return void
	 */
	public static function hook_screen( WP_Screen $screen ): void {
		if ( 'edit' !== $screen->base ) {
			return;
		}

		add_filter( "bulk_actions-{$screen->id}", array( self::class, 'add_bulk_actions' ) );
		add_filter( "handle_bulk_actions-{$screen->id}", array( self::class, 'handle_bulk_action' ), 10, 3 );
		add_action( 'restrict_manage_posts', array( self::class, 'render_pickers' ) );
	}

	/**
	 * Adds the two Supertext bulk actions.
	 *
	 * @param array $actions Existing bulk actions.
	 * @return array
	 */
	public static function add_bulk_actions( array $actions ): array {
		$actions[ self::ACTION_AI ]    = __( 'Supertext AI Translation', 'supertext-wpml' );
		$actions[ self::ACTION_HUMAN ] = __( 'Supertext Human Translation', 'supertext-wpml' );
		return $actions;
	}

	/**
	 * Renders the (hidden) target-language, service-type and delivery dropdowns.
	 *
	 * @return void
	 */
	public static function render_pickers(): void {
		$languages   = self::get_languages();
		$prefs       = self::get_user_prefs();
		$sel_lang    = (string) ( $prefs['lang'] ?? '' );
		$sel_service = (int) ( $prefs['service_id'] ?? 0 );
		$sel_express = (string) ( $prefs['express'] ?? '' );
		?>
		<span id="supertext-lang-picker" style="display:none;">
			<label for="supertext_target_lang" class="screen-reader-text"><?php esc_html_e( 'Target language', 'supertext-wpml' ); ?></label>
			<select name="supertext_target_lang" id="supertext_target_lang">
				<option value=""><?php esc_html_e( 'Target language', 'supertext-wpml' ); ?></option>
				<?php foreach ( $languages as $slug => $name ) : ?>
					<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $sel_lang, $slug ); ?>><?php echo esc_html( $name ); ?></option>
				<?php endforeach; ?>
			</select>
		</span>
		<span id="supertext-service-picker" style="display:none;">
			<label for="supertext_service_id" class="screen-reader-text"><?php esc_html_e( 'Translation type', 'supertext-wpml' ); ?></label>
			<select name="supertext_service_id" id="supertext_service_id">
				<option value=""><?php esc_html_e( 'Translation type', 'supertext-wpml' ); ?></option>
				<?php foreach ( Human_Services::all() as $id => $service ) : ?>
					<option
						value="<?php echo esc_attr( (string) $id ); ?>"
						data-order-type-configuration-id="<?php echo esc_attr( (string) $id ); ?>"
						data-order-type-id="<?php echo esc_attr( (string) $service['order_type_id'] ); ?>"
						title="<?php echo esc_attr( $service['short'] ); ?>"
						<?php selected( $sel_service, $id ); ?>
					><?php echo esc_html( $service['label'] ); ?></option>
				<?php endforeach; ?>
			</select>
		</span>
		<span id="supertext-express-picker" style="display:none;">
			<label for="supertext_express" class="screen-reader-text"><?php esc_html_e( 'Delivery', 'supertext-wpml' ); ?></label>
			<?php // Options + prices are filled in from the live quote; disabled until then. ?>
			<select name="supertext_express" id="supertext_express" data-preferred="<?php echo esc_attr( $sel_express ); ?>" disabled>
				<option value=""><?php esc_html_e( 'Delivery', 'supertext-wpml' ); ?></option>
			</select>
		</span>
		<?php
	}

	/**
	 * Returns the display label for an OrderTypeConfigurationId (falls back to the id).
	 *
	 * @param int $config_id OrderTypeConfigurationId.
	 * @return string
	 */
	public static function service_label( int $config_id ): string {
		return Human_Services::label( $config_id );
	}

	/**
	 * Returns the Supertext OrderTypeId for an OrderTypeConfigurationId, or 0.
	 *
	 * @param int $config_id OrderTypeConfigurationId.
	 * @return int
	 */
	public static function order_type_id( int $config_id ): int {
		return Human_Services::order_type_id( $config_id );
	}

	/**
	 * Returns the current user's last-selected order options.
	 *
	 * @return array{lang?: string, service_id?: int, express?: string}
	 */
	private static function get_user_prefs(): array {
		$prefs = get_user_meta( get_current_user_id(), self::PREFS_META, true );
		return is_array( $prefs ) ? $prefs : array();
	}

	/**
	 * Returns the WPML languages as code => name.
	 *
	 * @return array<string, string>
	 */
	private static function get_languages(): array {
		$out = array();
		foreach ( Wpml::languages() as $code => $language ) {
			$out[ $code ] = $language->name;
		}
		return $out;
	}

	/**
	 * Handles a Supertext bulk action.
	 *
	 * @param string $redirect_url Redirect URL.
	 * @param string $action       Bulk action name.
	 * @param int[]  $post_ids     Selected post IDs.
	 * @return string
	 */
	public static function handle_bulk_action( string $redirect_url, string $action, array $post_ids ): string {
		if ( ! in_array( $action, array( self::ACTION_AI, self::ACTION_HUMAN ), true ) ) {
			return $redirect_url;
		}

		$target_lang = sanitize_text_field( wp_unslash( $_REQUEST['supertext_target_lang'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $target_lang || null === Wpml::language( $target_lang ) ) {
			return add_query_arg( 'supertext_error', 'no_lang', $redirect_url );
		}

		$service_id = 0;
		$express    = '';

		if ( self::ACTION_AI === $action && ! Ai_Settings::has_api_key() ) {
			return add_query_arg( 'supertext_error', 'mt_not_configured', $redirect_url );
		}

		if ( self::ACTION_HUMAN === $action ) {
			if ( ! Settings::is_configured() ) {
				return add_query_arg( 'supertext_error', 'human_not_configured', $redirect_url );
			}
			$service_id = (int) ( $_REQUEST['supertext_service_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( ! Human_Services::exists( $service_id ) ) {
				return add_query_arg( 'supertext_error', 'no_service', $redirect_url );
			}
			$express = sanitize_key( wp_unslash( $_REQUEST['supertext_express'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( ! isset( self::EXPRESS_OPTIONS[ $express ] ) ) {
				return add_query_arg( 'supertext_error', 'no_delivery', $redirect_url );
			}
		}

		// Remember the user's selections to pre-fill the dropdowns next time.
		$prefs         = self::get_user_prefs();
		$prefs['lang'] = $target_lang;
		if ( self::ACTION_HUMAN === $action ) {
			$prefs['service_id'] = $service_id;
			$prefs['express']    = $express;
		}
		update_user_meta( get_current_user_id(), self::PREFS_META, $prefs );

		$created        = 0;
		$errors         = 0;
		$error_messages = array();
		$order_ids_all  = array();

		foreach ( $post_ids as $post_id ) {
			$res = self::ACTION_AI === $action
				? self::ai_translate( (int) $post_id, $target_lang )
				: self::submit_human_order( (int) $post_id, $target_lang, $service_id, $express );

			if ( $res instanceof WP_Error || false === $res ) {
				$errors++;
				if ( is_wp_error( $res ) ) {
					$error_messages[] = $res->get_error_message();
				}
			} else {
				$created++;
				if ( is_array( $res ) ) {
					$order_ids_all = array_merge( $order_ids_all, $res );
				}
			}
		}

		if ( ! empty( $error_messages ) ) {
			set_transient( 'supertext_wpml_bulk_errors_' . get_current_user_id(), array_values( array_unique( $error_messages ) ), 60 );
		}

		if ( ! empty( $order_ids_all ) ) {
			set_transient( 'supertext_wpml_bulk_orders_' . get_current_user_id(), array_values( array_map( 'intval', $order_ids_all ) ), 60 );
		}

		return add_query_arg(
			array(
				'supertext_action'  => $action,
				'supertext_created' => $created,
				'supertext_errors'  => $errors,
			),
			$redirect_url
		);
	}

	/**
	 * Translates one post with Supertext AI (never overwrites an existing translation).
	 *
	 * @param int    $post_id     Source post ID.
	 * @param string $target_lang Target language code.
	 * @return bool|WP_Error
	 */
	private static function ai_translate( int $post_id, string $target_lang ) {
		$result = Translator::translate( $post_id, $target_lang, false );

		return $result instanceof WP_Error ? $result : true;
	}

	/**
	 * Builds the translatable HTML for a post and uploads it to Supertext.
	 *
	 * Shared by the order and quote paths so a quote reflects exactly what an order
	 * would send. Validates the post + languages, collects the translatable content
	 * (via the export pipeline), and uploads it, returning the Supertext DocumentId
	 * plus the language codes/title needed to build an order or a quote.
	 *
	 * @param Human_Client $client      Human API client.
	 * @param int          $post_id     Source post ID.
	 * @param string       $target_lang Target language slug.
	 * @return array{document_id:int, title:string, source_lang:string, source_w3c:string, target_w3c:string}|WP_Error
	 */
	private static function upload_post_content( Human_Client $client, int $post_id, string $target_lang ) {
		$check = Translator::check( $post_id, $target_lang );
		if ( $check instanceof WP_Error ) {
			return $check;
		}

		$post   = get_post( $post_id );
		$lang   = Wpml::language( $target_lang );
		$source = Wpml::language( Wpml::post_language( $post_id ) );
		if ( ! $post instanceof WP_Post || null === $lang || null === $source ) {
			return new WP_Error( 'supertext_invalid', __( 'Invalid post, or missing source/target language.', 'supertext-wpml' ) );
		}

		$entries = Human_Content::collect( $post, $target_lang );
		if ( empty( $entries ) ) {
			return new WP_Error(
				'supertext_empty',
				sprintf(
					/* translators: %s: post title. */
					__( '"%s": no translatable content found.', 'supertext-wpml' ),
					$post->post_title
				)
			);
		}

		$html     = Human_Content::render( $entries );
		$title    = '' !== $post->post_title ? $post->post_title : 'post-' . $post_id;
		$filename = sanitize_file_name( $title );
		$filename = ( '' !== $filename ? $filename : 'content-' . $post_id ) . '.html';

		$document_id = $client->upload_file( $html, $filename );
		if ( is_wp_error( $document_id ) ) {
			return $document_id;
		}

		return array(
			'document_id' => (int) $document_id,
			'title'       => (string) $title,
			'source_lang' => (string) strtok( $source->w3c(), '-' ), // 2-letter primary subtag.
			'source_w3c'  => $source->w3c(),
			'target_w3c'  => $lang->w3c(),
		);
	}

	/**
	 * Best-effort: captures a screenshot of the source page and uploads it to
	 * Supertext as an order reference file (DocumentTypeId 3).
	 *
	 * The page is captured through its secret preview link (so unpublished drafts
	 * are reachable too), via VibeBoost Screenshots. Returns 0 when the feature is
	 * disabled or the capture/upload fails — the order then simply carries no
	 * screenshot rather than failing.
	 *
	 * @param Human_Client $client  Human API client.
	 * @param int          $post_id Source post ID.
	 * @return int The uploaded screenshot's DocumentId, or 0.
	 */
	private static function maybe_upload_screenshot( Human_Client $client, int $post_id ): int {
		if ( ! Settings::screenshots_enabled() ) {
			return 0;
		}

		$url = \Supertext\Wpml\Preview\Draft_Preview::ensure_preview_url( $post_id );
		if ( '' === $url ) {
			return 0;
		}

		$image = ( new \Supertext\Wpml\Integrations\VibeBoost\Client( Settings::screenshots_api_key() ) )->capture( $url );
		if ( is_wp_error( $image ) || '' === (string) $image ) {
			return 0;
		}

		$document_id = $client->upload_file( (string) $image, 'screenshot-' . $post_id . '.png', 'image/png', 3 );

		return is_wp_error( $document_id ) ? 0 : (int) $document_id;
	}

	/**
	 * Whether an order for this post + language is still open — recorded in the
	 * registry, not completed and not cancelled. Distinguishes a genuine in-progress
	 * order from a stale `_supertext_order_<lang>` lock left behind after cleanup.
	 *
	 * @param int    $post_id Source post id.
	 * @param string $lang    Target language slug.
	 * @return bool
	 */
	private static function has_open_order( int $post_id, string $lang ): bool {
		foreach ( Human_Orders::all() as $order ) {
			if ( (int) ( $order['post_id'] ?? 0 ) !== $post_id || (string) ( $order['lang'] ?? '' ) !== $lang ) {
				continue;
			}
			$completed = ! empty( $order['completed_at'] );
			$cancelled = 'Cancelled' === ( $order['status'] ?? '' );
			if ( ! $completed && ! $cancelled ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Places a human-translation order with Supertext: builds the content HTML,
	 * uploads it, and creates the order. The completion callback writes it back.
	 *
	 * @param int    $post_id     Source post ID.
	 * @param string $target_lang Target language slug.
	 * @param int    $service_id  Supertext OrderTypeConfigurationId.
	 * @param string $express     Supertext DeliveryId.
	 * @return int[]|WP_Error Order id(s) on success.
	 */
	private static function submit_human_order( int $post_id, string $target_lang, int $service_id, string $express ) {
		// Avoid placing a duplicate (paid) order for the same target language — but only
		// when a matching order is actually still open in the registry. A leftover lock
		// meta with no open order behind it (e.g. the order registry was cleared, or an
		// order was cancelled outside the plugin) must not block re-ordering forever, so
		// treat such a lock as stale: clear it and continue.
		if ( get_post_meta( $post_id, '_supertext_order_' . $target_lang, true ) ) {
			if ( self::has_open_order( $post_id, $target_lang ) ) {
				return new WP_Error(
					'supertext_already_ordered',
					sprintf(
						/* translators: 1: post title, 2: language slug. */
						__( 'An order already exists for "%1$s" (%2$s).', 'supertext-wpml' ),
						get_the_title( $post_id ),
						$target_lang
					)
				);
			}
			delete_post_meta( $post_id, '_supertext_order_' . $target_lang );
		}

		$client   = new Human_Client();
		$prepared = self::upload_post_content( $client, $post_id, $target_lang );
		if ( is_wp_error( $prepared ) ) {
			return $prepared;
		}

		$files = array(
			array(
				'Comment' => 'WordPress content',
				'Id'      => $prepared['document_id'],
			),
		);

		// Attach a page screenshot as a visual reference for the translator, if enabled.
		$screenshot_id = self::maybe_upload_screenshot( $client, $post_id );
		if ( $screenshot_id > 0 ) {
			$files[] = array(
				'Comment' => 'Page screenshot (visual reference)',
				'Id'      => $screenshot_id,
			);
		}

		$order = array(
			'DeliveryId'               => (int) $express,
			'OrderName'                => $prepared['title'],
			'OrderTypeConfigurationId' => $service_id,
			'ContentType'              => 'text/html',
			'Referrer'                 => 'Supertext for WPML',
			'SystemName'               => 'WordPress',
			'SystemVersion'            => get_bloginfo( 'version' ),
			'ComponentName'            => 'supertext-wpml',
			'ComponentVersion'         => SUPERTEXT_WPML_VERSION,
			'SourceLang'               => $prepared['source_lang'],
			'TargetLanguages'          => array( $prepared['target_w3c'] ),
			'ReferenceData'            => Human_Callback::reference_data( $post_id, $target_lang ),
			'CallbackUrl'              => Human_Callback::url(),
			'Files'                    => $files,
		);

		$result = $client->create_order( $order );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// The order endpoint returns an array of order objects (one per target
		// language). Capture the order id(s) so we can reconcile on callback.
		$order_ids = array();
		if ( is_array( $result ) ) {
			foreach ( $result as $entry ) {
				if ( is_array( $entry ) && isset( $entry['Id'] ) ) {
					$order_ids[] = (int) $entry['Id'];
				}
			}
		}

		// Add each order to the registry shown on the Orders admin page.
		foreach ( $order_ids as $oid ) {
			Human_Orders::record(
				array(
					'order_id'    => $oid,
					'post_id'     => $post_id,
					'lang'        => $target_lang,
					'target'      => $prepared['target_w3c'],
					'type_id'     => $service_id,
					'delivery_id' => (int) $express,
					'order_name'  => $prepared['title'],
					'status'      => 'New',
				)
			);
		}

		// Record the order so we can reconcile it when the callback is implemented.
		update_post_meta(
			$post_id,
			'_supertext_order_' . $target_lang,
			wp_json_encode(
				array(
					'order_ids'   => $order_ids,
					'document_id' => $prepared['document_id'],
					'service_id'  => $service_id,
					'delivery_id' => (int) $express,
					'target'      => $prepared['target_w3c'],
					'ordered_at'  => gmdate( 'c' ),
				)
			)
		);

		return $order_ids;
	}

	/**
	 * AJAX: returns a live price quote for the selected posts + chosen service.
	 *
	 * Uploads each selected post's translatable content (exactly as an order would),
	 * groups the uploads by source language (the quote endpoint takes one SourceLang
	 * per call), requests a quote per group, and aggregates word count + per-delivery
	 * prices across groups.
	 *
	 * @return void
	 */
	public static function handle_quote(): void {
		check_ajax_referer( self::QUOTE_ACTION, 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'supertext-wpml' ) ), 403 );
		}
		if ( ! Settings::is_configured() ) {
			wp_send_json_error( array( 'message' => __( 'Supertext human-translation credentials are not configured.', 'supertext-wpml' ) ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce checked above.
		$target_lang = sanitize_text_field( wp_unslash( $_POST['target_lang'] ?? '' ) );
		$service_id  = (int) ( $_POST['service_id'] ?? 0 );
		$post_ids    = array_values( array_unique( array_filter( array_map( 'intval', (array) ( $_POST['post_ids'] ?? array() ) ) ) ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( '' === $target_lang || ! Human_Services::exists( $service_id ) || empty( $post_ids ) ) {
			wp_send_json_error( array( 'message' => __( 'Select a translation type, a target language, and at least one post.', 'supertext-wpml' ) ) );
		}

		$client = new Human_Client();

		// Upload each post, grouped by source language.
		$groups = array();
		$errors = array();
		foreach ( $post_ids as $post_id ) {
			$prepared = self::upload_post_content( $client, $post_id, $target_lang );
			if ( is_wp_error( $prepared ) ) {
				$errors[] = $prepared->get_error_message();
				continue;
			}
			$groups[ $prepared['source_w3c'] ][] = $prepared;
		}

		if ( empty( $groups ) ) {
			wp_send_json_error( array( 'message' => ! empty( $errors ) ? implode( ' ', array_unique( $errors ) ) : __( 'Nothing to quote.', 'supertext-wpml' ) ) );
		}

		$order_type_id = self::order_type_id( $service_id );
		$lang          = Wpml::language( $target_lang );
		$target_w3c    = null !== $lang ? $lang->w3c() : $target_lang;

		/** Optional currency to request; empty lets the account default apply. */
		$currency_req = (string) apply_filters( 'supertext_wpml_quote_currency', '' );

		$currency    = '';
		$symbol      = '';
		$word_count  = 0;
		$group_count = count( $groups );
		$acc         = array(); // DeliveryId => { delivery_id, name, price, date, is_default, seen }.

		foreach ( $groups as $source_w3c => $items ) {
			$files = array();
			foreach ( $items as $item ) {
				$files[] = array( 'Id' => $item['document_id'], 'Comment' => $item['title'] );
			}

			$body = array(
				'OrderTypeConfigurationId' => $service_id,
				'OrderTypeId'              => $order_type_id,
				'SourceLang'               => $source_w3c,
				'TargetLanguages'          => array( $target_w3c ),
				'Files'                    => $files,
			);
			if ( '' !== $currency_req ) {
				$body['Currency'] = $currency_req;
			}

			$quote = $client->get_quote( $body );
			if ( is_wp_error( $quote ) ) {
				wp_send_json_error( array( 'message' => $quote->get_error_message() ) );
			}

			$currency    = (string) ( $quote['Currency'] ?? $currency );
			$symbol      = (string) ( $quote['CurrencySymbol'] ?? $symbol );
			$word_count += (int) ( $quote['WordCount'] ?? 0 );

			$option = ( isset( $quote['Options'][0] ) && is_array( $quote['Options'][0] ) ) ? $quote['Options'][0] : array();
			foreach ( (array) ( $option['DeliveryOptions'] ?? array() ) as $do ) {
				$did = (int) ( $do['DeliveryId'] ?? 0 );
				if ( $did <= 0 ) {
					continue;
				}
				if ( ! isset( $acc[ $did ] ) ) {
					$acc[ $did ] = array(
						'delivery_id' => $did,
						'name'        => (string) ( $do['Name'] ?? '' ),
						'price'       => 0.0,
						'date'        => (string) ( $do['DeliveryDate'] ?? '' ),
						'is_default'  => (bool) ( $do['IsDefaultDeliveryOption'] ?? false ),
						'seen'        => 0,
					);
				}
				$acc[ $did ]['price'] += (float) ( $do['Price'] ?? 0 );
				$acc[ $did ]['seen']  += 1;
				// Across groups, the order isn't done until the latest delivery date.
				$date = (string) ( $do['DeliveryDate'] ?? '' );
				if ( $date > $acc[ $did ]['date'] ) {
					$acc[ $did ]['date'] = $date;
				}
			}
		}

		// Keep only delivery options offered for EVERY source-language group, so the
		// chosen delivery is valid for the whole selection.
		$deliveries = array();
		foreach ( $acc as $did => $delivery ) {
			if ( $delivery['seen'] === $group_count ) {
				unset( $delivery['seen'] );
				$deliveries[ $did ] = $delivery;
			}
		}
		ksort( $deliveries );

		wp_send_json_success(
			array(
				'currency'       => $currency,
				'currencySymbol' => '' !== $symbol ? $symbol : $currency,
				'wordCount'      => $word_count,
				'deliveries'     => array_values( $deliveries ),
				'warnings'       => array_values( array_unique( $errors ) ),
			)
		);
	}

	/**
	 * Renders result / validation notices.
	 *
	 * @return void
	 */
	public static function render_notice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$error   = isset( $_GET['supertext_error'] ) ? sanitize_key( wp_unslash( $_GET['supertext_error'] ) ) : '';
		$action  = isset( $_GET['supertext_action'] ) ? sanitize_key( wp_unslash( $_GET['supertext_action'] ) ) : '';
		$created = isset( $_GET['supertext_created'] ) ? (int) $_GET['supertext_created'] : 0;
		$errors  = isset( $_GET['supertext_errors'] ) ? (int) $_GET['supertext_errors'] : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$messages = array(
			'no_lang'           => array( 'error', __( 'Please select a target language before applying a Supertext translation action.', 'supertext-wpml' ) ),
			'no_service'        => array( 'error', __( 'Please select a translation type before applying Supertext Human Translation.', 'supertext-wpml' ) ),
			'no_delivery'       => array( 'error', __( 'Please select a delivery option before applying Supertext Human Translation.', 'supertext-wpml' ) ),
			'mt_not_configured'    => array( 'error', __( 'No Supertext API key is set. Enter it under Supertext → Settings → AI translation.', 'supertext-wpml' ) ),
			'human_not_configured' => array( 'error', __( 'Supertext human-translation credentials are not configured. Add your account email and Order API Key on the Supertext settings page.', 'supertext-wpml' ) ),
		);

		if ( isset( $messages[ $error ] ) ) {
			printf(
				'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
				esc_attr( $messages[ $error ][0] ),
				esc_html( $messages[ $error ][1] )
			);
			return;
		}

		if ( '' === $action || ( ! $created && ! $errors ) ) {
			return;
		}

		$label = self::ACTION_AI === $action
			? __( 'AI Translation', 'supertext-wpml' )
			: __( 'Human Translation', 'supertext-wpml' );

		if ( $created ) {
			$template = self::ACTION_AI === $action
				/* translators: 1: number of posts, 2: translation type */
				? _n( '%1$d translation created via Supertext %2$s.', '%1$d translations created via Supertext %2$s.', $created, 'supertext-wpml' )
				/* translators: 1: number of posts, 2: translation type */
				: _n( '%1$d order submitted to Supertext %2$s.', '%1$d orders submitted to Supertext %2$s.', $created, 'supertext-wpml' );

			$count_message = sprintf( $template, $created, $label );
			$orders_html   = '';

			if ( self::ACTION_HUMAN === $action ) {
				$order_key = 'supertext_wpml_bulk_orders_' . get_current_user_id();
				$order_ids = get_transient( $order_key );
				delete_transient( $order_key );

				if ( is_array( $order_ids ) && ! empty( $order_ids ) ) {
					$links = array();
					foreach ( $order_ids as $oid ) {
						$oid     = (int) $oid;
						$links[] = sprintf(
							'<a href="%s" target="_blank" rel="noopener">%d</a>',
							esc_url( Settings::order_url( $oid ) ),
							$oid
						);
					}
					$orders_html = ' ' . sprintf(
						/* translators: %s is a comma-separated list of order id links. */
						esc_html( _n( 'Order ID: %s', 'Order IDs: %s', count( $order_ids ), 'supertext-wpml' ) ),
						implode( ', ', $links )
					);
				}
			}

			printf(
				'<div class="notice notice-success is-dismissible"><p>%s%s</p></div>',
				esc_html( $count_message ),
				$orders_html // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- order ids + URLs escaped above.
			);
		}

		if ( $errors ) {
			$detail_key = 'supertext_wpml_bulk_errors_' . get_current_user_id();
			$details    = get_transient( $detail_key );
			delete_transient( $detail_key );

			$list = '';
			if ( is_array( $details ) && ! empty( $details ) ) {
				$items = '';
				foreach ( $details as $message ) {
					$items .= '<li>' . esc_html( $message ) . '</li>';
				}
				$list = '<ul style="list-style:disc;margin-left:1.5em;">' . $items . '</ul>';
			}

			printf(
				'<div class="notice notice-error is-dismissible"><p>%s</p>%s</div>',
				esc_html(
					sprintf(
						/* translators: number of failures */
						_n( '%d item could not be submitted:', '%d items could not be submitted:', $errors, 'supertext-wpml' ),
						$errors
					)
				),
				$list // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each message escaped above.
			);
		}
	}
}
