<?php
/**
 * Demo setup, run with `wp eval-file` on every container start (idempotent):
 * WPML languages, the demo accounts (created once, never changed), the Supertext settings from the variables,
 * and a few English pages and posts to translate.
 *
 * @package Supertext\Wpml
 */

defined( 'ABSPATH' ) || exit;

/**
 * Prints a progress line.
 *
 * @param string $msg Message.
 */
function supertext_demo_log( string $msg ): void {
	WP_CLI::log( '[demo] ' . $msg );
}

// ---------------------------------------------------------------------------
// Languages: English (default), German, French, Italian.
// ---------------------------------------------------------------------------
// WPML has no public API for its setup, so this uses SitePress's own methods (best
// effort: every step is checked and a failure is logged, the demo still starts). Once
// WPML's setup is complete, it is left alone.
global $sitepress;
if ( is_object( $sitepress ) && class_exists( 'SitePress' ) && $sitepress instanceof SitePress ) {
	try {
		if ( ! $sitepress->get_setting( 'setup_complete' ) ) {
			if ( function_exists( 'icl_sitepress_activate' ) ) {
				icl_sitepress_activate();
			}
			if ( method_exists( $sitepress, 'set_default_language' ) ) {
				$sitepress->set_default_language( 'en' );
			}
			if ( method_exists( $sitepress, 'set_active_languages' ) ) {
				$sitepress->set_active_languages( array( 'en', 'de', 'fr', 'it' ) );
			}
			$sitepress->set_setting( 'custom_posts_sync_option', array( 'post' => 1, 'page' => 1 ) );
			$sitepress->set_setting( 'taxonomies_sync_option', array( 'category' => 1, 'post_tag' => 1 ) );
			$sitepress->set_setting( 'setup_complete', 1 );
			$sitepress->set_setting( 'setup_wizard_step', 0 );
			$sitepress->save_settings();
			supertext_demo_log( 'Set up WPML: English (default), German, French, Italian' );
		}
	} catch ( \Throwable $e ) {
		supertext_demo_log( 'WPML setup failed: ' . $e->getMessage() );
	}
} else {
	supertext_demo_log( 'WPML is not loaded (set WPML_ZIP_URL), skipping languages' );
}

// ---------------------------------------------------------------------------
// Accounts from the variables, created once. Existing accounts are never changed
// (no password resets from variables); the log names the variable, never the password.
// ---------------------------------------------------------------------------
/**
 * Creates a demo user if no account with that e-mail exists yet.
 *
 * @param string $login    Preferred login name (the e-mail if it is taken).
 * @param string $prefix   Variable prefix, e.g. DEMO_ADMIN.
 * @param string $role     Role.
 */
function supertext_demo_user( string $login, string $prefix, string $role ): void {
	$email    = (string) getenv( $prefix . '_EMAIL' );
	$password = (string) getenv( $prefix . '_PASSWORD' );
	if ( '' === $email || '' === $password ) {
		return;
	}
	if ( get_user_by( 'email', $email ) ) {
		supertext_demo_log( "{$prefix}_EMAIL: account exists, left unchanged" );
		return;
	}
	$id = wp_insert_user(
		array(
			'user_login' => username_exists( $login ) ? $email : $login,
			'user_email' => $email,
			'user_pass'  => $password,
			'role'       => $role,
		)
	);
	supertext_demo_log( is_wp_error( $id ) ? "{$prefix}_EMAIL: could not create the account: " . $id->get_error_message() : "{$prefix}_EMAIL: created the $role account" );
}

supertext_demo_user( 'admin', 'DEMO_ADMIN', 'administrator' );
supertext_demo_user( 'editor', 'DEMO_EDITOR', 'editor' );

// ---------------------------------------------------------------------------
// Supertext settings: the AI key comes from SUPERTEXT_API_KEY directly (used while the
// field on the settings page is empty). The professional translation settings are
// seeded from the variables only while still empty, so changes made there stay.
// ---------------------------------------------------------------------------
$settings = get_option( 'supertext_wpml_settings', array() );
$settings = is_array( $settings ) ? $settings : array();
$changed  = false;
foreach (
	array(
		'human_api_key' => 'SUPERTEXT_ORDER_API_KEY',
		'human_email'   => 'SUPERTEXT_ACCOUNT_EMAIL',
		'environment'   => 'SUPERTEXT_ENVIRONMENT',
	) as $key => $env
) {
	$value = (string) getenv( $env );
	if ( '' !== $value && empty( $settings[ $key ] ) ) {
		$settings[ $key ] = $value;
		$changed          = true;
	}
}
if ( $changed ) {
	update_option( 'supertext_wpml_settings', $settings );
	supertext_demo_log( 'Seeded the Supertext settings from the variables' );
}

