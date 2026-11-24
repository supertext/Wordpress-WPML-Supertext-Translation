<?php
/**
 * @package Supertext_Wpml
 */

namespace Supertext\Wpml\Admin;

defined( 'ABSPATH' ) || exit;

use Supertext\Wpml\Human_Translation\Human_Strings;
use Supertext\Wpml\Human_Translation\Services as Human_Services;
use Supertext\Wpml\Machine_Translation\Ai_Settings;
use Supertext\Wpml\Strings\String_Store;
use Supertext\Wpml\Wpml\Wpml;
use WP_Error;

/**
 * Supertext → String translations: the strings of WPML String Translation's packages
 * (forms of WPForms, Gravity Forms, Contact Form 7, ACF field groups, …), filterable by
 * group, with a text box per language. Checked rows can be translated with Supertext
 * AI or ordered for professional translation; edited boxes are saved with Save. The
 * translations are WPML's own (String_Store), the same as in WPML → String Translation.
 *
 * Needs WPML String Translation; without it the page explains that.
 *
 * @since 1.2.0
 */
final class String_Translations_Page {
	/** Page slug. */
	public const SLUG = 'supertext-wpml-strings';

	/** admin-post action. */
	public const ACTION = 'supertext_wpml_strings_save';

	/**
	 * Hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( self::class, 'register_menu' ), 12 );
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle_submit' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'maybe_enqueue' ) );
		Human_Strings::init();
	}

	/**
	 * Adds the page under Supertext.
	 *
	 * @return void
	 */
	public static function register_menu(): void {
		add_submenu_page(
			Page::SLUG,
			__( 'String translations', 'supertext-wpml' ),
			__( 'String translations', 'supertext-wpml' ),
			'manage_options',
			self::SLUG,
			array( self::class, 'render' )
		);
	}

	/**
	 * Loads the grid script on this page.
	 *
	 * @param string $hook Screen hook.
	 * @return void
	 */
	public static function maybe_enqueue( string $hook ): void {
		if ( str_ends_with( $hook, '_page_' . self::SLUG ) ) {
			String_Table::enqueue();
		}
	}

	/**
	 * Renders the page.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div class="wrap supertext-admin">';
		Page::hero(
			__( 'String translations', 'supertext-wpml' ),
			__( 'Translate form fields and other WPML strings with Supertext AI or professional translators.', 'supertext-wpml' )
		);
		self::render_notices();

		if ( ! String_Store::available() ) {
			echo '<div class="st-panel"><p style="margin:0;">' . esc_html__( 'This page needs WPML String Translation (wpml.org account → Downloads). With it, the texts of forms (WPForms, Gravity Forms, Contact Form 7 with their WPML add-ons) and other WPML string packages appear here.', 'supertext-wpml' ) . '</p></div></div>';
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$group  = isset( $_GET['st_group'] ) ? sanitize_text_field( wp_unslash( $_GET['st_group'] ) ) : '';
		$search = isset( $_GET['st_search'] ) ? sanitize_text_field( wp_unslash( $_GET['st_search'] ) ) : '';
		$empty  = ! empty( $_GET['st_empty'] );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$all       = String_Store::rows();
		$languages = self::languages();
		$groups    = array_values( array_unique( array_column( $all, 'group' ) ) );
		sort( $groups );

		$needle = mb_strtolower( $search );
		$rows   = array_filter(
			$all,
			static function ( array $row ) use ( $group, $needle ): bool {
				if ( '' !== $group && $row['group'] !== $group ) {
					return false;
				}
				return '' === $needle
					|| false !== mb_strpos( mb_strtolower( $row['source'] ), $needle )
					|| false !== mb_strpos( mb_strtolower( $row['name'] ), $needle )
					|| false !== mb_strpos( mb_strtolower( $row['group'] ), $needle );
			}
		);
		$translations = String_Store::translations( $rows );
		if ( $empty ) {
			$rows = array_filter(
				$rows,
				static function ( array $row ) use ( $languages, $translations ): bool {
					foreach ( array_keys( $languages ) as $code ) {
						if ( $code !== $row['lang'] && '' === (string) ( $translations[ $row['id'] ][ $code ] ?? '' ) ) {
							return true;
						}
					}
					return false;
				}
			);
		}

		ob_start();
		?>
		<form method="get" class="st-filter">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
			<label for="st-filter-group" class="screen-reader-text"><?php esc_html_e( 'Group', 'supertext-wpml' ); ?></label>
			<span class="st-select-wrap">
				<select name="st_group" id="st-filter-group" class="st-select st-filter__group">
					<option value=""><?php esc_html_e( 'All groups', 'supertext-wpml' ); ?></option>
					<?php foreach ( $groups as $g ) : ?>
						<option value="<?php echo esc_attr( $g ); ?>" <?php selected( $group, $g ); ?>><?php echo esc_html( $g ); ?></option>
					<?php endforeach; ?>
				</select>
				<span class="dashicons dashicons-arrow-down-alt2 st-select-chevron"></span>
			</span>
			<span class="st-search">
				<span class="dashicons dashicons-search"></span>
				<input type="search" name="st_search" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search strings…', 'supertext-wpml' ); ?>" />
			</span>
			<label class="st-check-plain">
				<input type="checkbox" name="st_empty" value="1" <?php checked( $empty ); ?> onchange="this.form.submit();" />
				<span><?php esc_html_e( 'Empty translation only', 'supertext-wpml' ); ?></span>
			</label>
			<button type="submit" class="st-btn-outline"><?php esc_html_e( 'Filter', 'supertext-wpml' ); ?></button>
			<span class="st-count">
				<?php
				/* translators: %d: number of strings shown. */
				printf( esc_html( _n( '%d string', '%d strings', count( $rows ), 'supertext-wpml' ) ), count( $rows ) );
				?>
			</span>
		</form>
		<?php
		$filter_html = (string) ob_get_clean();

