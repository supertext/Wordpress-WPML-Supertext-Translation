<?php
/**
 * @package Supertext_Wpml
 */

namespace Supertext\Wpml\Tests\Cases;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Supertext\Wpml\Admin\String_Table;
use Supertext\Wpml\Human_Translation\Human_Strings;
use Supertext\Wpml\Strings\String_Store;
use Supertext\Wpml\Tests\TestCase;

/**
 * Supertext → String translations: saving through WPML's hook and mapping order files.
 */
class StringTranslationsTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'sanitize_text_field' )->alias( static fn( $s ) => trim( (string) $s ) );
		Functions\when( 'wp_kses_post' )->returnArg();
	}

	public function test_save_calls_wpml_hook_as_complete(): void {
		Actions\expectDone( 'wpml_add_string_translation' )->once()->with( 12, 'de', 'Ihr Name', 10 );

		$this->assertSame( 1, String_Store::save( 'de', array( 12 => 'Ihr Name' ) ) );
	}

	public function test_save_skips_empty_values_and_bad_ids(): void {
		Actions\expectDone( 'wpml_add_string_translation' )->never();

		$this->assertSame( 0, String_Store::save( 'de', array( 0 => 'x', 5 => '  ' ) ) );
	}

	public function test_save_grid_saves_only_changed_non_empty_cells(): void {
		Actions\expectDone( 'wpml_add_string_translation' )->once()->with( 13, 'de', 'Ihr Name', 10 );

		$saved = String_Table::save_grid(
			array(
				'de' => array(
					12 => 'Name',     // Unchanged.
					13 => 'Ihr Name', // New.
					14 => '',         // Empty: left alone.
				),
			),
			array( 12 => array( 'de' => 'Name' ) )
		);

		$this->assertSame( 1, $saved );
	}

	public function test_pairs_maps_string_contexts_to_ids(): void {
		$pairs = Human_Strings::pairs(
			array(
				'string:12'                             => 'Nom',
				'string:10'                             => 'S’abonner',
				'string:11'                             => '   ',
				'{"field":"post_title","id":"","x":""}' => 'Titre',
			)
		);

		$this->assertSame( array( 12 => 'Nom', 10 => 'S’abonner' ), $pairs );
	}
}
