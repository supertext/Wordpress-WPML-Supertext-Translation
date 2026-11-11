<?php
/**
 * Plugin Name: WPML stand-in (tests only)
 * Description: Emulates the public WPML hooks Supertext for WPML uses, for tests and screenshots on a site without a WPML licence. Never on a real site.
 *
 * Copy to wp-content/mu-plugins/. Languages: English (default), German (de_CH), French, Italian.
 * Storage: the option fake_icl (element type:id => trid, language, source language).
 * It covers only the hooks the plugin calls (see includes/Wpml/Wpml.php), not WPML's UI.
 *
 * @package Supertext_Wpml
 */

if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
	return; // The real WPML is active.
}

define( 'ICL_SITEPRESS_VERSION', '0.0.0-stand-in' );

function fake_icl_get(): array {
	$v = get_option( 'fake_icl', array() );
	return is_array( $v ) ? $v : array();
}
function fake_icl_set( array $v ): void {
	update_option( 'fake_icl', $v, false );
}
function fake_icl_type( string $type ): string {
	if ( str_starts_with( $type, 'post_' ) || str_starts_with( $type, 'tax_' ) ) {
		return $type;
	}
	return taxonomy_exists( $type ) ? 'tax_' . $type : 'post_' . $type;
}
$GLOBALS['fake_icl_current'] = 'en';

add_filter(
	'wpml_active_languages',
	static function () {
		return array(
			'en' => array( 'code' => 'en', 'native_name' => 'English', 'translated_name' => 'English', 'default_locale' => 'en_US' ),
			'de' => array( 'code' => 'de', 'native_name' => 'Deutsch', 'translated_name' => 'German', 'default_locale' => 'de_CH' ),
			'fr' => array( 'code' => 'fr', 'native_name' => 'Français', 'translated_name' => 'French', 'default_locale' => 'fr_FR' ),
			'it' => array( 'code' => 'it', 'native_name' => 'Italiano', 'translated_name' => 'Italian', 'default_locale' => 'it_IT' ),
		);
	}
);
add_filter( 'wpml_default_language', static fn() => 'en' );
add_filter( 'wpml_current_language', static fn() => $GLOBALS['fake_icl_current'] );
add_action( 'wpml_switch_language', static function ( $code ) { $GLOBALS['fake_icl_current'] = $code; } );
add_filter( 'wpml_is_translated_post_type', static fn( $v, $type ) => in_array( $type, array( 'post', 'page' ), true ), 10, 2 );
add_filter( 'wpml_is_translated_taxonomy', static fn( $v, $tax ) => in_array( $tax, array( 'category', 'post_tag' ), true ), 10, 2 );
add_filter(
	'wpml_setting',
	static function ( $v, $key ) {
		if ( 'translation-management' !== $key ) {
			return $v;
		}
		return array(
			'custom_fields_translation' => array(
				'_yoast_wpseo_title' => 2,
				'_yoast_wpseo_metadesc' => 2,
				'_thumbnail_id' => 1,
			),
		);
	},
	10,
	2
);
add_filter(
	'wpml_element_language_details',
	static function ( $v, $args ) {
		$key = fake_icl_type( (string) $args['element_type'] ) . ':' . (int) $args['element_id'];
		$row = fake_icl_get()[ $key ] ?? null;
		if ( null === $row ) {
			return null;
		}
		return (object) array(
			'trid'                 => $row['trid'],
			'language_code'        => $row['lang'],
			'source_language_code' => $row['source'],
			'element_id'           => (int) $args['element_id'],
		);
	},
	10,
	2
);
add_filter(
	'wpml_get_element_translations',
	static function ( $v, $trid, $type ) {
		$out = array();
		foreach ( fake_icl_get() as $key => $row ) {
			list( $t, $id ) = explode( ':', $key );
			if ( $t === $type && (int) $row['trid'] === (int) $trid ) {
				$out[ $row['lang'] ] = (object) array( 'element_id' => (int) $id, 'language_code' => $row['lang'], 'trid' => $row['trid'] );
			}
		}
		return $out;
	},
	10,
	3
);
add_filter(
	'wpml_object_id',
	static function ( $id, $type, $return_original, $lang ) {
		if ( ! taxonomy_exists( $type ) ) {
			return null;
		}
		$term = get_term( (int) $id, $type );
		if ( ! $term instanceof WP_Term ) {
			return null;
		}
		$rows = fake_icl_get();
		$row  = $rows[ 'tax_' . $type . ':' . $term->term_taxonomy_id ] ?? null;
		if ( null === $row ) {
			return null;
		}
		foreach ( $rows as $key => $r ) {
			list( $t, $ttid ) = explode( ':', $key );
			if ( $t === 'tax_' . $type && $r['trid'] === $row['trid'] && $r['lang'] === $lang ) {
				$tr = get_term_by( 'term_taxonomy_id', (int) $ttid, $type );
				return $tr instanceof WP_Term ? (int) $tr->term_id : null;
			}
		}
		return null;
	},
	10,
	4
);
add_action(
	'wpml_set_element_language_details',
	static function ( $args ) {
		$rows = fake_icl_get();
		$type = (string) $args['element_type'];
		$trid = $args['trid'];
		if ( ! $trid ) {
			$trid = 1 + max( array_merge( array( 0 ), array_map( static fn( $r ) => (int) $r["trid"], $rows ) ) );
		}
		$rows[ $type . ':' . (int) $args['element_id'] ] = array(
			'trid'   => (int) $trid,
			'lang'   => (string) $args['language_code'],
			'source' => $args['source_language_code'] ?? null,
		);
		fake_icl_set( $rows );
	}
);

// Posts and terms without a language are English.
add_action(
	'save_post',
	static function ( $id, $post ) {
		if ( in_array( $post->post_type, array( 'post', 'page' ), true ) && ! isset( fake_icl_get()[ 'post_' . $post->post_type . ':' . $id ] ) ) {
			do_action( 'wpml_set_element_language_details', array( 'element_id' => $id, 'element_type' => 'post_' . $post->post_type, 'trid' => false, 'language_code' => in_array( $GLOBALS['fake_icl_current'], array( 'en', 'de', 'fr', 'it' ), true ) ? $GLOBALS['fake_icl_current'] : 'en' ) );
		}
	},
	5,
	2
);
