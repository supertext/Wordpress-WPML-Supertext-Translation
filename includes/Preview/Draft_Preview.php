<?php
/**
 * @package Supertext_Wpml
 */

namespace Supertext\Wpml\Preview;

defined( 'ABSPATH' ) || exit;

use Supertext\Wpml\Admin\Settings;
use WP_Post;
use WP_Query;

/**
 * Public, secret-link preview of unpublished posts/pages.
 *
 * A per-post secret UUID lets anyone with the link view a draft/pending/scheduled
 * post without logging in — handy for sharing a draft (e.g. a translated page saved
 * as a draft by the human-translation write-back) with a reviewer, client, or an
 * external screenshot tool.
 *
 * Controlled from a metabox on the editor: an on/off switch and an expiration date
 * (default two weeks out). The link only works while enabled and unexpired, and
 * only for the exact post whose stored token matches — everything else 404s.
 *
 * The token is the capability: treat the link like a password (long random UUID,
 * HTTPS, expiry).
 *
 * @since 0.1.0
 */
class Draft_Preview {
	/** Query var carrying the secret token. */
	const QUERY_VAR = 'st_preview';

	/** Post meta: the secret token. */
	const META_TOKEN = '_supertext_preview_token';

	/** Post meta: whether the public preview link is enabled (1/''). */
	const META_ENABLED = '_supertext_preview_enabled';

	/** Post meta: expiration as a unix timestamp. */
	const META_EXPIRES = '_supertext_preview_expires';

	/** Nonce action/field for the metabox. */
	const NONCE = 'supertext_preview_meta';

	/** Post types that get the preview metabox. */
	const POST_TYPES = array( 'post', 'page' );

