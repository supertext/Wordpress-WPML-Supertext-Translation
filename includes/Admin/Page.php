<?php
/**
 * @package Supertext_Wpml
 */

namespace Supertext\Wpml\Admin;

defined( 'ABSPATH' ) || exit;

use Supertext\Wpml\Human_Translation\Callback;
use Supertext\Wpml\Machine_Translation\Ai_Settings;
use Supertext\Wpml\Reporting\Commando;
use Supertext\Wpml\Wpml\Wpml;

/**
 * The "Supertext" admin pages: Settings (with the status panel merged in),
 * Orders and Debug.
 *
 * @since 0.1.0
 */
class Page {
	/**
	 * Top-level / Settings page slug.
	 *
	 * @var string
	 */
	const SLUG = 'supertext-wpml';

	/**
	 * Settings page slug. Kept as an alias of {@see self::SLUG} for back-compat
	 * now that Status is merged into the (top-level) Settings page.
	 *
	 * @var string
	 */
	const SETTINGS_SLUG = self::SLUG;

	/**
	 * Debug page slug.
	 *
	 * @var string
	 */
	const DEBUG_SLUG = 'supertext-wpml-debug';

	/**
	 * admin-post action name for clearing the callback log.
	 *
	 * @var string
	 */
	const CLEAR_LOG_ACTION = 'supertext_wpml_clear_callback_log';

