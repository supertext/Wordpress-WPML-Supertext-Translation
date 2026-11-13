<?php
/**
 * @package Supertext_Wpml
 */

namespace Supertext\Wpml\Tests\Cases;

use Supertext\Wpml\Content\Acf_Block_Data;
use PHPUnit\Framework\Attributes\DataProvider;
use Supertext\Wpml\Tests\TestCase;

/**
 * The texts of ACF block data (sites without ACF Multilingual).
 */
class AcfBlockDataTest extends TestCase {
	/**
	 * Data like an ACF "Text" block on a real site: a WYSIWYG field with a shortcode, two switches.
	 *
	 * @var array<string, mixed>
	 */
	private const DATA = array(
		'text'                   => "<h1 class=\"h1\">Do you want to know more?</h1>\r\nSubscribe here.\r\n\r\n[wpforms id=\"2784\" title=\"false\"]",
		'_text'                  => 'field_69a5af6e3439a',
		'remove_spacing_top'     => '0',
		'_remove_spacing_top'    => 'field_6215e82ed73ad',
		'remove_spacing_bottom'  => '1',
		'_remove_spacing_bottom' => 'field_6215e873d73ae',
	);

	public function test_only_text_values_are_collected(): void {
		$strings = Acf_Block_Data::strings( 'acf/text', self::DATA );

		$this->assertSame( array( Acf_Block_Data::id( 'acf/text', self::DATA['text'] ) ), array_keys( $strings ), 'the switches and the _ field references are left out' );
		$this->assertSame( self::DATA['text'], reset( $strings )['value'] );
		$this->assertTrue( reset( $strings )['html'] );
	}

	public function test_ids_match_acfml(): void {
		$this->assertSame( md5( 'acf/text' . 'Hello' ), Acf_Block_Data::id( 'acf/text', 'Hello' ), 'same id as ACFML, so adding ACFML later keeps the contexts' );
	}

	public function test_translations_replace_only_the_texts(): void {
		$id   = Acf_Block_Data::id( 'acf/text', self::DATA['text'] );
		$data = Acf_Block_Data::apply( 'acf/text', self::DATA, array( $id => '<h1 class="h1">Mehr erfahren?</h1>' ) );

		$this->assertSame( '<h1 class="h1">Mehr erfahren?</h1>', $data['text'] );
		$this->assertSame( array_diff_key( self::DATA, array( 'text' => 1 ) ), array_diff_key( $data, array( 'text' => 1 ) ), 'switches and field references unchanged' );
	}

	public function test_repeaters_and_groups_are_walked(): void {
		$data    = array(
			'items'  => array(
				'row-0' => array( 'title' => 'First item', '_title' => 'field_a', 'icon' => 'star.svg' ),
				'row-1' => array( 'title' => 'Second item', '_title' => 'field_a', 'icon' => 'heart.svg' ),
			),
			'_items' => 'field_items',
		);
		$strings = Acf_Block_Data::strings( 'acf/list', $data );

		$this->assertSame( array( 'First item', 'Second item' ), array_column( array_values( $strings ), 'value' ) );

		$out = Acf_Block_Data::apply( 'acf/list', $data, array( Acf_Block_Data::id( 'acf/list', 'Second item' ) => 'Zweites Element' ) );
		$this->assertSame( 'Zweites Element', $out['items']['row-1']['title'] );
		$this->assertSame( 'First item', $out['items']['row-0']['title'], 'a text without translation stays' );
	}

	#[DataProvider( 'values' )]
	public function test_what_counts_as_text( string $value, bool $text ): void {
		$this->assertSame( $text, Acf_Block_Data::is_text( $value ), $value );
	}

	/**
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public static function values(): array {
		return array(
			'sentence'         => array( 'Subscribe to our newsletter.', true ),
			'one word'         => array( 'Newsletter', true ),
			'hyphenated word'  => array( 'E-Mail', true ),
			'rich text'        => array( '<p>Hello <b>world</b></p>', true ),
			'switch'           => array( '1', false ),
			'number'           => array( '42.5', false ),
			'empty'            => array( '  ', false ),
			'field key'        => array( 'field_69a5af6e3439a', false ),
			'link'             => array( 'https://www.supertext.com/en', false ),
			'e-mail address'   => array( 'info@supertext.com', false ),
			'file name'        => array( 'logo.svg', false ),
			'path'             => array( '/wp-content/uploads/x', false ),
			'slug_with_underscore' => array( 'layout_wide', false ),
			'colour'           => array( '#ff6600', false ),
			'tags only'        => array( '<br><hr>', false ),
		);
	}
}
