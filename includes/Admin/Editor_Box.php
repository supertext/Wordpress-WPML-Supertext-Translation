<?php
/**
 * @package Supertext_Wpml
 */

namespace Supertext\Wpml\Admin;

defined( 'ABSPATH' ) || exit;

use Supertext\Wpml\Machine_Translation\Ai_Settings;
use Supertext\Wpml\Machine_Translation\Translator;
use Supertext\Wpml\Wpml\Wpml;
use WP_Error;
use WP_Post;

/**
 * The *Supertext* box in the post editor (block and classic editor): one row per
 * other WPML language with its translation (linked) and a *Translate* or *Translate
 * again* button for Supertext AI. *Translate again* asks first, since it overwrites
 * the translation's title, content and translated fields. The result is shown at
 * the top of the box after the page reloads (the block editor doesn't show classic
 * admin notices).
 *
 * @since 0.1.0
 */
class Editor_Box {
	/** admin-post action. */
	const ACTION = 'supertext_wpml_translate_post';

	/** Transient prefix (+ user id + post id) of the last result. */
	const RESULT = 'supertext_wpml_box_';

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'add_meta_boxes', array( self::class, 'add' ), 10, 2 );
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle' ) );
	}

	/**
	 * Adds the box to post types WPML translates.
	 *
	 * @param string       $post_type Post type.
	 * @param WP_Post|null $post      Post.
	 * @return void
	 */
	public static function add( $post_type, $post = null ): void {
		if ( ! Wpml::is_translated_post_type( (string) $post_type ) ) {
			return;
		}
		add_meta_box(
			'supertext-wpml-translate',
			__( 'Supertext', 'supertext-wpml' ),
			array( self::class, 'render' ),
			(string) $post_type,
			'side',
			'default'
		);
	}

	/**
	 * The URL that translates a post into a language.
	 *
	 * @param int    $post_id Post id.
	 * @param string $code    WPML code.
	 * @param bool   $again   Overwrite an existing translation.
	 * @return string
	 */
	public static function url( int $post_id, string $code, bool $again ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => self::ACTION,
					'post'   => $post_id,
					'lang'   => $code,
					'again'  => $again ? '1' : '0',
				),
				admin_url( 'admin-post.php' )
			),
			self::ACTION . '_' . $post_id . '_' . $code
		);
	}

	/**
	 * Prints the box.
	 *
	 * @param WP_Post $post The post.
	 * @return void
	 */
	public static function render( WP_Post $post ): void {
		$key    = self::RESULT . get_current_user_id() . '_' . $post->ID;
		$result = get_transient( $key );
		if ( is_array( $result ) ) {
			delete_transient( $key );
			printf(
				'<div class="notice notice-%s inline" style="margin:0 0 10px;"><p>%s</p></div>',
				esc_attr( 'success' === ( $result['type'] ?? '' ) ? 'success' : 'error' ),
				esc_html( (string) ( $result['text'] ?? '' ) )
			);
		}

		$source = Wpml::language( Wpml::post_language( $post->ID ) );
		if ( 'auto-draft' === $post->post_status || null === $source ) {
			echo '<p>' . esc_html__( 'Save the post first: Supertext translates the saved version.', 'supertext-wpml' ) . '</p>';
			return;
		}
		if ( ! Ai_Settings::has_api_key() ) {
			$link = current_user_can( 'manage_options' )
				? ' <a href="' . esc_url( admin_url( 'admin.php?page=' . Page::SLUG . '#supertext-ai' ) ) . '">' . esc_html__( 'Settings', 'supertext-wpml' ) . '</a>'
				: '';
			echo '<p>' . esc_html__( 'No Supertext API key is set. Enter it under Supertext → Settings → AI translation.', 'supertext-wpml' ) . $link . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
			return;
		}

		$translations = Wpml::post_translations( $post->ID );
		foreach ( $translations as $code => $id ) {
			if ( $id !== $post->ID && Translator::is_original_of( $id, $post->ID ) ) {
				$original = Wpml::language( (string) $code );
				$link     = get_edit_post_link( $id );
				echo '<p>' . esc_html(
					sprintf(
						/* translators: %s: language name. */
						__( 'This is a translation. Translate from the original (%s) so it isn\'t overwritten.', 'supertext-wpml' ),
						null !== $original ? $original->name : $code
					)
				) . '</p>';
				if ( '' !== (string) $link ) {
					echo '<p><a class="button button-small" href="' . esc_url( (string) $link ) . '">' . esc_html__( 'Open the original', 'supertext-wpml' ) . '</a></p>';
				}
				return;
			}
		}
		$confirm      = __( 'Translate again? This overwrites the title, content and translated fields of the existing translation with a new Supertext AI translation.', 'supertext-wpml' );
		?>
		<p class="description">
			<?php
			printf(
				/* translators: %s: language name. */
				esc_html__( 'AI translation from %s. Supertext translates the saved version: save your changes first.', 'supertext-wpml' ),
				esc_html( $source->name )
			);
			?>
		</p>
		<table class="supertext-box" style="width:100%;border-collapse:collapse;">
			<?php foreach ( Wpml::languages() as $language ) : ?>
				<?php
				if ( $language->code === $source->code ) {
					continue;
				}
				$existing = (int) ( $translations[ $language->code ] ?? 0 );
				$edit     = $existing > 0 ? get_edit_post_link( $existing ) : '';
				?>
				<tr>
					<td style="padding:4px 0;">
						<?php if ( $existing > 0 && '' !== (string) $edit ) : ?>
							<a href="<?php echo esc_url( (string) $edit ); ?>"><?php echo esc_html( $language->name ); ?></a>
							<br /><small><?php echo esc_html( self::status_label( (string) get_post_status( $existing ) ) ); ?></small>
						<?php else : ?>
							<?php echo esc_html( $language->name ); ?>
							<br /><small><?php esc_html_e( 'Not translated', 'supertext-wpml' ); ?></small>
						<?php endif; ?>
					</td>
					<td style="padding:4px 0;text-align:right;">
						<?php if ( $existing > 0 ) : ?>
							<a class="button button-small" href="<?php echo esc_url( self::url( $post->ID, $language->code, true ) ); ?>" onclick="return window.confirm( <?php echo esc_attr( (string) wp_json_encode( $confirm ) ); ?> );"><?php esc_html_e( 'Translate again', 'supertext-wpml' ); ?></a>
						<?php else : ?>
							<a class="button button-small button-primary" href="<?php echo esc_url( self::url( $post->ID, $language->code, false ) ); ?>"><?php esc_html_e( 'Translate', 'supertext-wpml' ); ?></a>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</table>
		<p class="description" style="margin-top:8px;">
			<?php esc_html_e( 'New translations are saved as drafts. Professional (human) translation: the "Supertext Human Translation" bulk action in the list of posts or pages.', 'supertext-wpml' ); ?>
		</p>
		<?php
	}

	/**
	 * A post status for people.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	private static function status_label( string $status ): string {
		$object = get_post_status_object( $status );
		return null !== $object && isset( $object->label ) ? (string) $object->label : $status;
	}

	/**
	 * admin-post: translates and returns to the editor.
	 *
	 * @return void
	 */
	public static function handle(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- verified below.
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		$code    = isset( $_GET['lang'] ) ? sanitize_text_field( wp_unslash( $_GET['lang'] ) ) : '';
		$again   = isset( $_GET['again'] ) && '1' === $_GET['again'];
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		check_admin_referer( self::ACTION . '_' . $post_id . '_' . $code );
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to edit this post.', 'supertext-wpml' ) );
		}

		$result   = Translator::translate( $post_id, $code, $again );
		$language = Wpml::language( $code );
		$name     = null !== $language ? $language->name : $code;
		if ( $result instanceof WP_Error ) {
			$message = array(
				'type' => 'error',
				'text' => $result->get_error_message(),
			);
		} else {
			$message = array(
				'type' => 'success',
				'text' => sprintf(
					/* translators: %s: language name. */
					__( 'Translated into %s with Supertext AI. Open the translation to check it.', 'supertext-wpml' ),
					$name
				),
			);
		}
		set_transient( self::RESULT . get_current_user_id() . '_' . $post_id, $message, 300 );

		wp_safe_redirect( (string) get_edit_post_link( $post_id, 'url' ) );
		exit;
	}
}