	/**
	 * Transient key for one-off admin notices.
	 *
	 * @var string
	 */
	const NOTICE_TRANSIENT = 'supertext_wpml_notice';

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( self::class, 'register_menu' ), 10 );
		add_action( 'admin_menu', array( self::class, 'register_debug_menu' ), 12 );
		add_action( 'admin_post_' . self::CLEAR_LOG_ACTION, array( self::class, 'handle_clear_log' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_style' ) );
	}

	/**
	 * Enqueues the Supertext admin stylesheet on every Supertext screen.
	 *
	 * @return void
	 */
	public static function enqueue_style(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
		if ( 0 !== strpos( $page, self::SLUG ) ) {
			return;
		}
		wp_enqueue_style(
			'supertext-wpml-admin',
			plugins_url( 'assets/css/supertext-admin.css', SUPERTEXT_WPML_FILE ),
			array( 'dashicons' ),
			SUPERTEXT_WPML_VERSION
		);
	}

	/**
	 * Registers the top-level menu and its Settings submenu.
	 *
	 * Status is merged into the Settings page, so the top-level page *is* the
	 * Settings page (the first submenu is just renamed to "Settings").
	 *
	 * @return void
	 */
	public static function register_menu(): void {
		add_menu_page(
			__( 'Supertext', 'supertext-wpml' ),
			__( 'Supertext', 'supertext-wpml' ),
			'manage_options',
			self::SLUG,
			array( self::class, 'render_settings' ),
			self::menu_icon(),
			81
		);

		add_submenu_page(
			self::SLUG,
			__( 'Settings', 'supertext-wpml' ),
			__( 'Settings', 'supertext-wpml' ),
			'manage_options',
			self::SLUG,
			array( self::class, 'render_settings' )
		);
	}

	/**
	 * Registers the Debug submenu (later, so it appears after Orders).
	 *
	 * @return void
	 */
	public static function register_debug_menu(): void {
		add_submenu_page(
			self::SLUG,
			__( 'Debug', 'supertext-wpml' ),
			__( 'Debug', 'supertext-wpml' ),
			'manage_options',
			self::DEBUG_SLUG,
			array( self::class, 'render_debug' )
		);
	}

	/**
	 * Returns the admin-menu icon (base64 SVG data URI).
	 *
	 * @return string
	 */
	private static function menu_icon(): string {
		if ( defined( 'SUPERTEXT_WPML_DIR' ) ) {
			$svg = SUPERTEXT_WPML_DIR . 'assets/Supertext_S_Glow_White_RGB.svg';
			if ( is_readable( $svg ) ) {
				$data = file_get_contents( $svg ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				if ( is_string( $data ) && '' !== $data ) {
					return 'data:image/svg+xml;base64,' . base64_encode( $data ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
				}
			}
		}

		return 'dashicons-translation';
	}

	/**
	 * Clears the recorded callback log.
	 *
	 * @return void
	 */
	public static function handle_clear_log(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'supertext-wpml' ) );
		}
		check_admin_referer( self::CLEAR_LOG_ACTION );

		Callback::clear_log();

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::DEBUG_SLUG ) );
		exit;
	}

	/**
	 * Prints the page header (logo + title).
	 *
	 * @param string $title Page title.
	 * @return void
	 */
	public static function hero( string $title, string $subtitle = '' ): void {
		$logo = defined( 'SUPERTEXT_WPML_FILE' ) ? plugins_url( 'assets/Supertext_S_Glow_Light_Green_RGB.png', SUPERTEXT_WPML_FILE ) : '';
		?>
		<div class="st-hero">
			<?php if ( $logo ) : ?>
				<span class="st-hero__logo"><img src="<?php echo esc_url( $logo ); ?>" width="34" height="34" alt="" /></span>
			<?php endif; ?>
			<div class="st-hero__text">
				<h1 class="st-hero__title"><?php echo esc_html( $title ); ?></h1>
				<?php if ( '' !== $subtitle ) : ?>
					<p class="st-hero__subtitle"><?php echo esc_html( $subtitle ); ?></p>
				<?php endif; ?>
			</div>
			<span class="st-hero__version">
				<?php
				/* translators: %s is the plugin version. */
				printf( esc_html__( 'Version %s', 'supertext-wpml' ), esc_html( defined( 'SUPERTEXT_WPML_VERSION' ) ? SUPERTEXT_WPML_VERSION : '' ) );
				?>
			</span>
		</div>
		<?php
	}

	/**
	 * Prints the status panel (WPML, API key, languages, reports, features).
	 *
	 * @return void
	 */
	private static function render_status_panel(): void {
		$wpml      = Wpml::is_active();
		$languages = $wpml ? count( Wpml::languages() ) : 0;
		$key       = Ai_Settings::has_api_key();
		$all_ok    = $wpml && $languages > 1 && $key;
		?>
		<div class="st-card">
			<div class="st-card__head">
				<span class="st-tile"><span class="dashicons <?php echo esc_attr( $all_ok ? 'dashicons-yes-alt' : 'dashicons-warning' ); ?>"></span></span>
				<div>
					<h2 class="st-card__title"><?php esc_html_e( 'Status', 'supertext-wpml' ); ?></h2>
					<p class="st-card__subtitle">
						<?php
						if ( $all_ok ) {
							esc_html_e( 'Everything is connected and running.', 'supertext-wpml' );
						} else {
							esc_html_e( 'Not everything is set up yet: see the red rows below.', 'supertext-wpml' );
						}
						?>
					</p>
				</div>
			</div>
			<div class="st-rows">
				<?php
				self::status_row(
					__( 'WPML Multilingual CMS', 'supertext-wpml' ),
					$wpml,
					$wpml
						/* translators: %s: WPML version. */
						? sprintf( __( 'Active (version %s)', 'supertext-wpml' ), Wpml::version() )
						: __( 'Not active', 'supertext-wpml' )
				);
				self::status_row(
					__( 'WPML languages', 'supertext-wpml' ),
					$languages > 1,
					$languages > 1
						/* translators: %d: number of languages. */
						? sprintf( _n( '%d language', '%d languages', $languages, 'supertext-wpml' ), $languages )
						: __( 'Add at least two languages under WPML → Languages', 'supertext-wpml' )
				);
				self::status_row(
					__( 'Supertext API key (AI translation)', 'supertext-wpml' ),
					$key,
					$key ? __( 'Set', 'supertext-wpml' ) : __( 'Not set: enter it under AI translation below', 'supertext-wpml' )
				);

				self::render_key_check_row();
				self::render_language_rows();

				$reports = Commando::enabled();
				self::state_row(
					__( 'Send version information to Supertext', 'supertext-wpml' ),
					$reports ? 'ok' : 'off',
					$reports ? __( 'On', 'supertext-wpml' ) : __( 'Off', 'supertext-wpml' )
				);
				self::render_send_extensions_row();

				// Optional features (a disabled feature is a choice, not an error — shown
				// neutral rather than red).
				printf(
					'<div class="st-subhead">%s</div>',
					esc_html__( 'Features', 'supertext-wpml' )
				);

				$preview_on = Settings::preview_links_enabled();
				self::state_row(
					__( 'Secret preview links', 'supertext-wpml' ),
					$preview_on ? 'ok' : 'off',
					$preview_on ? __( 'On', 'supertext-wpml' ) : __( 'Off', 'supertext-wpml' )
				);

				$shots_on = Settings::screenshots_enabled();
				if ( ! $shots_on ) {
					self::state_row( __( 'Page screenshots (VibeBoost)', 'supertext-wpml' ), 'off', __( 'Off', 'supertext-wpml' ) );
				} elseif ( '' === Settings::screenshots_api_key() ) {
					self::state_row( __( 'Page screenshots (VibeBoost)', 'supertext-wpml' ), 'warn', __( 'On — but no VibeBoost API key is set, so captures will fail', 'supertext-wpml' ) );
				} elseif ( $preview_on ) {
					self::state_row( __( 'Page screenshots (VibeBoost)', 'supertext-wpml' ), 'ok', __( 'On', 'supertext-wpml' ) );
				} else {
					self::state_row( __( 'Page screenshots (VibeBoost)', 'supertext-wpml' ), 'warn', __( 'On — but Secret preview links are off, so drafts cannot be captured', 'supertext-wpml' ) );
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Prints the latest automatic API key check (with its message) and the
	 * *Test connection* button.
	 *
	 * @return void
	 */
	private static function render_key_check_row(): void {
		$checks = Commando::last_checks();
		if ( array() === $checks ) {
			self::state_row( __( 'API key check', 'supertext-wpml' ), 'off', __( 'Not checked yet', 'supertext-wpml' ) );
		}
		foreach ( $checks as $name => $check ) {
			$message = Commando::message( $check, true, $name );
			$state   = 'success' === $message['type'] ? 'ok' : ( 'error' === $message['type'] ? 'bad' : 'warn' );
			/* translators: %s: which key, e.g. "AI translation (API key)". */
			$label = sprintf( __( 'API key check: %s', 'supertext-wpml' ), Commando::key_label( $name ) );
			if ( 'ok' === $state ) {
				// One line: the badge says it works; the full message is the tooltip.
				/* translators: %s: when it was checked, e.g. "checked November 11, 2026 11:00 am". */
				self::state_row( $label, $state, sprintf( __( 'Works · %s', 'supertext-wpml' ), Commando::checked_at( $check ) ), wp_strip_all_tags( $message['text'] ) );
				continue;
			}
			// A problem keeps its explanation (with the links to fix it) below the row.
			self::state_row( $label, $state, Commando::checked_at( $check ) );
			printf(
				'<div class="st-row st-row--note"><span class="st-row__label">%s</span></div>',
				wp_kses( $message['text'], array( 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array() ) ) )
			);
		}
		?>
		<div class="st-row">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( Commando::TEST_ACTION ); ?>" />
				<?php wp_nonce_field( Commando::TEST_ACTION ); ?>
				<?php submit_button( __( 'Test connection', 'supertext-wpml' ), 'secondary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Prints the *Send configuration to Supertext* button with its help text.
	 *
	 * @return void
	 */
	private static function render_send_extensions_row(): void {
		?>
		<div class="st-row">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( Commando::EXTENSIONS_ACTION ); ?>" />
				<?php wp_nonce_field( Commando::EXTENSIONS_ACTION ); ?>
				<?php submit_button( __( 'Send configuration to Supertext', 'supertext-wpml' ), 'secondary', 'submit', false ); ?>
				<p class="description"><?php esc_html_e( 'Sends Supertext the plugins and themes installed on this site (name, version, active or not) and its Supertext language mappings, so Supertext support can help better. Nothing else is sent.', 'supertext-wpml' ); ?></p>
			</form>
		</div>
		<?php
	}

	/**
	 * Prints one row per WPML language with its Supertext language, or that it isn't
	 * mapped (translations into or from it stop with unsupported_language).
	 *
	 * @return void
	 */
	private static function render_language_rows(): void {
		$languages = Wpml::languages();
		if ( array() === $languages ) {
			return;
		}

		printf( '<div class="st-subhead">%s</div>', esc_html__( 'Languages (Supertext language mapping)', 'supertext-wpml' ) );
		foreach ( $languages as $language ) {
			$label = $language->name . ' (' . $language->locale . ')';
			$code  = Ai_Settings::target_or_null( $language );
			if ( null !== $code ) {
				self::state_row( $label, 'ok', $code );
			} else {
				self::state_row( $label, 'bad', __( 'Not mapped: choose its Supertext language under AI translation below', 'supertext-wpml' ) );
			}
		}
	}

	/**
	 * Prints a tri-state status row (for optional features / integrations, where
	 * "off" is a neutral choice rather than an error).
	 *
	 * @param string $label Row label.
	 * @param string $state One of 'ok' (green ✓), 'warn' (amber ⚠), 'off' (grey •).
	 * @param string $text  Status text.
	 * @param string $title Optional tooltip for the badge.
	 * @return void
	 */
	private static function state_row( string $label, string $state, string $text, string $title = '' ): void {
		$map = array(
			'ok'   => array( '', 'dashicons-yes' ),
			'warn' => array( '--warn', 'dashicons-warning' ),
			'off'  => array( '--off', 'dashicons-minus' ),
			'bad'  => array( '--bad', 'dashicons-no-alt' ),
		);
		list( $modifier, $icon ) = $map[ $state ] ?? $map['off'];
		printf(
			'<div class="st-row"><span class="st-row__label">%s</span><span class="st-badge st-badge%s"%s><span class="dashicons %s"></span>%s</span></div>',
			esc_html( $label ),
			esc_attr( $modifier ),
			'' !== $title ? ' title="' . esc_attr( $title ) . '"' : '',
			esc_attr( $icon ),
			esc_html( $text )
		);
	}

	/**
	 * Settings page: status panel, AI translation settings and human
	 * translation services.
	 *
	 * @return void
	 */
	public static function render_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// One-off notice.
		$notice_key = self::NOTICE_TRANSIENT . '_' . get_current_user_id();
		$notice     = get_transient( $notice_key );
		$tested     = Commando::take_test_result();
		foreach ( $tested ?? array() as $name => $check ) {
			$message = Commando::message( $check, true, $name );
			printf(
				'<div class="notice notice-%s is-dismissible"><p><strong>%s:</strong> %s</p></div>',
				esc_attr( $message['type'] ),
				esc_html( Commando::key_label( $name ) ),
				wp_kses( $message['text'], array( 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array() ) ) )
			);
		}
		$sent = Commando::take_extensions_result();
		if ( null !== $sent ) {
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $sent['type'] ), esc_html( $sent['text'] ) );
		}
		if ( is_array( $notice ) ) {
			delete_transient( $notice_key );
			printf(
				'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
				esc_attr( 'error' === $notice['type'] ? 'error' : 'success' ),
				esc_html( $notice['text'] )
			);
		}
		?>
		<div class="wrap supertext-admin st-narrow">
			<?php
			self::hero(
				__( 'Supertext for WPML', 'supertext-wpml' ),
				__( 'AI & professional translation, wired straight into WPML.', 'supertext-wpml' )
			);
			self::render_status_panel();
			?>

			<div class="st-card" id="supertext-ai">
				<div class="st-card__head">
					<span class="st-tile"><span class="dashicons dashicons-translation"></span></span>
					<div>
						<h2 class="st-card__title"><?php esc_html_e( 'AI translation', 'supertext-wpml' ); ?></h2>
						<p class="st-card__subtitle"><?php esc_html_e( 'Enter your Supertext API key and check the Supertext language of each WPML language. Then translate posts and pages with the “Supertext AI Translation” bulk action or the Supertext box in the editor.', 'supertext-wpml' ); ?></p>
					</div>
				</div>
				<?php
				settings_errors( Ai_Settings::OPTION );
				Ai_Settings::render_form();
				?>
			</div>

			<div class="st-card">
				<div class="st-card__head">
					<span class="st-tile"><span class="dashicons dashicons-groups"></span></span>
					<div>
						<h2 class="st-card__title"><?php esc_html_e( 'Translation Services (human)', 'supertext-wpml' ); ?></h2>
						<p class="st-card__subtitle"><?php esc_html_e( 'Professional (human) translation orders use a separate credential from the AI translation API key. Enter your Supertext account email and Order API Key below.', 'supertext-wpml' ); ?></p>
					</div>
				</div>
				<?php
				settings_errors( Settings::GROUP );
				Settings::render_form();
				?>
			</div>

		</div>
		<?php
	}

	/**
	 * Debug page: order callback log.
	 *
	 * @return void
	 */
	public static function render_debug(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap supertext-admin st-narrow">
			<?php
			self::hero(
				__( 'Supertext Debug', 'supertext-wpml' ),
				__( 'Order-callback log & diagnostics.', 'supertext-wpml' )
			);
			?>
			<div class="st-card">
				<?php self::render_callback_log(); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Renders the recorded order-callback payloads.
	 *
	 * @return void
	 */
	private static function render_callback_log(): void {
		$entries = Callback::get_log();
		?>
		<h2 style="margin-top:1em;"><?php esc_html_e( 'Order callbacks (debug)', 'supertext-wpml' ); ?></h2>
		<p class="description" style="max-width:640px;">
			<?php esc_html_e( 'The most recent payload Supertext POSTed to the callback URL is recorded here, so Supertext support can see exactly what the system sends when an order completes.', 'supertext-wpml' ); ?>
		</p>
		<p><code><?php echo esc_html( Callback::url() ); ?></code></p>

		<?php if ( empty( $entries ) ) : ?>
			<p><em><?php esc_html_e( 'No callbacks recorded yet.', 'supertext-wpml' ); ?></em></p>
		<?php else : ?>
			<?php foreach ( $entries as $entry ) : ?>
				<div style="margin:1em 0;padding:8px 12px;border:1px solid #dcdcde;background:#fff;max-width:900px;">
					<p style="margin:0 0 6px;">
						<strong><?php echo esc_html( (string) ( $entry['time'] ?? '' ) ); ?></strong>
						— <?php echo esc_html( (string) ( $entry['method'] ?? '' ) ); ?>
						<?php echo esc_html( (string) ( $entry['content_type'] ?? '' ) ); ?>
					</p>
					<pre style="white-space:pre-wrap;word-break:break-word;max-height:320px;overflow:auto;margin:0;"><?php echo esc_html( self::pretty_json( (string) ( $entry['body'] ?? '' ) ) ); ?></pre>
				</div>
			<?php endforeach; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::CLEAR_LOG_ACTION ); ?>" />
				<?php wp_nonce_field( self::CLEAR_LOG_ACTION ); ?>
				<?php submit_button( __( 'Clear callback log', 'supertext-wpml' ), 'secondary', 'submit', false ); ?>
			</form>
		<?php endif; ?>
		<?php
	}

	/**
	 * Pretty-prints a JSON string if possible.
	 *
	 * @param string $raw Raw body.
	 * @return string
	 */
	private static function pretty_json( string $raw ): string {
		$decoded = json_decode( $raw, true );
		if ( null === $decoded && JSON_ERROR_NONE !== json_last_error() ) {
			return $raw;
		}
		$pretty = wp_json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return false !== $pretty ? $pretty : $raw;
	}

	/**
	 * Prints a status table row with a coloured dot.
	 *
	 * @param string $label Row label.
	 * @param bool   $ok    Whether the state is good.
	 * @param string $text  Status text.
	 * @return void
	 */
	private static function status_row( string $label, bool $ok, string $text ): void {
		printf(
			'<div class="st-row"><span class="st-row__label">%s</span><span class="st-badge %s"><span class="dashicons %s"></span>%s</span></div>',
			esc_html( $label ),
			esc_attr( $ok ? '' : 'st-badge--bad' ),
			esc_attr( $ok ? 'dashicons-yes' : 'dashicons-no-alt' ),
			esc_html( $text )
		);
	}
}
