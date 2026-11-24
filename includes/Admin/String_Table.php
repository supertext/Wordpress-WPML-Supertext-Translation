<?php
/**
 * @package Supertext_Wpml
 */

namespace Supertext\Wpml\Admin;

defined( 'ABSPATH' ) || exit;

use Supertext\Wpml\Human_Translation\Human_Strings;
use Supertext\Wpml\Strings\String_Store;

/**
 * The string translation grid (same design as the Polylang plugin): a toolbar card
 * (intro, filter slot, bulk-actions row) and a table card (checkbox, field, group,
 * source, one text box per language), plus the Save bar. Rows are WPML strings by id;
 * the box of a row's own language shows the source instead.
 *
 * "Supertext AI Translation" fills every other language of the checked rows; human
 * orders use the target language / type / delivery pickers. The controls sit in the
 * toolbar card and submit the table form through the HTML5 `form` attribute.
 *
 * @since 1.2.0
 */
final class String_Table {
	/** Form id shared by the toolbar controls and the table form. */
	public const FORM_ID = 'supertext-string-form';

	/**
	 * Enqueues the table script and its live-quote config.
	 *
	 * @return void
	 */
	public static function enqueue(): void {
		wp_enqueue_script(
			'supertext-string-table',
			plugins_url( 'assets/js/string-table.js', SUPERTEXT_WPML_FILE ),
			array(),
			SUPERTEXT_WPML_VERSION,
			true
		);
		wp_localize_script(
			'supertext-string-table',
			'SupertextStringTable',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'quoteAction' => Human_Strings::QUOTE_ACTION,
				'quoteNonce'  => wp_create_nonce( Human_Strings::QUOTE_ACTION ),
				'i18n'        => array(
					'quoting'       => __( 'Getting price…', 'supertext-wpml' ),
					'delivery'      => __( 'Delivery', 'supertext-wpml' ),
					'noOptions'     => __( 'No delivery options', 'supertext-wpml' ),
					'quoteFail'     => __( 'Could not get a price.', 'supertext-wpml' ),
					'needRows'      => __( 'Select at least one row.', 'supertext-wpml' ),
					'needLang'      => __( 'Select a target language.', 'supertext-wpml' ),
					'needService'   => __( 'Select a translation type.', 'supertext-wpml' ),
					'needDelivery'  => __( 'Select a delivery option.', 'supertext-wpml' ),
					'order'         => __( 'Order', 'supertext-wpml' ),
					'translate'     => __( 'Translate', 'supertext-wpml' ),
					'apply'         => __( 'Apply', 'supertext-wpml' ),
					'needRowsPrice' => __( 'Tick at least one row to see delivery options and the price.', 'supertext-wpml' ),
					'ordering'      => __( 'Ordering…', 'supertext-wpml' ),
					'translating'   => __( 'Translating…', 'supertext-wpml' ),
				),
			)
		);
	}

	/**
	 * Renders the grid.
	 *
	 * @param array<string, mixed> $args rows, languages (code => name), translations
	 *                                   (id => [ code => text ]), action, hidden,
	 *                                   show_group, human, human_services, filter_html, intro.
	 * @return void
	 */
	public static function render( array $args ): void {
		$rows         = (array) ( $args['rows'] ?? array() );
		$languages    = (array) ( $args['languages'] ?? array() );
		$translations = (array) ( $args['translations'] ?? array() );
		$human        = ! empty( $args['human'] );
		$show_group   = ! empty( $args['show_group'] );
		$filter       = (string) ( $args['filter_html'] ?? '' );
		$intro        = (string) ( $args['intro'] ?? '' );
		$fid          = self::FORM_ID;
		?>
		<div class="st-panel st-toolbar">
			<?php if ( '' !== $intro ) : ?>
				<p class="st-toolbar__intro"><?php echo wp_kses( $intro, array( 'a' => array( 'href' => array() ) ) ); ?></p>
			<?php endif; ?>
			<?php
			if ( '' !== $filter ) {
				echo $filter; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built and escaped by the caller.
			}
			?>
			<?php if ( count( $languages ) < 2 ) : ?>
				<p><em><?php esc_html_e( 'Add at least one more language in WPML to translate strings.', 'supertext-wpml' ); ?></em></p>
			<?php else : ?>
				<div class="st-bulkrow">
					<span class="st-select-wrap">
						<select name="st_action" form="<?php echo esc_attr( $fid ); ?>" class="st-select st-bulk-action">
							<option value="-1"><?php esc_html_e( 'Bulk actions', 'supertext-wpml' ); ?></option>
							<option value="ai"><?php esc_html_e( 'Supertext AI Translation', 'supertext-wpml' ); ?></option>
							<?php if ( $human ) : ?>
								<option value="human"><?php esc_html_e( 'Supertext Human Translation', 'supertext-wpml' ); ?></option>
							<?php endif; ?>
						</select>
						<span class="dashicons dashicons-arrow-down-alt2 st-select-chevron"></span>
					</span>
					<?php if ( $human ) : ?>
						<span class="st-select-wrap st-picker st-picker-human">
							<select name="lang" id="st-lang" form="<?php echo esc_attr( $fid ); ?>" class="st-select" title="<?php esc_attr_e( 'Target language', 'supertext-wpml' ); ?>">
								<option value=""><?php esc_html_e( 'Target language', 'supertext-wpml' ); ?></option>
								<?php foreach ( $languages as $code => $name ) : ?>
									<option value="<?php echo esc_attr( (string) $code ); ?>"><?php echo esc_html( (string) $name ); ?></option>
								<?php endforeach; ?>
							</select>
							<span class="dashicons dashicons-arrow-down-alt2 st-select-chevron"></span>
						</span>
						<span class="st-select-wrap st-picker st-picker-human">
							<select name="service_id" id="st-service" form="<?php echo esc_attr( $fid ); ?>" class="st-select" title="<?php esc_attr_e( 'Translation type', 'supertext-wpml' ); ?>">
								<option value=""><?php esc_html_e( 'Translation type', 'supertext-wpml' ); ?></option>
								<?php foreach ( (array) ( $args['human_services'] ?? array() ) as $id => $service ) : ?>
									<option value="<?php echo esc_attr( (string) $id ); ?>" title="<?php echo esc_attr( (string) ( $service['short'] ?? '' ) ); ?>"><?php echo esc_html( (string) ( $service['label'] ?? $id ) ); ?></option>
								<?php endforeach; ?>
							</select>
							<span class="dashicons dashicons-arrow-down-alt2 st-select-chevron"></span>
						</span>
						<span class="st-select-wrap st-picker st-picker-human">
							<select name="express" id="st-express" form="<?php echo esc_attr( $fid ); ?>" class="st-select" title="<?php esc_attr_e( 'Delivery', 'supertext-wpml' ); ?>">
								<option value=""><?php esc_html_e( 'Delivery', 'supertext-wpml' ); ?></option>
							</select>
							<span class="dashicons dashicons-arrow-down-alt2 st-select-chevron"></span>
						</span>
						<span class="st-quote-status st-picker st-picker-human" aria-live="polite"></span>
					<?php endif; ?>
					<button type="submit" name="st_apply" value="1" form="<?php echo esc_attr( $fid ); ?>" class="st-btn-outline st-apply" data-confirm="<?php esc_attr_e( 'Order a professional translation of the checked strings?', 'supertext-wpml' ); ?>"><?php esc_html_e( 'Apply', 'supertext-wpml' ); ?></button>
				</div>
			<?php endif; ?>
		</div>

		<?php if ( count( $languages ) >= 2 ) : ?>
			<form method="post" id="<?php echo esc_attr( $fid ); ?>" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( (string) $args['action'] ); ?>" />
				<?php foreach ( (array) ( $args['hidden'] ?? array() ) as $name => $value ) : ?>
					<input type="hidden" name="<?php echo esc_attr( (string) $name ); ?>" value="<?php echo esc_attr( (string) $value ); ?>" />
				<?php endforeach; ?>
				<?php wp_nonce_field( (string) $args['action'] ); ?>

				<?php if ( empty( $rows ) ) : ?>
					<div class="st-panel"><p style="margin:0;"><?php esc_html_e( 'No strings to translate.', 'supertext-wpml' ); ?></p></div>
				<?php else : ?>
					<div class="st-panel st-tablecard">
						<table class="st-table">
							<thead>
								<tr>
									<th class="st-col-check"><input type="checkbox" class="st-check-all" title="<?php esc_attr_e( 'Select all', 'supertext-wpml' ); ?>" /></th>
									<th class="st-col-field"><?php esc_html_e( 'Field', 'supertext-wpml' ); ?></th>
									<?php if ( $show_group ) : ?>
										<th class="st-col-group"><?php esc_html_e( 'Group', 'supertext-wpml' ); ?></th>
									<?php endif; ?>
									<th class="st-col-source"><?php esc_html_e( 'Source', 'supertext-wpml' ); ?></th>
									<?php foreach ( $languages as $name ) : ?>
										<th><?php echo esc_html( (string) $name ); ?></th>
									<?php endforeach; ?>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $rows as $row ) : ?>
									<?php $id = (int) $row['id']; ?>
									<tr>
										<td class="st-col-check"><input type="checkbox" class="st-row-check" name="sel[]" value="<?php echo (int) $id; ?>" /></td>
										<td class="st-col-field"><?php echo esc_html( (string) $row['name'] ); ?></td>
										<?php if ( $show_group ) : ?>
											<td class="st-col-group"><span class="st-chip"><?php echo esc_html( (string) $row['group'] ); ?></span></td>
										<?php endif; ?>
										<td class="st-col-source">
											<?php echo esc_html( wp_strip_all_tags( (string) $row['source'] ) ); ?>
											<input type="hidden" name="src[<?php echo (int) $id; ?>]" value="<?php echo (int) $id; ?>" />
										</td>
										<?php foreach ( $languages as $code => $name ) : ?>
											<td>
												<?php if ( (string) $code === (string) $row['lang'] ) : ?>
													<span class="st-source-mark"><?php esc_html_e( 'Source language', 'supertext-wpml' ); ?></span>
												<?php else : ?>
													<textarea
														name="tr[<?php echo esc_attr( (string) $code ); ?>][<?php echo (int) $id; ?>]"
														rows="1"
														class="st-tr-input"
														placeholder="<?php echo esc_attr( sprintf( /* translators: %s: language name */ __( 'Add %s translation…', 'supertext-wpml' ), (string) $name ) ); ?>"
													><?php echo esc_textarea( (string) ( $translations[ $id ][ $code ] ?? '' ) ); ?></textarea>
												<?php endif; ?>
											</td>
										<?php endforeach; ?>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>

					<div class="st-savebar">
						<button type="submit" name="st_save" value="1" class="st-btn-mint st-btn-icon">
							<span class="dashicons dashicons-yes"></span><?php esc_html_e( 'Save translations', 'supertext-wpml' ); ?>
						</button>
						<span class="st-savebar__note"><?php esc_html_e( 'Changes apply to the same translations as WPML → String Translation.', 'supertext-wpml' ); ?></span>
					</div>
				<?php endif; ?>
			</form>
		<?php endif; ?>
		<?php
	}

	/**
	 * Reads a submitted grid (call after checking nonce and capability).
	 *
	 * @return array{do: string, lang: string, service_id: int, express: string, grid: array<string, array<int, string>>, selected: int[]}
	 */
	public static function read_submit(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- the caller checks the nonce.
		$apply      = isset( $_POST['st_apply'] );
		$action     = isset( $_POST['st_action'] ) ? sanitize_key( wp_unslash( $_POST['st_action'] ) ) : '-1';
		$lang       = isset( $_POST['lang'] ) ? sanitize_text_field( wp_unslash( $_POST['lang'] ) ) : '';
		$service_id = isset( $_POST['service_id'] ) ? (int) $_POST['service_id'] : 0;
		$express    = isset( $_POST['express'] ) ? sanitize_key( wp_unslash( $_POST['express'] ) ) : '';
		$grid       = ( isset( $_POST['tr'] ) && is_array( $_POST['tr'] ) ) ? wp_unslash( $_POST['tr'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per cell in save_grid().
		$sel        = ( isset( $_POST['sel'] ) && is_array( $_POST['sel'] ) ) ? array_map( 'intval', (array) $_POST['sel'] ) : array();
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		return array(
			'do'         => $apply && in_array( $action, array( 'ai', 'human' ), true ) ? $action : 'save',
			'lang'       => $lang,
			'service_id' => $service_id,
			'express'    => $express,
			'grid'       => (array) $grid,
			'selected'   => array_values( array_unique( array_filter( $sel ) ) ),
		);
	}

	/**
	 * Saves the visible grid's changed cells.
	 *
	 * @param array<string, mixed>                 $grid         Language => [ string id => text ] (as posted).
	 * @param array<int, array<string, string>>    $translations String id => [ language => saved text ] (unchanged cells are skipped).
	 * @return int How many were saved.
	 */
	public static function save_grid( array $grid, array $translations ): int {
		$saved = 0;
		foreach ( $grid as $lang => $cells ) {
			$lang = sanitize_text_field( (string) $lang );
			if ( '' === $lang || ! is_array( $cells ) ) {
				continue;
			}
			$pairs = array();
			foreach ( $cells as $id => $value ) {
				$value = wp_kses_post( (string) $value );
				if ( '' !== trim( $value ) && $value !== ( $translations[ (int) $id ][ $lang ] ?? null ) ) {
					$pairs[ (int) $id ] = $value;
				}
			}
			$saved += String_Store::save( $lang, $pairs );
		}

		return $saved;
	}
}