		String_Table::render(
			array(
				'action'         => self::ACTION,
				'hidden'         => array(
					'st_group'  => $group,
					'st_search' => $search,
					'st_empty'  => $empty ? '1' : '',
				),
				'rows'           => $rows,
				'show_group'     => '' === $group, // One group filtered: its name is in the filter.
				'languages'      => $languages,
				'translations'   => $translations,
				'human'          => Settings::is_configured(),
				'human_services' => Settings::is_configured() ? Human_Services::all() : array(),
				'filter_html'    => $filter_html,
				'intro'          => sprintf(
					/* translators: %s: link to WPML → String Translation. */
					__( 'The texts of forms and other WPML string packages. Tick the rows you want, then translate them with Supertext AI or order a professional translation, or edit a translation and save. These are the same translations as in %s.', 'supertext-wpml' ),
					'<a href="' . esc_url( admin_url( 'admin.php?page=wpml-string-translation/menu/string-translation.php' ) ) . '">' . esc_html__( 'WPML → String Translation', 'supertext-wpml' ) . '</a>'
				),
			)
		);
		echo '</div>';
	}

	/**
	 * Saves the grid, then translates or orders the checked rows.
	 *
	 * @return void
	 */
	public static function handle_submit(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'supertext-wpml' ) );
		}
		check_admin_referer( self::ACTION );

		$submit = String_Table::read_submit();
		$rows   = String_Store::rows();
		// Save what was typed first, so nothing is lost.
		$saved = String_Table::save_grid( $submit['grid'], String_Store::translations( array_intersect_key( $rows, self::grid_ids( $submit['grid'] ) ) ) );

		$args = array( 'page' => self::SLUG );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce checked above.
		foreach ( array( 'st_group', 'st_search', 'st_empty' ) as $key ) {
			if ( ! empty( $_POST[ $key ] ) ) {
				$args[ $key ] = sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( 'ai' === $submit['do'] ) {
			$done = self::translate_ai( array_intersect_key( $rows, array_flip( $submit['selected'] ) ) );
			if ( is_wp_error( $done ) ) {
				$args['error'] = '1';
				self::error( $done->get_error_message() );
			} else {
				$args['ai'] = (string) $done;
			}
		} elseif ( 'human' === $submit['do'] ) {
			$result = array() === $submit['selected']
				? new WP_Error( 'supertext_empty', __( 'Select at least one row.', 'supertext-wpml' ) )
				: Human_Strings::place_order( $submit['selected'], $submit['lang'], $submit['service_id'], $submit['express'] );
			if ( is_wp_error( $result ) ) {
				$args['error'] = '1';
				self::error( $result->get_error_message() );
			} else {
				$args['ordered'] = implode( '-', array_map( 'intval', $result ) );
			}
		} else {
			$args['saved'] = (string) $saved;
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Translates rows with Supertext AI into every other language.
	 *
	 * @param array<int, array{id: int, source: string, lang: string, html: bool}> $rows Rows.
	 * @return int|WP_Error How many translations were saved.
	 */
	private static function translate_ai( array $rows ) {
		if ( array() === $rows ) {
			return new WP_Error( 'supertext_empty', __( 'Select at least one row to translate.', 'supertext-wpml' ) );
		}
		if ( ! Ai_Settings::has_api_key() ) {
			return new WP_Error( 'supertext_no_key', __( 'Enter your Supertext API key on the Settings page first.', 'supertext-wpml' ) );
		}
		$by_source = array();
		$plain     = array();
		foreach ( $rows as $row ) {
			// Everything is sent as HTML: plain-text strings are escaped and decoded again.
			$by_source[ $row['lang'] ][ $row['id'] ] = $row['html'] ? $row['source'] : esc_html( $row['source'] );
			if ( ! $row['html'] ) {
				$plain[ $row['id'] ] = true;
			}
		}
		$client = Ai_Settings::client();
		$saved  = 0;
		foreach ( $by_source as $source_code => $sources ) {
			$source = Wpml::language( (string) $source_code );
			foreach ( Wpml::languages() as $target ) {
				if ( $target->code === (string) $source_code ) {
					continue;
				}
				$map = $client->translate_strings( $sources, $target, $source );
				if ( is_wp_error( $map ) ) {
					return $map;
				}
				foreach ( $map as $id => $text ) {
					$map[ $id ] = isset( $plain[ $id ] ) ? html_entity_decode( (string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) : (string) $text;
				}
				$saved += String_Store::save( $target->code, $map );
			}
		}

		return $saved;
	}

	/**
	 * The site's languages, code => name.
	 *
	 * @return array<string, string>
	 */
	private static function languages(): array {
		$out = array();
		foreach ( Wpml::languages() as $language ) {
			$out[ $language->code ] = $language->name;
		}

		return $out;
	}

	/**
	 * The string ids in a submitted grid.
	 *
	 * @param array<string, mixed> $grid Language => [ id => text ].
	 * @return array<int, bool>
	 */
	private static function grid_ids( array $grid ): array {
		$ids = array();
		foreach ( $grid as $cells ) {
			foreach ( is_array( $cells ) ? array_keys( $cells ) : array() as $id ) {
				$ids[ (int) $id ] = true;
			}
		}

		return $ids;
	}

	/**
	 * One-off result notices.
	 *
	 * @return void
	 */
	private static function render_notices(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['saved'] ) ) {
			$n = (int) $_GET['saved'];
			/* translators: %d: number of translations. */
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( sprintf( _n( '%d translation saved.', '%d translations saved.', $n, 'supertext-wpml' ), $n ) ) );
		}
		if ( isset( $_GET['ai'] ) ) {
			$n = (int) $_GET['ai'];
			/* translators: %d: number of translations. */
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( sprintf( _n( '%d translation by Supertext AI saved.', '%d translations by Supertext AI saved.', $n, 'supertext-wpml' ), $n ) ) );
		}
		if ( isset( $_GET['ordered'] ) ) {
			$ids     = array_filter( array_map( static fn( string $part ): int => max( 0, (int) $part ), explode( '-', sanitize_text_field( wp_unslash( $_GET['ordered'] ) ) ) ) );
			$numbers = implode( ', ', array_map( static fn( int $id ): string => '#' . $id, $ids ) );
			$link    = '<a href="' . esc_url( admin_url( 'admin.php?page=' . Orders_Page::SLUG ) ) . '">' . esc_html__( 'Supertext → Orders', 'supertext-wpml' ) . '</a>';
			/* translators: %s: order numbers, e.g. "#12345". */
			$message = sprintf( _n( 'Order %s placed with Supertext. The translations are saved automatically when it is done; see', 'Orders %s placed with Supertext. The translations are saved automatically when they are done; see', max( 1, count( $ids ) ), 'supertext-wpml' ), $numbers );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $message ) . ' ' . $link . '.</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- message escaped, link built from esc_url/esc_html.
		}
		if ( isset( $_GET['error'] ) ) {
			$key = 'supertext_wpml_strings_error_' . get_current_user_id();
			$msg = get_transient( $key );
			delete_transient( $key );
			printf( '<div class="notice notice-error is-dismissible"><p>%s</p></div>', esc_html( is_string( $msg ) && '' !== $msg ? $msg : __( 'Something went wrong.', 'supertext-wpml' ) ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Keeps an error message for the next page view.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	private static function error( string $message ): void {
		set_transient( 'supertext_wpml_strings_error_' . get_current_user_id(), $message, 60 );
	}
}