// ---------------------------------------------------------------------------
// Sample content in English, created once.
// ---------------------------------------------------------------------------
if ( ! get_option( 'supertext_demo_content' ) ) {
	wp_delete_post( 1, true ); // "Hello world!"
	wp_delete_post( 2, true ); // "Sample Page"

	$content = array(
		array(
			'post_type'    => 'page',
			'post_title'   => 'Welcome to the Supertext demo',
			'post_content' => "<!-- wp:paragraph -->\n<p>This WordPress site runs WPML and the Supertext plugin. Write in English, then let Supertext translate the page into German, French and Italian — with AI in seconds, or by professional translators.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">Why Supertext?</h2>\n<!-- /wp:heading -->\n\n<!-- wp:list -->\n<ul class=\"wp-block-list\"><li>Swiss quality since 2005</li><li>AI translation trained on your terminology</li><li>Human review when it matters</li></ul>\n<!-- /wp:list -->",
			'front'        => true,
		),
		array(
			'post_type'    => 'page',
			'post_title'   => 'About us',
			'post_content' => "<!-- wp:paragraph -->\n<p>We are a small team from Zurich that loves good language. Our products help companies speak to their customers in every language they need, without losing their voice.</p>\n<!-- /wp:paragraph -->",
		),
		array(
			'post_type'    => 'page',
			'post_title'   => 'Contact',
			'post_content' => "<!-- wp:paragraph -->\n<p>Questions about the demo? Write to us at hello@supertext.com or call us during office hours. We usually answer within one working day.</p>\n<!-- /wp:paragraph -->",
		),
		array(
			'post_type'    => 'post',
			'post_title'   => 'Five tips for translating your website',
			'post_content' => "<!-- wp:paragraph -->\n<p>Start with the pages your visitors see most. Keep sentences short and clear. Agree on key terms before you translate. Let a native speaker review headlines and calls to action. And plan updates: a translated site is never finished.</p>\n<!-- /wp:paragraph -->",
		),
		array(
			'post_type'    => 'post',
			'post_title'   => 'Swiss German, Standard German or both?',
			'post_content' => "<!-- wp:paragraph -->\n<p>Many Swiss companies write in Standard German with Swiss spelling — no ß, and words like Velo instead of Fahrrad. For marketing, a touch of Swiss German can make all the difference.</p>\n<!-- /wp:paragraph -->",
		),
	);

	foreach ( $content as $item ) {
		$id = wp_insert_post(
			array(
				'post_type'    => $item['post_type'],
				'post_title'   => $item['post_title'],
				'post_content' => $item['post_content'],
				'post_status'  => 'publish',
				'post_author'  => 1,
			)
		);
		if ( $id && ! is_wp_error( $id ) ) {
			do_action(
				'wpml_set_element_language_details',
				array(
					'element_id'    => $id,
					'element_type'  => 'post_' . $item['post_type'],
					'trid'          => false,
					'language_code' => 'en',
				)
			);
			if ( ! empty( $item['front'] ) ) {
				update_option( 'show_on_front', 'page' );
				update_option( 'page_on_front', $id );
			}
		}
	}
	update_option( 'supertext_demo_content', 1 );
	supertext_demo_log( 'Created the sample content' );
}

// ---------------------------------------------------------------------------
// With WPML: posts and pages without a language (e.g. the sample content, created
// before WPML was installed) become English. Runs on every start, changes nothing twice.
// ---------------------------------------------------------------------------
if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
	$assigned = 0;
	foreach ( get_posts( array( 'post_type' => array( 'post', 'page' ), 'post_status' => 'any', 'numberposts' => -1, 'suppress_filters' => true, 'fields' => 'ids' ) ) as $id ) {
		$type    = (string) get_post_type( $id );
		$details = apply_filters( 'wpml_element_language_details', null, array( 'element_id' => $id, 'element_type' => $type ) );
		if ( empty( $details ) || empty( ( (array) $details )['language_code'] ) ) {
			do_action( 'wpml_set_element_language_details', array( 'element_id' => $id, 'element_type' => 'post_' . $type, 'trid' => false, 'language_code' => 'en' ) );
			++$assigned;
		}
	}
	if ( $assigned > 0 ) {
		supertext_demo_log( "Set the language of $assigned posts and pages to English" );
	}
}
