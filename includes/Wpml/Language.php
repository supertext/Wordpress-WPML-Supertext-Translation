<?php
/**
 * @package Supertext_Wpml
 */

namespace Supertext\Wpml\Wpml;

defined( 'ABSPATH' ) || exit;

/**
 * One WPML language: its WPML code (`de`, `pt-br`, `zh-hans`), its name and its
 * locale (WPML's *default locale* of the language, `de_DE`; the code when WPML has
 * none). The Supertext code is resolved from the locale (see Language_Settings).
 *
 * @since 0.1.0
 */
class Language {
	/**
	 * Constructor.
	 *
	 * @param string $code   WPML language code.
	 * @param string $name   Display name (in the admin's language where WPML has it).
	 * @param string $locale WordPress locale (`de_DE`), never empty.
	 */
	public function __construct(
		public readonly string $code,
		public readonly string $name,
		public readonly string $locale,
	) {}

	/**
	 * The language as a BCP 47 tag from its locale (`de_DE_formal` → `de-DE`,
	 * `pt_BR` → `pt-BR`, `de` → `de`), for the order API.
	 *
	 * @return string
	 */
	public function w3c(): string {
		$parts = explode( '_', str_replace( '-', '_', $this->locale ) );
		$tag   = strtolower( $parts[0] );
		if ( isset( $parts[1] ) && ! in_array( strtolower( $parts[1] ), array( 'formal', 'informal' ), true ) ) {
			$tag .= '-' . ( 2 === strlen( $parts[1] ) ? strtoupper( $parts[1] ) : $parts[1] );
		}

		return $tag;
	}
}
