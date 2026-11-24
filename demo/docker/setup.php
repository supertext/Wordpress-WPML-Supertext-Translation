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
// WPML has no public API for its setup, so this runs the steps of WPML's own setup
// wizard (WPML_Installation, as in WPML 5.3): default language, active languages, site
// key, finish. Best effort: a failure is logged, the demo still starts. Runs only while
// WPML has no active languages, so changes made in WPML's settings stay.
global $sitepress;
if ( is_object( $sitepress ) && class_exists( 'SitePress' ) && $sitepress instanceof SitePress && function_exists( 'wpml_get_setup_instance' ) ) {
	try {
		if ( array() === (array) $sitepress->get_active_languages( true ) ) {
			$installation = wpml_get_setup_instance();
			$installation->finish_step1( 'en' );
			$installation->finish_step2( array( 'en', 'de', 'fr', 'it' ) );
			if ( getenv( 'WPML_SITE_KEY' ) ) {
				$installation->store_site_key( (string) getenv( 'WPML_SITE_KEY' ) );
			}
			$sitepress->set_setting( 'custom_posts_sync_option', array( 'post' => 1, 'page' => 1 ) );
			$sitepress->set_setting( 'taxonomies_sync_option', array( 'category' => 1, 'post_tag' => 1 ) );
			$sitepress->save_settings();
			$installation->finish_installation();
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
		// The keys need no seeding: SUPERTEXT_API_KEY, SUPERTEXT_ACCOUNT_EMAIL,
		// SUPERTEXT_ORDER_API_KEY and VIBEBOOST_API_KEY are read by the plugin itself.
		'environment' => 'SUPERTEXT_ENVIRONMENT',
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
// With ACF PRO (ACF_ZIP_URL): a page built from the demo's ACF block "Text"
// (acf-demo-block.php), its text in the block's data like on real ACF sites. Created once.
// ---------------------------------------------------------------------------
if ( function_exists( 'acf_register_block_type' ) && ! get_option( 'supertext_demo_acf_content' ) ) {
	$block = serialize_block(
		array(
			'blockName'    => 'acf/text',
			'attrs'        => array(
				'name' => 'acf/text',
				'data' => array(
					'text'                   => "<h2>Stay in the loop</h2>\r\n<p>Subscribe to our newsletter and get translation tips once a month.</p>",
					'_text'                  => 'field_st_demo_text',
					'remove_spacing_top'     => '0',
					'_remove_spacing_top'    => 'field_st_demo_spacing_top',
					'remove_spacing_bottom'  => '1',
					'_remove_spacing_bottom' => 'field_st_demo_spacing_bottom',
				),
				'mode' => 'edit',
			),
			'innerBlocks'  => array(),
			'innerHTML'    => '',
			'innerContent' => array(),
		)
	);
	$id    = wp_insert_post(
		wp_slash(
			array(
				'post_type'    => 'page',
				'post_title'   => 'Newsletter',
				'post_content' => $block . "\n\n<!-- wp:paragraph -->\n<p>Why Supertext?</p>\n<!-- /wp:paragraph -->",
				'post_status'  => 'publish',
				'post_author'  => 1,
			)
		)
	);
	if ( $id && ! is_wp_error( $id ) ) {
		update_option( 'supertext_demo_acf_content', $id );
		supertext_demo_log( 'Created the ACF block page "Newsletter"' );
	}
}

// ---------------------------------------------------------------------------
// With WPForms (WPML_WPFORMS_ZIP_URL): a newsletter form on the page "Subscribe". With
// WPML String Translation and WPForms Multilingual, its texts are a WPML string package,
// listed under Supertext → String translations. Created once.
// ---------------------------------------------------------------------------
if ( function_exists( 'wpforms' ) && ! get_option( 'supertext_demo_wpforms' ) ) {
	$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
	wp_set_current_user( (int) ( $admins[0] ?? 1 ) ); // WPForms only saves forms for a user who may.
	$form = array(
		'fields'   => array(
			'1' => array( 'id' => '1', 'type' => 'name', 'label' => 'Name', 'format' => 'simple', 'required' => '1', 'placeholder' => 'Your name' ),
			'2' => array( 'id' => '2', 'type' => 'email', 'label' => 'Email', 'required' => '1', 'placeholder' => 'you@example.com', 'description' => 'We never share your address.' ),
			'3' => array( 'id' => '3', 'type' => 'checkbox', 'label' => 'What would you like to receive?', 'choices' => array( '1' => array( 'label' => 'Newsletter', 'value' => '' ), '2' => array( 'label' => 'Free magazine by post', 'value' => '' ) ) ),
		),
		'field_id' => 4,
		'settings' => array(
			'form_title'             => 'Newsletter',
			'submit_text'            => 'Subscribe',
			'submit_text_processing' => 'Sending...',
			'confirmations'          => array( '1' => array( 'type' => 'message', 'message' => '<p>Thank you! Please confirm your address in the e-mail we just sent you.</p>' ) ),
		),
		'meta'     => array( 'template' => 'blank' ),
	);
	$form_id = (int) wpforms()->obj( 'form' )->add( 'Newsletter', array(), array( 'builder' => false ) );
	if ( $form_id > 0 ) {
		$form['id'] = $form_id;
		wpforms()->obj( 'form' )->update( $form_id, $form );
		// What the form builder fires on save; WPForms Multilingual registers the strings on it.
		do_action( 'wpforms_builder_save_form', $form_id, $form );
		$page = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_title'   => 'Subscribe',
				'post_content' => "<!-- wp:shortcode -->\n[wpforms id=\"$form_id\" title=\"false\"]\n<!-- /wp:shortcode -->",
				'post_status'  => 'publish',
				'post_author'  => get_current_user_id(),
			)
		);
		update_option( 'supertext_demo_wpforms', $form_id );
		supertext_demo_log( 'Created the WPForms form "Newsletter" and the page "Subscribe"' . ( is_int( $page ) && $page > 0 ? '' : ' (page failed)' ) );
	}
	wp_set_current_user( 0 );
}
// No WPForms welcome page or setup wizard for the demo's admins (they replace the first admin page).
if ( function_exists( 'wpforms' ) ) {
	delete_transient( 'wpforms_activation_redirect' );
	delete_transient( 'wpforms_setup_wizard_first_run' );
	update_option( 'wpforms_activation_redirect', true );
	update_option( 'wpforms_setup_wizard_disabled', true );
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
