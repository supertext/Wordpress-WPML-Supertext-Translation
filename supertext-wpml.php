<?php
/**
 * Plugin Name:       Supertext for WPML
 * Plugin URI:        https://github.com/supertext/Wordpress-WPML-Supertext-Translation
 * Description:       Translates posts and pages into your WPML languages with Supertext AI, orders professional (human) translations, and exports and imports translation files.
 * Version:           1.0.1
 * Requires at least: 6.4
 * Requires PHP:      8.2
 * Author:            Supertext
 * Author URI:        https://www.supertext.com
 * Text Domain:       supertext-wpml
 * License:           GPL-2.0-or-later
 *
 * @package Supertext_Wpml
 */

defined( 'ABSPATH' ) || exit;

define( 'SUPERTEXT_WPML_VERSION', '1.0.1' );
define( 'SUPERTEXT_WPML_FILE', __FILE__ );
define( 'SUPERTEXT_WPML_DIR', plugin_dir_path( __FILE__ ) );

/**
 * Minimal PSR-4 autoloader for the `Supertext\Wpml\` namespace (includes/).
 */
spl_autoload_register(
	function ( string $class ): void {
		$prefix = 'Supertext\\Wpml\\';
		if ( strpos( $class, $prefix ) !== 0 ) {
			return;
		}

		$relative = substr( $class, strlen( $prefix ) );
		$path     = SUPERTEXT_WPML_DIR . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $path ) ) {
			require $path;
		}
	}
);

/**
 * Loads the plugin's own translations (languages/supertext-wpml-<locale>.mo:
 * German, French, Italian). A regional locale without its own file uses its
 * language's (de_CH, de_DE_formal → de_DE; fr_CH → fr_FR; it_CH → it_IT), so the
 * interface follows each user's back-end language. A translation installed in
 * wp-content/languages/plugins wins.
 */
add_action(
	'init',
	function (): void {
		$locale = determine_locale();
		$dir    = SUPERTEXT_WPML_DIR . 'languages/';
		$files  = array( $dir . 'supertext-wpml-' . $locale . '.mo' );
		$base   = array(
			'de' => 'de_DE',
			'fr' => 'fr_FR',
			'it' => 'it_IT',
		);
		$lang   = strtolower( substr( $locale, 0, 2 ) );
		if ( isset( $base[ $lang ] ) ) {
			$files[] = $dir . 'supertext-wpml-' . $base[ $lang ] . '.mo';
		}
		foreach ( $files as $file ) {
			if ( is_readable( $file ) ) {
				load_textdomain( 'supertext-wpml', $file, $locale );
				return;
			}
		}
	}
);

// Version reports, the automatic API key check and the public ping (Supertext Commando Center).
\Supertext\Wpml\Reporting\Commando::init();

// The "Supertext" admin page (status, AI translation settings, professional translation settings).
\Supertext\Wpml\Admin\Page::init();

/**
 * Adds a "Settings" link to the plugin's row on the Plugins screen.
 */
add_filter(
	'plugin_action_links_' . plugin_basename( SUPERTEXT_WPML_FILE ),
	function ( array $links ): array {
		$settings = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=' . \Supertext\Wpml\Admin\Page::SETTINGS_SLUG ) ),
			esc_html__( 'Settings', 'supertext-wpml' )
		);
		array_unshift( $links, $settings );
		return $links;
	}
);

// AI translation settings (API key, environment, language mapping).
\Supertext\Wpml\Machine_Translation\Ai_Settings::init();

// Professional (human) translation settings (account email, Order API Key, environment, …).
\Supertext\Wpml\Admin\Settings::init();

// The "Orders" submenu (list / refresh / cancel human-translation orders).
\Supertext\Wpml\Admin\Orders_Page::init();

// The "Translation files" submenu and the "Export for translation" row action (XLIFF / HTML export and import).
\Supertext\Wpml\Admin\Files_Page::init();

// Human translation product types, loaded from the configured Supertext ServiceId
// (flushes its cache when the settings change).
\Supertext\Wpml\Human_Translation\Services::init();

// The "Supertext AI/Human Translation" bulk actions in the posts and pages lists.
\Supertext\Wpml\Admin\Bulk_Actions::init();

// The Supertext box in the post editor (AI translation per language).
\Supertext\Wpml\Admin\Editor_Box::init();

// The REST callback Supertext calls when a human-translation order is done.
\Supertext\Wpml\Human_Translation\Callback::init();

// Write completed human-translation orders back into the WPML translations.
\Supertext\Wpml\Human_Translation\Writeback::init();

// Block deleting a source post while it has an in-progress human-translation order.
\Supertext\Wpml\Human_Translation\Source_Lock::init();

// Secret-link public preview of unpublished posts/pages (on/off + expiry per post).
\Supertext\Wpml\Preview\Draft_Preview::init();

/**
 * Warns in the admin if WPML is missing (without it, translating does nothing).
 */
add_action(
	'admin_notices',
	function (): void {
		if ( ! current_user_can( 'activate_plugins' ) || \Supertext\Wpml\Wpml\Wpml::is_active() ) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'Supertext for WPML requires WPML Multilingual CMS to be active.', 'supertext-wpml' )
		);
	}
);
