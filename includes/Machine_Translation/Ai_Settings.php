<?php
/**
 * @package Supertext_Wpml
 */

namespace Supertext\Wpml\Machine_Translation;

defined( 'ABSPATH' ) || exit;

use Supertext\Wpml\PluginHealth\KeyCheck;
use Supertext\Wpml\Reporting\Commando;
use Supertext\Wpml\Sdk\V1\SupertextException;
use Supertext\Wpml\Wpml\Language;
use Supertext\Wpml\Wpml\Wpml;

/**
 * The AI translation settings (option `supertext_wpml_ai`): API key, environment,
 * and per WPML language the Supertext language, formality and Translation review.
 * Shown in the *AI translation* section of **Supertext → Settings**.
 *
 * WPML has no machine-translation service API for third parties (its own engines
 * run on WPML's servers), so — unlike the Polylang plugin, whose settings live in
 * Polylang's machine-translation screen — everything is on the plugin's own page.
 *
 * @since 0.1.0
 */
class Ai_Settings {
	/** Option name. */
	const OPTION = 'supertext_wpml_ai';

	/** Settings API group. */
	const GROUP = 'supertext_wpml_ai_group';

	/**
	 * Registers the setting and the save hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_init', array( self::class, 'register' ) );
		// The key check and the version report run when the key or environment is saved.
		add_action( 'update_option_' . self::OPTION, array( self::class, 'after_save' ), 10, 2 );
		add_action( 'add_option_' . self::OPTION, array( self::class, 'after_add' ), 10, 2 );
	}

	/**
	 * Registers the option with the Settings API.
	 *
	 * @return void
	 */
	public static function register(): void {
		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( self::class, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	/**
	 * Defaults.
	 *
	 * @return array{api_key: string, endpoint: string, languages: array<string, string>, politeness: array<string, string>, review: array<string, bool>}
	 */
	public static function defaults(): array {
		return array(
			'api_key'    => '',
			'endpoint'   => '',
			'languages'  => array(),
			'politeness' => array(),
			'review'     => array(),
		);
	}

	/**
	 * The stored options merged with the defaults. `SUPERTEXT_API_KEY` (constant, else
	 * environment variable) is used while no key is saved.
	 *
	 * @return array{api_key: string, endpoint: string, languages: array<string, string>, politeness: array<string, string>, review: array<string, bool>}
	 */
	public static function get(): array {
		$stored = get_option( self::OPTION, array() );
		$out    = array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
		foreach ( array( 'languages', 'politeness', 'review' ) as $key ) {
			$out[ $key ] = is_array( $out[ $key ] ) ? $out[ $key ] : array();
		}
		$out['api_key']  = (string) $out['api_key'];
		$out['endpoint'] = (string) $out['endpoint'];
		if ( '' === trim( $out['api_key'] ) ) {
			$out['api_key'] = self::fallback_key();
		}

		return $out;
	}

	/**
	 * The API key from `SUPERTEXT_API_KEY` (wp-config.php constant, else environment variable).
	 *
	 * @return string
	 */
	public static function fallback_key(): string {
		if ( defined( 'SUPERTEXT_API_KEY' ) ) {
			return trim( (string) constant( 'SUPERTEXT_API_KEY' ) );
		}
		$env = getenv( 'SUPERTEXT_API_KEY' );

		return false === $env ? '' : trim( $env );
	}

	/**
	 * Whether an API key is set.
	 *
	 * @return bool
	 */
	public static function has_api_key(): bool {
		return '' !== trim( self::get()['api_key'] );
	}

	/**
	 * The AI client for the saved settings.
	 *
	 * @return Client
	 */
	public static function client(): Client {
		return new Client( self::get() );
	}

	/**
	 * Keeps only valid values: a Supertext language must be one of Supertext's targets
	 * (stored in Supertext's spelling, anything else becomes '' = the suggestion),
	 * formality only `more`/`less` and only for a target that offers it, Translation
	 * review a boolean per language, the endpoint one of the environments.
	 *
	 * @param mixed $input Submitted value.
	 * @return array
	 */
	public static function sanitize( $input ): array {
		$input = is_array( $input ) ? $input : array();
		$out   = self::defaults();

		$out['api_key'] = (string) preg_replace( '/^\s*Supertext-Auth-Key\s+/i', '', trim( sanitize_text_field( (string) ( $input['api_key'] ?? '' ) ) ) );
		$endpoint       = (string) ( $input['endpoint'] ?? '' );
		$out['endpoint'] = in_array( $endpoint, Client::ENVIRONMENTS, true ) ? $endpoint : '';

		$languages = isset( $input['languages'] ) && is_array( $input['languages'] ) ? $input['languages'] : array();
		foreach ( $languages as $code => $target ) {
			$out['languages'][ (string) $code ] = is_string( $target ) ? ( Language_Codes::known_target( $target ) ?? '' ) : '';
		}

		$politeness = isset( $input['politeness'] ) && is_array( $input['politeness'] ) ? $input['politeness'] : array();
		foreach ( $politeness as $code => $setting ) {
			$target = $out['languages'][ (string) $code ] ?? '';
			$ok     = in_array( $setting, array( 'more', 'less' ), true ) && ( '' === $target || Language_Codes::supports_politeness( $target ) );

			$out['politeness'][ (string) $code ] = $ok ? (string) $setting : '';
		}

		$review = isset( $input['review'] ) && is_array( $input['review'] ) ? $input['review'] : array();
		foreach ( $review as $code => $on ) {
			$out['review'][ (string) $code ] = true === $on || 1 === $on || ( is_string( $on ) && in_array( strtolower( $on ), array( '1', 'true', 'on', 'yes' ), true ) );
		}

		return $out;
	}

	/**
	 * The Supertext target code for a WPML language: the mapping (the language's
	 * drop-down), else an exact match of its locale (`de_CH` → `de-CH`), else the
	 * SDK's default (`de_DE` → `de-DE`, `es_ES` → `es`), else that of the locale
	 * without its formal/informal variant, see Language_Codes. The
	 * `supertext_wpml_language_code` filter can set or change the code (it wins over
	 * the drop-down); '' means "not supported".
	 *
	 * @param Language              $language The language.
	 * @param array<string, string> $mapping  WPML code => Supertext code.
	 * @return string
	 * @throws SupertextException `unsupported_language` (nothing is sent) when there is no code.
	 */
	public static function resolve_target( Language $language, array $mapping ): string {
		$mapped = (string) ( $mapping[ $language->code ] ?? '' );
		$code   = Language_Codes::resolve( $language->locale, '' !== trim( $mapped ) ? array( $language->locale => $mapped ) : array() ) ?? '';

		/**
		 * Filters the Supertext target code of a WPML language.
		 *
		 * @param string   $code     The mapped, matching or default code, '' when the language has none.
		 * @param Language $language The language (code, name, locale).
		 */
		$code = trim( (string) apply_filters( 'supertext_wpml_language_code', $code, $language ) );
		if ( '' === $code ) {
			throw new SupertextException( SupertextException::UNSUPPORTED_LANGUAGE, targetLang: $language->locale );
		}

		return $code;
	}

	/**
	 * The Supertext code of a language, null when it has none.
	 *
	 * @param Language $language The language.
	 * @return string|null
	 */
	public static function target_or_null( Language $language ): ?string {
		try {
			return self::resolve_target( $language, self::get()['languages'] );
		} catch ( SupertextException $e ) {
			return null;
		}
	}

	/**
	 * After the option is first added: the same as an update.
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  New value.
	 * @return void
	 */
	public static function after_add( $option, $value ): void {
		self::after_save( array(), $value );
	}

	/**
	 * After a save: the key check and version report (Commando Center), its result as
	 * a settings notice.
	 *
	 * @param mixed $old_value Old value.
	 * @param mixed $value     New value.
	 * @return void
	 */
	public static function after_save( $old_value, $value ): void {
		$value = is_array( $value ) ? $value : array();
		$key   = trim( (string) ( $value['api_key'] ?? '' ) );
		$check = Commando::after_settings_saved( '' !== $key ? $key : self::fallback_key(), (string) ( $value['endpoint'] ?? '' ) );
		if ( null === $check || ! function_exists( 'add_settings_error' ) ) {
			return;
		}
		$message = Commando::message( $check, false );
		add_settings_error(
			self::OPTION,
			'supertext_key_check',
			$message['text'],
			KeyCheck::OK === $check->status ? 'success' : ( 'error' === $message['type'] ? 'error' : 'warning' )
		);
	}

	/**
	 * Prints the AI translation form (API key, environment, language mapping).
	 *
	 * @return void
	 */
	public static function render_form(): void {
		$options  = self::get();
		$stored   = get_option( self::OPTION, array() );
		$own_key  = is_array( $stored ) ? (string) ( $stored['api_key'] ?? '' ) : '';
		$endpoint = $options['endpoint'];
		$name     = self::OPTION;
		?>
		<form method="post" action="options.php">
			<?php settings_fields( self::GROUP ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="supertext-ai-api-key"><?php esc_html_e( 'API key', 'supertext-wpml' ); ?></label></th>
					<td>
						<input
							name="<?php echo esc_attr( $name . '[api_key]' ); ?>"
							id="supertext-ai-api-key"
							type="password"
							autocomplete="off"
							class="regular-text"
							value="<?php echo esc_attr( $own_key ); ?>"
						/>
						<p class="description">
							<?php
							printf(
								/* translators: 1: link to create a Supertext account (or log in), 2: link to the Supertext API integrations page. */
								esc_html__( 'Your Supertext API key for AI translation. No Supertext account yet? Create one at %1$s. Generate your API key at %2$s (requires the Admin role).', 'supertext-wpml' ),
								sprintf(
									'<a href="%s" target="_blank" rel="noopener">%s</a>',
									esc_url( 'https://www.supertext.com/person/en/account/signin' ),
									esc_html__( 'supertext.com', 'supertext-wpml' )
								),
								sprintf(
									'<a href="%s" target="_blank" rel="noopener">%s</a>',
									esc_url( 'https://www.supertext.com/en/integrations/api' ),
									esc_html__( 'supertext.com → Integrations → API', 'supertext-wpml' )
								)
							); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- link built from escaped parts.
							?>
						</p>
						<?php if ( '' === $own_key && '' !== self::fallback_key() ) : ?>
							<p class="description"><strong><?php esc_html_e( 'The key from SUPERTEXT_API_KEY on the server is used while this field is empty.', 'supertext-wpml' ); ?></strong></p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="supertext-ai-endpoint"><?php esc_html_e( 'Environment', 'supertext-wpml' ); ?></label></th>
					<td>
						<select name="<?php echo esc_attr( $name . '[endpoint]' ); ?>" id="supertext-ai-endpoint" <?php disabled( null !== Client::environment_override() ); ?>>
							<?php foreach ( Client::ENVIRONMENTS as $key => $url ) : ?>
								<?php $is_selected = null !== Client::environment_override() ? Client::environment_override() === $url : ( ( $endpoint === $url ) || ( '' === $endpoint && 'live' === $key ) ); ?>
								<option value="<?php echo esc_attr( $url ); ?>" <?php selected( $is_selected, true ); ?>>
									<?php echo esc_html( ucfirst( $key ) . ' (' . preg_replace( '#^https?://#', '', rtrim( $url, '/' ) ) . ')' ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Supertext environment for AI translation.', 'supertext-wpml' ); ?></p>
						<?php
						if ( null !== Client::environment_override() ) {
							\Supertext\Wpml\Admin\Settings::print_server_note( 'SUPERTEXT_AI_ENVIRONMENT' );
						}
						?>
					</td>
				</tr>
				<?php self::print_language_mapping( $options ); ?>
			</table>
			<?php submit_button( __( 'Save AI settings', 'supertext-wpml' ), 'primary st-btn-plainfont' ); ?>
		</form>
		<?php
	}

	/**
	 * Prints the WPML language → Supertext language mapping table: per language a
	 * drop-down of Supertext's targets (preselected with the saved code, else the
	 * suggestion, else "— choose —"), formality where Supertext offers it, the
	 * Translation review box and the resulting code.
	 *
	 * @param array $options The options.
	 * @return void
	 */
	private static function print_language_mapping( array $options ): void {
		$name       = self::OPTION;
		$mapping    = $options['languages'];
		$politeness = $options['politeness'];
		$review     = $options['review'];
		$languages  = Wpml::languages();
		$ui_locale  = function_exists( 'get_user_locale' ) ? get_user_locale() : '';
		$targets    = Language_Codes::targets( $ui_locale );
		?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Language mapping', 'supertext-wpml' ); ?></th>
			<td>
				<?php if ( array() === $languages ) : ?>
					<p class="description"><?php esc_html_e( 'No WPML languages yet. Activate WPML and add your languages under WPML → Languages first.', 'supertext-wpml' ); ?></p>
				<?php else : ?>
				<p class="description" style="margin-bottom:8px;">
					<?php esc_html_e( 'Choose the Supertext language for each WPML language. The suggested language is preselected: the one whose code equals the locale (de_CH → de-CH), else the usual one (de → de-DE, es_ES → es, de_DE_formal → de-DE). Languages without a suggestion, such as en_IE or gsw, show “— choose —”; until you choose one, translations into or from them stop with an error and nothing is sent to Supertext.', 'supertext-wpml' ); ?>
				</p>
				<table class="widefat striped supertext-language-mapping" style="max-width:860px;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'WPML language', 'supertext-wpml' ); ?></th>
							<th><?php esc_html_e( 'Supertext language', 'supertext-wpml' ); ?></th>
							<th><?php esc_html_e( 'Formality', 'supertext-wpml' ); ?></th>
							<th><?php esc_html_e( 'Translation review', 'supertext-wpml' ); ?></th>
							<th><?php esc_html_e( 'Status', 'supertext-wpml' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $languages as $language ) : ?>
							<?php
							$code     = $language->code;
							$saved    = Language_Codes::known_target( trim( (string) ( $mapping[ $code ] ?? '' ) ) );
							$selected = $saved ?? Language_Codes::suggest( $language->locale ) ?? '';
							try {
								$resolved = self::resolve_target( $language, '' !== $selected ? array( $code => $selected ) : array() );
							} catch ( SupertextException $e ) {
								$resolved = null;
							}
							$polite = (string) ( $politeness[ $code ] ?? '' );
							if ( '' === $polite ) {
								$polite = Language_Codes::locale_politeness( $language->locale );
							}
							$offer = '' !== $selected && Language_Codes::supports_politeness( $selected );
							$id    = 'supertext-lang-' . $code;
							$pid   = 'supertext-polite-' . $code;
							$rid   = 'supertext-review-' . $code;
							?>
							<tr>
								<td>
									<label for="<?php echo esc_attr( $id ); ?>">
										<?php echo esc_html( $language->name ); ?>
										<code><?php echo esc_html( $language->locale ); ?></code>
									</label>
								</td>
								<td>
									<select
										name="<?php echo esc_attr( $name . '[languages][' . $code . ']' ); ?>"
										id="<?php echo esc_attr( $id ); ?>"
										class="supertext-language-select"
										data-politeness="<?php echo esc_attr( $pid ); ?>"
									>
										<option value="" <?php selected( $selected, '' ); ?>><?php esc_html_e( '— choose —', 'supertext-wpml' ); ?></option>
										<?php foreach ( $targets as $target => $label ) : ?>
											<option
												value="<?php echo esc_attr( $target ); ?>"
												data-politeness="<?php echo Language_Codes::supports_politeness( $target ) ? '1' : '0'; ?>"
												<?php selected( $selected, $target ); ?>
											><?php echo esc_html( $label ); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
								<td>
									<select
										name="<?php echo esc_attr( $name . '[politeness][' . $code . ']' ); ?>"
										id="<?php echo esc_attr( $pid ); ?>"
										aria-label="<?php esc_attr_e( 'Formality', 'supertext-wpml' ); ?>"
										<?php echo $offer ? '' : 'hidden'; ?>
									>
										<option value="" <?php selected( $polite, '' ); ?>><?php esc_html_e( 'Automatic', 'supertext-wpml' ); ?></option>
										<option value="more" <?php selected( $polite, 'more' ); ?>><?php esc_html_e( 'Formal', 'supertext-wpml' ); ?></option>
										<option value="less" <?php selected( $polite, 'less' ); ?>><?php esc_html_e( 'Informal', 'supertext-wpml' ); ?></option>
									</select>
									<span class="description" data-no-politeness="<?php echo esc_attr( $pid ); ?>" <?php echo $offer ? 'hidden' : ''; ?>>&ndash;</span>
								</td>
								<td>
									<?php // The hidden 0 is sent when the box is unticked, so turning review off is saved too. ?>
									<input type="hidden" name="<?php echo esc_attr( $name . '[review][' . $code . ']' ); ?>" value="0" />
									<input
										type="checkbox"
										name="<?php echo esc_attr( $name . '[review][' . $code . ']' ); ?>"
										id="<?php echo esc_attr( $rid ); ?>"
										value="1"
										aria-label="<?php echo esc_attr( sprintf( /* translators: %s: language name, e.g. "Deutsch". */ __( 'Translation review: %s', 'supertext-wpml' ), $language->name ) ); ?>"
										<?php checked( ! empty( $review[ $code ] ) ); ?>
									/>
								</td>
								<td>
									<?php if ( null === $resolved ) : ?>
										<strong style="color:#d63638;"><?php esc_html_e( 'Not mapped: Supertext can\'t translate into or from this language yet', 'supertext-wpml' ); ?></strong>
									<?php elseif ( $resolved !== $selected ) : ?>
										<span style="color:#996800;">&#9888; <?php echo esc_html( $resolved ); ?></span>
										<br /><small><?php esc_html_e( 'Set by the supertext_wpml_language_code filter, which wins over this choice.', 'supertext-wpml' ); ?></small>
									<?php elseif ( null === $saved ) : ?>
										<span style="color:#008a20;">&#10003; <?php echo esc_html( $resolved ); ?></span>
										<br /><small><?php esc_html_e( 'Suggested; saved with the settings.', 'supertext-wpml' ); ?></small>
									<?php else : ?>
										<span style="color:#008a20;">&#10003; <?php echo esc_html( $resolved ); ?></span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description" style="margin-top:8px;">
					<?php esc_html_e( 'Formality is offered only for the languages where Supertext supports it. It is preselected as formal for locales ending in _formal and informal for _informal; “Automatic” lets Supertext decide.', 'supertext-wpml' ); ?>
				</p>
				<p class="description">
					<?php esc_html_e( 'Translation review: translations into a language with this box ticked go through Supertext\'s review service, so reviewers can check and correct them on the website with the Supertext Localization Review extension. Their corrections are used the next time the content is translated.', 'supertext-wpml' ); ?>
				</p>
				<script>
				( function () {
					document.querySelectorAll( '.supertext-language-select' ).forEach( function ( select ) {
						select.addEventListener( 'change', function () {
							var option = select.options[ select.selectedIndex ];
							var offer  = !! option && '1' === option.getAttribute( 'data-politeness' );
							var id     = select.getAttribute( 'data-politeness' );
							var polite = document.getElementById( id );
							var none   = document.querySelector( '[data-no-politeness="' + id + '"]' );
							if ( polite ) {
								polite.hidden = ! offer;
							}
							if ( none ) {
								none.hidden = offer;
							}
						} );
					} );
				}() );
				</script>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}
}
