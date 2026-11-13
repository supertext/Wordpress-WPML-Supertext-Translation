<?php
/**
 * Plugin Name: Supertext demo: ACF "Text" block
 * Description: Demo only. Registers an ACF block "Text" (acf/text) with a WYSIWYG field and two switches, like many ACF-built sites do, so the demo can show ACF block translation. Needs ACF PRO; with ACFML the text field is set to Translate and the switches to Copy.
 *
 * @package Supertext\Wpml\Demo
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'acf/init',
	static function (): void {
		if ( ! function_exists( 'acf_register_block_type' ) || ! function_exists( 'acf_add_local_field_group' ) ) {
			return;
		}
		acf_register_block_type(
			array(
				'name'            => 'text',
				'title'           => 'Text',
				'description'     => 'Rich text (ACF block).',
				'category'        => 'text',
				'icon'            => 'editor-paragraph',
				'mode'            => 'edit',
				'supports'        => array( 'mode' => true ),
				'render_callback' => static function ( array $block ): void {
					$classes = array( 'st-demo-text' );
					if ( get_field( 'remove_spacing_top' ) ) {
						$classes[] = 'is-tight-top';
					}
					if ( get_field( 'remove_spacing_bottom' ) ) {
						$classes[] = 'is-tight-bottom';
					}
					echo '<div class="' . esc_attr( implode( ' ', $classes ) ) . '">' . wp_kses_post( (string) get_field( 'text' ) ) . '</div>';
				},
			)
		);
		acf_add_local_field_group(
			array(
				'key'      => 'group_st_demo_text',
				'title'    => 'Block: Text',
				'fields'   => array(
					array(
						'key'                 => 'field_st_demo_text',
						'label'               => 'Text',
						'name'                => 'text',
						'type'                => 'wysiwyg',
						'wpml_cf_preferences' => 2,
					),
					array(
						'key'                 => 'field_st_demo_spacing_top',
						'label'               => 'Remove spacing top',
						'name'                => 'remove_spacing_top',
						'type'                => 'true_false',
						'ui'                  => 1,
						'wpml_cf_preferences' => 1,
					),
					array(
						'key'                 => 'field_st_demo_spacing_bottom',
						'label'               => 'Remove spacing bottom',
						'name'                => 'remove_spacing_bottom',
						'type'                => 'true_false',
						'ui'                  => 1,
						'wpml_cf_preferences' => 1,
					),
				),
				'location' => array(
					array(
						array(
							'param'    => 'block',
							'operator' => '==',
							'value'    => 'acf/text',
						),
					),
				),
			)
		);
	}
);
