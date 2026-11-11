<?php
/**
 * @package Supertext_Wpml
 */

namespace Supertext\Wpml\Tests\Cases;

use Supertext\Wpml\Content\Content_Splitter;
use Supertext\Wpml\Content\Writer;
use Supertext\Wpml\Tests\TestCase;
use Supertext\Wpml\Wpml\Language;

/**
 * What of the post content is translated, and putting it back together.
 */
class ContentSplitterTest extends TestCase {
	private const BLOCKS = "<!-- wp:heading {\"level\":2} -->\n<h2 class=\"wp-block-heading\">Hello <b>world</b></h2>\n<!-- /wp:heading -->\n\n"
		. "<!-- wp:paragraph -->\n<p>First <a href=\"/x\">paragraph</a>.</p>\n<!-- /wp:paragraph -->\n\n"
		. "<!-- wp:separator -->\n<hr class=\"wp-block-separator\"/>\n<!-- /wp:separator -->\n\n"
		. "<!-- wp:shortcode -->\n[gallery ids=\"1,2\"]\n<!-- /wp:shortcode -->\n\n"
		. "<!-- wp:paragraph -->\n<p>&nbsp;</p>\n<!-- /wp:paragraph -->";

	public function test_block_comments_and_empty_blocks_are_not_translated(): void {
		$texts = Content_Splitter::texts( self::BLOCKS );

		$this->assertSame(
			array( '<h2 class="wp-block-heading">Hello <b>world</b></h2>', '<p>First <a href="/x">paragraph</a>.</p>' ),
			array_values( $texts ),
			'one text per block with its formatting; comments, the separator, shortcodes and an empty paragraph are kept'
		);
	}

	public function test_join_without_translations_gives_back_the_content(): void {
		$this->assertSame( self::BLOCKS, Content_Splitter::join( self::BLOCKS, array() ) );
		$classic = "<p>Classic editor.</p>\n\n<p>Second paragraph.</p>\n";
		$this->assertSame( $classic, Content_Splitter::join( $classic, array() ) );
		$this->assertSame( array( '<p>Classic editor.</p>' . "\n\n" . '<p>Second paragraph.</p>' ), array_values( Content_Splitter::texts( $classic ) ) );
		$this->assertSame( '', Content_Splitter::join( '', array() ) );
	}

	public function test_join_replaces_only_the_translated_parts(): void {
		$texts = Content_Splitter::texts( self::BLOCKS );
		$keys  = array_keys( $texts );

		$joined = Content_Splitter::join( self::BLOCKS, array( $keys[1] => '<p>Erster <a href="/x">Absatz</a>.</p>' ) );

		$this->assertStringContainsString( "<!-- wp:paragraph -->\n<p>Erster <a href=\"/x\">Absatz</a>.</p>\n<!-- /wp:paragraph -->", $joined );
		$this->assertStringContainsString( 'Hello <b>world</b>', $joined, 'a part without a translation keeps the source' );
		$this->assertStringContainsString( '<!-- wp:heading {"level":2} -->', $joined );
	}

	public function test_writer_rebuilds_content_and_ignores_unknown_parts(): void {
		$texts  = Content_Splitter::texts( self::BLOCKS );
		$keys   = array_keys( $texts );
		$filled = 0;

		$content = Writer::content(
			self::BLOCKS,
			array(
				'title'                => 'Hallo',
				'content:' . $keys[0]  => '<h2 class="wp-block-heading">Hallo <b>Welt</b></h2>',
				'content:999'          => '<p>stale</p>',
			),
			$filled
		);

		$this->assertSame( 1, $filled );
		$this->assertStringContainsString( 'Hallo <b>Welt</b>', $content );
		$this->assertStringNotContainsString( 'stale', $content );
	}

	public function test_writer_translates_yootheme_layouts_field_by_field(): void {
		$layout  = array(
			'type'     => 'layout',
			'version'  => '4.5.0',
			'children' => array(
				array(
					'type'  => 'headline',
					'props' => array(
						'content' => 'Welcome',
						'link'    => 'https://example.com/',
					),
				),
			),
		);
		$content = '<!-- ' . json_encode( $layout ) . ' -->';
		$filled  = 0;

		$out = Writer::content( $content, array( 'yootheme:children/0/props/content' => 'Willkommen' ), $filled );

		$this->assertSame( 1, $filled );
		$this->assertStringContainsString( '"content":"Willkommen"', $out );
		$this->assertStringContainsString( '"link":"https://example.com/"', $out );
		$this->assertSame( array(), Content_Splitter::texts( $content ), 'the layout comment itself is never sent as one text' );
	}

	public function test_language_w3c_tag_for_the_order_api(): void {
		$this->assertSame( 'de-DE', ( new Language( 'de', 'Deutsch', 'de_DE_formal' ) )->w3c() );
		$this->assertSame( 'pt-BR', ( new Language( 'pt-br', 'Português', 'pt_BR' ) )->w3c() );
		$this->assertSame( 'de-CH', ( new Language( 'de-ch', 'Schweizerdeutsch', 'de_CH_informal' ) )->w3c() );
		$this->assertSame( 'de', ( new Language( 'de', 'Deutsch', 'de' ) )->w3c() );
		$this->assertSame( 'zh-Hans', ( new Language( 'zh-hans', '简体中文', 'zh-Hans' ) )->w3c() );
	}
}
