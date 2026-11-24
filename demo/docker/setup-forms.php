<?php
/**
 * Demo setup, second step, run with `wp eval-file` after setup.php on every container start
 * (idempotent): with WPForms (WPML_WPFORMS_ZIP_URL), a newsletter form on the page "Subscribe".
 *
 * A step of its own because WPML String Translation only starts in a request where WPML was
 * already set up: on a new site, setup.php sets WPML up, so in that same request WPForms
 * Multilingual can't register the form's strings. Here (a new request) it can; with WPML String
 * Translation the form's texts are a string package, listed under Supertext → String translations.
 *
 * @package Supertext\Wpml
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'wpforms' ) ) {
	return;
}

// No WPForms welcome page or setup wizard for the demo's admins (they replace the first admin page).
delete_transient( 'wpforms_activation_redirect' );
delete_transient( 'wpforms_setup_wizard_first_run' );
update_option( 'wpforms_activation_redirect', true );
update_option( 'wpforms_setup_wizard_disabled', true );
// Nor WPForms Multilingual's welcome box ("Your website's forms were automatically detected …").
update_option( 'wpml-forms-welcome-notice-dismiss', true );

$supertext_demo_admins = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	)
);
wp_set_current_user( (int) ( $supertext_demo_admins[0] ?? 1 ) ); // WPForms only saves forms for a user who may.

$supertext_demo_form_id = (int) get_option( 'supertext_demo_wpforms' );
if ( $supertext_demo_form_id <= 0 || ! get_post( $supertext_demo_form_id ) ) {
	$supertext_demo_form    = array(
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
	$supertext_demo_form_id = (int) wpforms()->obj( 'form' )->add( 'Newsletter', array(), array( 'builder' => false ) );
	if ( $supertext_demo_form_id > 0 ) {
		$supertext_demo_form['id'] = $supertext_demo_form_id;
		wpforms()->obj( 'form' )->update( $supertext_demo_form_id, $supertext_demo_form );
		$supertext_demo_page = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_title'   => 'Subscribe',
				'post_content' => "<!-- wp:shortcode -->\n[wpforms id=\"$supertext_demo_form_id\" title=\"false\"]\n<!-- /wp:shortcode -->",
				'post_status'  => 'publish',
				'post_author'  => get_current_user_id(),
			)
		);
		if ( is_int( $supertext_demo_page ) && $supertext_demo_page > 0 && defined( 'ICL_SITEPRESS_VERSION' ) ) {
			do_action(
				'wpml_set_element_language_details',
				array(
					'element_id'    => $supertext_demo_page,
					'element_type'  => 'post_page',
					'trid'          => false,
					'language_code' => (string) apply_filters( 'wpml_default_language', 'en' ),
				)
			);
		}
		update_option( 'supertext_demo_wpforms', $supertext_demo_form_id );
		WP_CLI::log( '[demo] Created the WPForms form "Newsletter" and the page "Subscribe"' );
	}
}

// The form's strings as a WPML string package: what the form builder fires on save, which
// WPForms Multilingual answers. Only when the package is missing (new form, or a demo set up
// before WPML String Translation was running).
if ( $supertext_demo_form_id > 0 && defined( 'WPML_ST_VERSION' ) ) {
	$supertext_demo_registered = false;
	foreach ( (array) apply_filters( 'wpml_pt_all_packages', array() ) as $supertext_demo_package ) {
		if ( is_object( $supertext_demo_package ) && 'WPForms' === ( $supertext_demo_package->kind ?? '' ) && (string) $supertext_demo_form_id === (string) ( $supertext_demo_package->name ?? '' ) ) {
			$supertext_demo_registered = true;
		}
	}
	if ( ! $supertext_demo_registered ) {
		$supertext_demo_data = wpforms()->obj( 'form' )->get( $supertext_demo_form_id, array( 'content_only' => true ) );
		do_action( 'wpforms_builder_save_form', $supertext_demo_form_id, is_array( $supertext_demo_data ) ? $supertext_demo_data : array() );
		WP_CLI::log( '[demo] Registered the strings of the WPForms form "Newsletter" with WPML String Translation' );
	}
}

wp_set_current_user( 0 );
