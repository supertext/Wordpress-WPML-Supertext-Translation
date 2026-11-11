<?php
/**
 * @package Supertext_Wpml
 */

namespace Supertext\Wpml\Tests\Cases;

use PHPUnit\Framework\TestCase;

/**
 * The German, French and Italian files: the same strings, complete, with the same
 * placeholders, URLs and "Supertext"; the .mo files compiled from them; and the
 * plugin's Commando Center and language strings all translated.
 */
class TranslationFilesTest extends TestCase {
	const LOCALES = array( 'de_DE', 'fr_FR', 'it_IT' );

	/**
	 * @return array<string, string>
	 */
	private static function po( string $locale ): array {
		$entries = array();
		$id      = null;
		$field   = null;
		$values  = array();
		$file    = dirname( __DIR__, 3 ) . '/languages/supertext-wpml-' . $locale . '.po';
		foreach ( file( $file, FILE_IGNORE_NEW_LINES ) as $line ) {
			if ( preg_match( '/^(msgid|msgstr) "(.*)"$/', $line, $m ) ) {
				if ( 'msgid' === $m[1] && isset( $values['msgid'], $values['msgstr'] ) ) {
					$entries[ $values['msgid'] ] = $values['msgstr'];
				}
				$field            = $m[1];
				$values[ $field ] = stripcslashes( $m[2] );
			} elseif ( preg_match( '/^"(.*)"$/', $line, $m ) && null !== $field ) {
				$values[ $field ] .= stripcslashes( $m[1] );
			}
		}
		if ( isset( $values['msgid'], $values['msgstr'] ) ) {
			$entries[ $values['msgid'] ] = $values['msgstr'];
		}
		unset( $entries[''], $id );

		return $entries;
	}

	/**
	 * @return array<int, string>
	 */
	private static function tokens( string $text ): array {
		preg_match_all( '/%(?:\d+\$)?[sd]|\{[a-z_]+\}|https?:\/\/[^\s)]+|Supertext|SUPERTEXT_[A-Z_]+/', $text, $m );
		sort( $m[0] );

		return $m[0];
	}

	public function test_all_languages_have_the_same_complete_strings(): void {
		$de = self::po( 'de_DE' );
		$this->assertNotEmpty( $de );
		foreach ( self::LOCALES as $locale ) {
			$entries = self::po( $locale );
			$this->assertSame( array_keys( $de ), array_keys( $entries ), $locale );
			foreach ( $entries as $id => $str ) {
				$this->assertNotSame( '', $str, "$locale: $id" );
				$this->assertSame( self::tokens( $id ), self::tokens( $str ), "$locale: placeholders, URLs and names in: $id" );
			}
		}
	}

	public function test_mo_files_are_compiled_from_the_po_files(): void {
		$dir = dirname( __DIR__, 3 ) . '/languages/';
		foreach ( self::LOCALES as $locale ) {
			$mo = (string) file_get_contents( $dir . 'supertext-wpml-' . $locale . '.mo' );
			foreach ( self::po( $locale ) as $id => $str ) {
				$this->assertStringContainsString( "\0" . $id . "\0", "\0" . $mo, "$locale.mo is outdated: run php scripts/compile-translations.php" );
				$this->assertStringContainsString( "\0" . $str . "\0", $mo, "$locale.mo is outdated: run php scripts/compile-translations.php" );
			}
		}
	}

	public function test_all_interface_strings_are_translated(): void {
		$root    = dirname( __DIR__, 3 );
		$entries = self::po( 'de_DE' );

		foreach ( self::plugin_files( $root ) as $file ) {
			$source = (string) file_get_contents( $file );
			preg_match_all( "/(?:__|esc_html__|esc_html_e|esc_attr_e|esc_attr__)\\(\\s*'((?:[^'\\\\]|\\\\.)*)',\\s*'supertext-wpml'/", $source, $m );
			foreach ( $m[1] as $literal ) {
				$id = str_replace( array( "\\'", '\\\\' ), array( "'", '\\' ), $literal );
				$this->assertArrayHasKey( $id, $entries, str_replace( $root, '', $file ) . ": untranslated string: $id" );
			}
		}
	}

	/**
	 * The plugin's own PHP files: the main file plus everything under includes/,
	 * without the vendored copies (Sdk, PluginHealth), which carry no strings of
	 * their own.
	 *
	 * @param string $root The repository root.
	 * @return string[]
	 */
	private static function plugin_files( string $root ): array {
		$files    = array( $root . '/supertext-wpml.php' );
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $root . '/includes', \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			$path = str_replace( '\\', '/', (string) $file );
			if ( 'php' === pathinfo( $path, PATHINFO_EXTENSION )
				&& false === strpos( $path, '/includes/Sdk/' )
				&& false === strpos( $path, '/includes/PluginHealth/' ) ) {
				$files[] = $path;
			}
		}

		sort( $files );

		return $files;
	}
}
