<?php
/**
 * @package Supertext_Wpml
 */

namespace Supertext\Wpml\Tests\Cases;

use Supertext\Wpml\Integrations\YooTheme\Layout;
use Supertext\Wpml\Tests\TestCase;

class LayoutTest extends TestCase {
	private function sampleLayout(): array {
		return array(
			'version'  => '5.0.24',
			'children' => array(
				array(
					'type'  => 'headline',
					'props' => array(
						'content'     => 'Hello',
						'title_style' => 'h1', // not translatable.
					),
				),
				array(
					'type'  => 'code',
					'props' => array(
						'content' => '<?php echo 1; ?>', // code element: content must NOT be translated.
					),
				),
			),
		);
	}

	public function test_is_layout_detects_yootheme_comment(): void {
		$content = '<!-- ' . json_encode( $this->sampleLayout() ) . ' -->';
		$this->assertTrue( Layout::is_layout( $content ) );
		$this->assertFalse( Layout::is_layout( '<p>just content</p>' ) );
	}

	public function test_decode_returns_layout_array(): void {
		$layout  = $this->sampleLayout();
		$content = '<!-- ' . json_encode( $layout ) . ' -->';
		$this->assertSame( $layout, Layout::decode( $content ) );
	}

	public function test_collect_only_translatable_string_props(): void {
		// collect() returns a path => source-string map.
		$entries = Layout::collect( $this->sampleLayout() );

		// Only the headline content; title_style and the code element are excluded.
		$this->assertSame( array( 'Hello' ), array_values( $entries ) );
		$this->assertSame( array( 'children/0/props/content' ), array_keys( $entries ) );
	}

	public function test_map_replaces_translatable_and_leaves_rest_intact(): void {
		$mapped = Layout::map( $this->sampleLayout(), static fn( $path, $value ) => strtoupper( $value ) );

		$this->assertSame( 'HELLO', $mapped['children'][0]['props']['content'] );
		$this->assertSame( 'h1', $mapped['children'][0]['props']['title_style'] );
		$this->assertSame( '<?php echo 1; ?>', $mapped['children'][1]['props']['content'] );
	}

	public function test_encode_writes_json_back_into_the_comment(): void {
		$content = '<!-- ' . json_encode( $this->sampleLayout() ) . ' -->';
		$mapped  = Layout::map( $this->sampleLayout(), static fn( $path, $value ) => strtoupper( $value ) );

		$encoded = Layout::encode( $content, $mapped );

		$this->assertStringStartsWith( '<!-- ', $encoded );
		$this->assertStringContainsString( '"content":"HELLO"', $encoded );
		$this->assertSame( $mapped, Layout::decode( $encoded ) );
	}
}
