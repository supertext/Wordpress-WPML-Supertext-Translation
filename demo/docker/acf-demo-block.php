<?php
/**
 * Plugin Name: Supertext demo: ACF blocks
 * Description: Demo only. Registers the ACF blocks "Text" (acf/text), "Bild" (acf/bild) and "Bildergalerie" (acf/bildergalerie) with a WYSIWYG, an image, a gallery and a radio field, plus the two spacing switches every block shares, like many ACF-built sites do, so the demo can show ACF block translation. Needs ACF PRO; with ACFML the text field is set to Translate and the image, gallery, radio and switch fields to Copy.
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

		/**
		 * The classes the spacing switches add, for every block's render callback.
		 *
		 * @param string $base The block's own class.
		 * @return string
		 */
		$classes = static function ( string $base ): string {
			$list = array( $base );
			if ( get_field( 'remove_spacing_top' ) ) {
				$list[] = 'is-tight-top';
			}
			if ( get_field( 'remove_spacing_bottom' ) ) {
				$list[] = 'is-tight-bottom';
			}
			return implode( ' ', $list );
		};

		/**
		 * The attachment id of an image or gallery field value (return format "array").
		 *
		 * @param mixed $image Field value.
		 * @return int
		 */
		$attachment_id = static function ( $image ): int {
			return is_array( $image ) ? (int) ( $image['ID'] ?? 0 ) : (int) $image;
		};

		acf_register_block_type(
			array(
				'name'            => 'text',
				'title'           => 'Text',
				'description'     => 'Rich text (ACF block).',
				'category'        => 'text',
				'icon'            => 'editor-paragraph',
				'mode'            => 'edit',
				'supports'        => array( 'mode' => true ),
				'render_callback' => static function ( array $block ) use ( $classes ): void {
					echo '<div class="' . esc_attr( $classes( 'st-demo-text' ) ) . '">' . wp_kses_post( (string) get_field( 'text' ) ) . '</div>';
				},
			)
		);
		acf_register_block_type(
			array(
				'name'            => 'bild',
				'title'           => 'Bild',
				'description'     => 'A single image (ACF block).',
				'category'        => 'media',
				'icon'            => 'format-image',
				'mode'            => 'edit',
				'supports'        => array( 'mode' => true ),
				'render_callback' => static function ( array $block ) use ( $classes, $attachment_id ): void {
					$id = $attachment_id( get_field( 'image' ) );
					echo '<figure class="' . esc_attr( $classes( 'st-demo-image' ) ) . '">';
					if ( $id > 0 ) {
						echo wp_get_attachment_image( $id, 'large' );
						$caption = wp_get_attachment_caption( $id );
						if ( $caption ) {
							echo '<figcaption>' . esc_html( $caption ) . '</figcaption>';
						}
					}
					echo '</figure>';
				},
			)
		);
		acf_register_block_type(
			array(
				'name'            => 'bildergalerie',
				'title'           => 'Bildergalerie',
				'description'     => 'An image gallery in one or two columns (ACF block).',
				'category'        => 'media',
				'icon'            => 'format-gallery',
				'mode'            => 'edit',
				'supports'        => array( 'mode' => true ),
				'render_callback' => static function ( array $block ) use ( $classes, $attachment_id ): void {
					$layout = (string) get_field( 'layout' );
					echo '<div class="' . esc_attr( $classes( 'st-demo-gallery' ) ) . ' is-' . esc_attr( '' !== $layout ? $layout : '1col' ) . '">';
					foreach ( (array) get_field( 'images' ) as $image ) {
						$id = $attachment_id( $image );
						if ( $id > 0 ) {
							echo wp_get_attachment_image( $id, 'large' );
						}
					}
					echo '</div>';
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
		acf_add_local_field_group(
			array(
				'key'      => 'group_st_demo_image',
				'title'    => 'Block: Bild',
				'fields'   => array(
					array(
						'key'                 => 'field_st_demo_image',
						'label'               => 'Bild',
						'name'                => 'image',
						'type'                => 'image',
						'return_format'       => 'array',
						'preview_size'        => 'medium',
						'wpml_cf_preferences' => 1,
					),
				),
				'location' => array(
					array(
						array(
							'param'    => 'block',
							'operator' => '==',
							'value'    => 'acf/bild',
						),
					),
				),
			)
		);
		acf_add_local_field_group(
			array(
				'key'      => 'group_st_demo_gallery',
				'title'    => 'Block: Bildergalerie',
				'fields'   => array(
					array(
						'key'                 => 'field_st_demo_gallery',
						'label'               => 'Bilder',
						'name'                => 'images',
						'type'                => 'gallery',
						'return_format'       => 'array',
						'preview_size'        => 'medium',
						'wpml_cf_preferences' => 1,
					),
					array(
						'key'                 => 'field_st_demo_gallery_layout',
						'label'               => 'Layout',
						'name'                => 'layout',
						'type'                => 'radio',
						'choices'             => array(
							'1col' => '1spaltig',
							'2col' => '2spaltig',
						),
						'default_value'       => '1col',
						'wpml_cf_preferences' => 1,
					),
				),
				'location' => array(
					array(
						array(
							'param'    => 'block',
							'operator' => '==',
							'value'    => 'acf/bildergalerie',
						),
					),
				),
			)
		);
		// The spacing switches belong to every block, as one shared group — the way ACF
		// sites usually add them. The keys are the ones the "Text" block carried before,
		// so pages already built from it keep their field references.
		acf_add_local_field_group(
			array(
				'key'      => 'group_st_demo_spacing',
				'title'    => 'Block: Abstände',
				'fields'   => array(
					array(
						'key'                 => 'field_st_demo_spacing_top',
						'label'               => 'Abstand oben entfernen?',
						'name'                => 'remove_spacing_top',
						'type'                => 'true_false',
						'ui'                  => 1,
						'wpml_cf_preferences' => 1,
					),
					array(
						'key'                 => 'field_st_demo_spacing_bottom',
						'label'               => 'Abstand unten entfernen?',
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
					array(
						array(
							'param'    => 'block',
							'operator' => '==',
							'value'    => 'acf/bild',
						),
					),
					array(
						array(
							'param'    => 'block',
							'operator' => '==',
							'value'    => 'acf/bildergalerie',
						),
					),
				),
			)
		);
	}
);