	/**
	 * The post id currently being previewed (set once a token validates), so the
	 * `posts_results` filter knows which returned post to force to `publish`.
	 *
	 * @var int
	 */
	private static $preview_post_id = 0;

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_filter( 'query_vars', array( self::class, 'register_query_var' ) );
		add_action( 'pre_get_posts', array( self::class, 'allow_preview' ) );
		add_action( 'add_meta_boxes', array( self::class, 'add_meta_box' ) );
		add_action( 'save_post', array( self::class, 'save' ) );
	}

	/**
	 * Registers the token query var.
	 *
	 * @param string[] $vars Query vars.
	 * @return string[]
	 */
	public static function register_query_var( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Lets the main query return an unpublished post when a valid token is present.
	 *
	 * Scoped to the exact post the token belongs to: only after confirming the
	 * queried post's stored token matches, the link is enabled, and it hasn't
	 * expired do we (1) widen the queried statuses and pin the exact post type so
	 * the draft is actually found, (2) force that post to `publish` in memory via
	 * `posts_results` so WordPress renders it instead of 404ing, and (3) suppress
	 * the canonical redirect that would otherwise bounce a draft's ugly URL.
	 * Otherwise the request 404s as usual.
	 *
	 * @param WP_Query $query The query.
	 * @return void
	 */
	public static function allow_preview( $query ): void {
		if ( ! Settings::preview_links_enabled() ) {
			return;
		}
		if ( is_admin() || ! $query instanceof WP_Query || ! $query->is_main_query() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only capability token, validated below.
		$token = isset( $_GET[ self::QUERY_VAR ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::QUERY_VAR ] ) ) : '';
		if ( '' === $token ) {
			return;
		}

		$post_id = (int) ( $query->get( 'p' ) ?: $query->get( 'page_id' ) ?: $query->get( 'preview_id' ) ?: 0 );
		if ( $post_id <= 0 || ! self::is_valid( $post_id, $token ) ) {
			return;
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return;
		}

		self::$preview_post_id = $post_id;

		// Make the query actually find the unpublished post: widen the statuses and
		// pin the exact type (a page requested as `?p=` would otherwise miss the
		// default post_type = "post" filter).
		$query->set( 'post_status', array( 'publish', 'draft', 'pending', 'future', 'private' ) );
		$query->set( 'post_type', $post->post_type );

		// Belt-and-suspenders: force the returned post to `publish` in memory so
		// downstream visibility/404 checks pass even where a widened status query
		// alone doesn't render (the technique the Public Post Preview plugin uses).
		add_filter( 'posts_results', array( self::class, 'force_publish' ), 10, 2 );

		// A draft has no canonical permalink; don't let WordPress redirect away from
		// the preview URL (which would drop the token).
		add_filter( 'redirect_canonical', '__return_false' );

		// Don't cache or index a secret draft preview.
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		nocache_headers();
		add_filter( 'wp_robots', 'wp_robots_no_robots' );
	}

	/**
	 * Forces the previewed post's in-memory status to `publish` so WordPress renders
	 * it. Only touches the exact post the (already-validated) token belongs to, then
	 * removes itself so no other query is affected.
	 *
	 * @param WP_Post[] $posts The posts the main query returned.
	 * @param WP_Query  $query The query.
	 * @return WP_Post[]
	 */
	public static function force_publish( $posts, $query ) {
		remove_filter( 'posts_results', array( self::class, 'force_publish' ), 10 );

		if ( self::$preview_post_id <= 0 || empty( $posts ) ) {
			return $posts;
		}

		foreach ( $posts as $post ) {
			if ( isset( $post->ID ) && (int) $post->ID === self::$preview_post_id ) {
				$post->post_status = 'publish';
			}
		}

		return $posts;
	}

	/**
	 * Tells whether a token grants preview access to a post right now.
	 *
	 * @param int    $post_id Post id.
	 * @param string $token   Provided token.
	 * @return bool
	 */
	public static function is_valid( int $post_id, string $token ): bool {
		$stored = (string) get_post_meta( $post_id, self::META_TOKEN, true );
		if ( '' === $stored || ! hash_equals( $stored, $token ) ) {
			return false;
		}
		if ( ! get_post_meta( $post_id, self::META_ENABLED, true ) ) {
			return false;
		}

		$expires = (int) get_post_meta( $post_id, self::META_EXPIRES, true );
		if ( $expires > 0 && time() > $expires ) {
			return false;
		}

		return true;
	}

	/**
	 * Returns the public preview URL for a post (empty if no token yet).
	 *
	 * @param WP_Post $post  Post.
	 * @param string  $token Token.
	 * @return string
	 */
	public static function preview_url( WP_Post $post, string $token ): string {
		if ( '' === $token ) {
			return '';
		}
		// Plain query vars resolve regardless of permalink settings, and drafts have
		// no reliable pretty URL.
		$var = 'page' === $post->post_type ? 'page_id' : 'p';
		return add_query_arg(
			array(
				$var             => $post->ID,
				self::QUERY_VAR  => $token,
			),
			home_url( '/' )
		);
	}

	/**
	 * Ensures a post has an active secret preview link and returns its URL.
	 *
	 * Generates the token on first use, turns the preview on, and (re)sets a future
	 * expiry when missing or already past — so an external consumer (e.g. the
	 * screenshot service) can reach the page even while it is a draft. Returns an
	 * empty string if the post doesn't exist.
	 *
	 * @param int $post_id Post id.
	 * @return string
	 */
	public static function ensure_preview_url( int $post_id ): string {
		if ( ! Settings::preview_links_enabled() ) {
			return '';
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return '';
		}

		$token = (string) get_post_meta( $post_id, self::META_TOKEN, true );
		if ( '' === $token ) {
			$token = wp_generate_uuid4();
			update_post_meta( $post_id, self::META_TOKEN, $token );
		}

		update_post_meta( $post_id, self::META_ENABLED, 1 );

		$expires = (int) get_post_meta( $post_id, self::META_EXPIRES, true );
		if ( $expires <= time() ) {
			update_post_meta( $post_id, self::META_EXPIRES, time() + 2 * WEEK_IN_SECONDS );
		}

		return self::preview_url( $post, $token );
	}

	/**
	 * Adds the preview metabox to the supported post types.
	 *
	 * @return void
	 */
	public static function add_meta_box(): void {
		if ( ! Settings::preview_links_enabled() ) {
			return;
		}
		foreach ( self::POST_TYPES as $type ) {
			add_meta_box(
				'supertext-preview',
				__( 'Supertext — Public preview link', 'supertext-wpml' ),
				array( self::class, 'render_meta_box' ),
				$type,
				'side',
				'default'
			);
		}
	}

	/**
	 * Renders the metabox.
	 *
	 * @param WP_Post $post Current post.
	 * @return void
	 */
	public static function render_meta_box( WP_Post $post ): void {
		$enabled = (bool) get_post_meta( $post->ID, self::META_ENABLED, true );
		$token   = (string) get_post_meta( $post->ID, self::META_TOKEN, true );
		$expires = (int) get_post_meta( $post->ID, self::META_EXPIRES, true );
		$default = gmdate( 'Y-m-d', time() + 2 * WEEK_IN_SECONDS );
		$date    = $expires > 0 ? gmdate( 'Y-m-d', $expires ) : $default;
		$expired = $expires > 0 && time() > $expires;
		$url     = self::preview_url( $post, $token );

		wp_nonce_field( self::NONCE, self::NONCE );
		?>
		<p>
			<label>
				<input type="checkbox" name="supertext_preview_enabled" value="1" <?php checked( $enabled ); ?> />
				<?php esc_html_e( 'Enable public preview link', 'supertext-wpml' ); ?>
			</label>
		</p>
		<p>
			<label for="supertext_preview_expires"><?php esc_html_e( 'Expires', 'supertext-wpml' ); ?></label><br />
			<input type="date" id="supertext_preview_expires" name="supertext_preview_expires" value="<?php echo esc_attr( $date ); ?>" />
			<span class="description" style="display:block;"><?php esc_html_e( 'Default: two weeks from now.', 'supertext-wpml' ); ?></span>
		</p>
		<?php if ( $enabled && '' !== $url ) : ?>
			<p style="margin-top:1em;">
				<?php if ( $expired ) : ?>
					<span style="color:#d63638;font-weight:600;"><?php esc_html_e( 'This link has expired — save with a future date to reactivate it.', 'supertext-wpml' ); ?></span>
				<?php else : ?>
					<label for="supertext_preview_url"><strong><?php esc_html_e( 'Preview link', 'supertext-wpml' ); ?></strong></label>
				<?php endif; ?>
				<input type="text" id="supertext_preview_url" readonly value="<?php echo esc_url( $url ); ?>" onclick="this.select();" style="width:100%;margin-top:4px;" />
			</p>
		<?php elseif ( ! $enabled ) : ?>
			<p class="description"><?php esc_html_e( 'Turn on to generate a secret link that shows this page to anyone, even while it is a draft.', 'supertext-wpml' ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Saves the metabox: toggle, expiration, and (on first enable) the secret token.
	 *
	 * @param int $post_id Post id.
	 * @return void
	 */
	public static function save( int $post_id ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		// Only act on the metabox submission (its nonce/fields are absent from the
		// block editor's REST save).
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST[ self::NONCE ] ) ), self::NONCE ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$enabled = ! empty( $_POST['supertext_preview_enabled'] );
		update_post_meta( $post_id, self::META_ENABLED, $enabled ? 1 : '' );

		if ( ! $enabled ) {
			return; // Keep the token so re-enabling reuses the same link.
		}

		if ( '' === (string) get_post_meta( $post_id, self::META_TOKEN, true ) ) {
			update_post_meta( $post_id, self::META_TOKEN, wp_generate_uuid4() );
		}

		$raw = isset( $_POST['supertext_preview_expires'] ) ? sanitize_text_field( wp_unslash( $_POST['supertext_preview_expires'] ) ) : '';
		$ts  = '' !== $raw ? strtotime( $raw . ' 23:59:59' ) : 0;
		if ( ! $ts ) {
			$ts = time() + 2 * WEEK_IN_SECONDS;
		}
		update_post_meta( $post_id, self::META_EXPIRES, $ts );
	}
}
