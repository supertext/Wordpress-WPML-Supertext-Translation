<?php
/**
 * @package Supertext_Wpml
 */

namespace Supertext\Wpml\Tests\Cases;

use PHPUnit\Framework\TestCase;
use Supertext\Wpml\Machine_Translation\Language_Codes;
use Supertext\Wpml\Sdk\V1\SupertextException;

/**
 * The mapping, else an exact match of the locale, else the SDK's default, else that of the
 * locale without its formal/informal variant, else unsupported_language.
 */
class LanguageCodesTest extends TestCase {
	public function test_target_exact_match_ignores_case_and_underscores(): void {
		$this->assertSame( 'de-CH', Language_Codes::target( 'de_CH' ) );
		$this->assertSame( 'en-US', Language_Codes::target( 'en_us' ) );
		$this->assertSame( 'es', Language_Codes::target( 'es' ) );
		$this->assertSame( 'zh-Hant', Language_Codes::target( 'zh_hant' ) );
	}

	public function test_target_without_match_uses_the_default(): void {
		$this->assertSame( 'de-DE', Language_Codes::target( 'de' ) );
		$this->assertSame( 'es', Language_Codes::target( 'es_ES' ) );
		$this->assertSame( 'fi', Language_Codes::target( 'fi_FI' ) );
		$this->assertSame( 'zh-Hant', Language_Codes::target( 'zh_TW' ) );
		$this->assertSame( 'pt-PT', Language_Codes::target( 'pt' ) );
	}

	public function test_wordpress_formal_and_informal_variants_use_the_locale_without_the_variant(): void {
		$this->assertSame( 'de-DE', Language_Codes::target( 'de_DE_formal' ) );
		$this->assertSame( 'de-CH', Language_Codes::target( 'de_CH_informal' ) );
		$this->assertSame( 'nl', Language_Codes::target( 'nl_NL_formal' ) );
		$this->assertSame( 'de-DE', Language_Codes::suggest( 'de_DE_formal' ) );
		$this->assertSame( 'de-CH', Language_Codes::suggest( 'de_CH' ) );
		$this->assertNull( Language_Codes::suggest( 'en_IE' ) );
		$this->assertSame( 'more', Language_Codes::locale_politeness( 'de_DE_formal' ) );
		$this->assertSame( 'less', Language_Codes::locale_politeness( 'de_CH_informal' ) );
		$this->assertSame( '', Language_Codes::locale_politeness( 'de_CH' ) );
	}

	public function test_target_without_match_or_default_is_unsupported_and_names_the_locale(): void {
		foreach ( array( 'en_IE', 'gsw', 'ca_valencia', 'pt_PT_ao90', 'de_LI', 'en_IE_formal' ) as $locale ) {
			try {
				Language_Codes::target( $locale );
				$this->fail( $locale . ' should not resolve without a mapping' );
			} catch ( SupertextException $e ) {
				$this->assertSame( SupertextException::UNSUPPORTED_LANGUAGE, $e->errorCode );
				$this->assertSame( $locale, $e->targetLang );
			}
		}
		$this->assertNull( Language_Codes::resolve( 'en_IE' ) );
	}

	public function test_mapping_wins_and_is_sent_as_given(): void {
		$this->assertSame( 'de-DE', Language_Codes::target( 'de_DE_formal', array( 'de_DE_formal' => 'de-DE' ) ) );
		$this->assertSame( 'de-AT', Language_Codes::target( 'de_CH', array( 'de_CH' => ' de-AT ' ) ) );
		$this->assertSame( 'xx-YY', Language_Codes::target( 'de_CH', array( 'de_CH' => 'xx-YY' ) ), 'a code Supertext adds later works before the SDK knows it' );
		$this->assertSame( 'de-CH', Language_Codes::target( 'de_CH', array( 'de_CH' => '  ' ) ), 'a blank mapping means the suggestion' );
	}

	public function test_source_is_bare_and_one_mapping_serves_both_directions(): void {
		$this->assertSame( 'en', Language_Codes::source( 'en_US' ) );
		$this->assertSame( 'de', Language_Codes::source( 'de_DE_formal' ) );
		$this->assertSame( 'de', Language_Codes::source( 'de_DE_formal', array( 'de_DE_formal' => 'de-CH' ) ) );
		$this->assertSame( 'en', Language_Codes::source( 'en', array( 'en' => 'en-US' ) ) );
		$this->assertSame( '', Language_Codes::source( '' ) );
	}

	public function test_politeness_only_where_supported(): void {
		$this->assertSame( 'more', Language_Codes::politeness( 'de-CH', '', 'de_CH_formal' ) );
		$this->assertSame( 'less', Language_Codes::politeness( 'de-DE', '', 'de_DE_informal' ) );
		$this->assertSame( 'less', Language_Codes::politeness( 'de-DE', 'less', 'de_DE_formal' ), 'the setting wins' );
		$this->assertSame( 'default', Language_Codes::politeness( 'fr-CH', '', 'fr_CH' ) );
		$this->assertSame( 'default', Language_Codes::politeness( 'en-US', 'more', 'en_US' ), 'English has no formal/informal' );
		$this->assertSame( 'default', Language_Codes::politeness( 'xx-YY', 'more', 'xx_YY' ), 'unknown codes get none' );
		$this->assertTrue( Language_Codes::supports_politeness( 'de-CH' ) );
		$this->assertFalse( Language_Codes::supports_politeness( 'en-US' ) );
	}

	public function test_targets_list_for_the_drop_down(): void {
		$targets = Language_Codes::targets();
		$this->assertSame( 'German (Switzerland) (de-CH)', $targets['de-CH'] );
		$this->assertSame( 'Spanish (es)', $targets['es'] );
		$this->assertArrayNotHasKey( 'es-ES', $targets );
		$this->assertSame( 'Albanian (sq)', reset( $targets ), 'sorted by name' );

		if ( class_exists( \Locale::class ) ) {
			$german = Language_Codes::targets( 'de_DE' );
			$this->assertSame( 'Deutsch (Schweiz) (de-CH)', $german['de-CH'] );
			$this->assertSame( 'Swiss German (Zurich) (gsw-u-sd-chzh)', $german['gsw-u-sd-chzh'], 'no readable display name: English' );
		}

		$this->assertSame( 'de-CH', Language_Codes::known_target( 'de_ch' ) );
		$this->assertNull( Language_Codes::known_target( 'es-ES' ) );
	}
}
