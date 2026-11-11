<?php
/**
 * @package Supertext_Wpml
 */

namespace Supertext\Wpml\Tests\Cases;

use Supertext\Wpml\Human_Translation\Writeback;
use Supertext\Wpml\Tests\TestCase;

class WritebackTest extends TestCase {
	public function test_parse_html_maps_translations_by_context(): void {
		$context = '{"field":"post_title","id":"","encoding":""}';
		$html    = '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>'
			. '<div data-st-ctx="' . base64_encode( $context ) . '">Bonjour le monde</div>'
			. '</body></html>';

		$map = self::callPrivate( Writeback::class, 'parse_html', array( $html ) );

		$this->assertSame( array( $context => 'Bonjour le monde' ), $map );
	}

	public function test_parse_html_ignores_unmarked_nodes(): void {
		$html = '<html><body><p>no marker here</p></body></html>';
		$this->assertSame( array(), self::callPrivate( Writeback::class, 'parse_html', array( $html ) ) );
	}

	public function test_final_files_returns_only_final_documents(): void {
		$body = array(
			'Files' => array(
				array( 'Id' => 1, 'Name' => 'source.html', 'DocumentType' => 'Original' ),
				array( 'Id' => 2, 'Name' => 'target.html', 'DocumentType' => 'Final' ),
			),
		);

		$files = self::callPrivate( Writeback::class, 'final_files', array( $body ) );

		$this->assertSame( array( array( 'Id' => 2, 'Name' => 'target.html' ) ), $files );
	}

	public function test_final_files_without_files_is_empty(): void {
		$this->assertSame( array(), self::callPrivate( Writeback::class, 'final_files', array( array() ) ) );
		$this->assertSame( array(), self::callPrivate( Writeback::class, 'final_files', array( array( 'Files' => array() ) ) ) );
	}
}
