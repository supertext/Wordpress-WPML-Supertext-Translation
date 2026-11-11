<?php
/**
 * @package Supertext_Wpml
 */

namespace Supertext\Wpml\Tests\Cases;

use Brain\Monkey\Functions;
use Supertext\Wpml\Human_Translation\Callback;
use Supertext\Wpml\Tests\TestCase;

class CallbackTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		// secret() reads this option; keep it stable so HMACs are deterministic.
		Functions\when( 'get_option' )->justReturn( 'unit-test-secret' );
	}

	public function test_reference_data_round_trips(): void {
		$ref = Callback::reference_data( 165, 'fr' );

		$this->assertStringStartsWith( '165:fr:', $ref );

		$parsed = self::callPrivate( Callback::class, 'parse_reference_data', array( $ref ) );
		$this->assertSame( array( 'type' => 'post', 'id' => 165, 'lang' => 'fr' ), $parsed );
	}

	public function test_typed_reference_data_round_trips(): void {
		$ref = Callback::reference_data_for( 'gf', 7, 'fr' );

		$this->assertStringStartsWith( 'gf:7:fr:', $ref );

		$parsed = self::callPrivate( Callback::class, 'parse_reference_data', array( $ref ) );
		$this->assertSame( array( 'type' => 'gf', 'id' => 7, 'lang' => 'fr' ), $parsed );
	}

	public function test_typed_reference_data_allows_zero_id(): void {
		// String-translation orders (type `str`) have no backing entity, so they
		// carry id 0. The callback must accept — and round-trip — that tuple, or the
		// write-back is rejected with "Invalid or missing ReferenceData."
		$ref = Callback::reference_data_for( 'str', 0, 'en' );

		$this->assertStringStartsWith( 'str:0:en:', $ref );

		$parsed = self::callPrivate( Callback::class, 'parse_reference_data', array( $ref ) );
		$this->assertSame( array( 'type' => 'str', 'id' => 0, 'lang' => 'en' ), $parsed );
	}

	public function test_typed_reference_data_rejects_negative_id(): void {
		$ref    = Callback::reference_data_for( 'str', 0, 'en' );
		$forged = 'str:-1:en:' . substr( $ref, strrpos( $ref, ':' ) + 1 );
		$this->assertNull( self::callPrivate( Callback::class, 'parse_reference_data', array( $forged ) ) );
	}

	public function test_typed_reference_data_rejects_tampering(): void {
		$ref    = Callback::reference_data_for( 'gf', 7, 'fr' );
		$forged = 'gf:999:fr:' . substr( $ref, strrpos( $ref, ':' ) + 1 );
		$this->assertNull( self::callPrivate( Callback::class, 'parse_reference_data', array( $forged ) ) );
	}

	public function test_tampered_reference_data_is_rejected(): void {
		$ref      = Callback::reference_data( 165, 'fr' );
		$tampered = $ref . 'x';
		$this->assertNull( self::callPrivate( Callback::class, 'parse_reference_data', array( $tampered ) ) );

		// Wrong post id with the same (now invalid) signature.
		$forged = '999:fr:' . substr( $ref, strrpos( $ref, ':' ) + 1 );
		$this->assertNull( self::callPrivate( Callback::class, 'parse_reference_data', array( $forged ) ) );
	}

	public function test_malformed_reference_data_is_rejected(): void {
		$this->assertNull( self::callPrivate( Callback::class, 'parse_reference_data', array( '' ) ) );
		$this->assertNull( self::callPrivate( Callback::class, 'parse_reference_data', array( '165:fr' ) ) );
	}

	public function test_extract_order_ids_from_single_and_array(): void {
		$this->assertSame( array( 5 ), self::callPrivate( Callback::class, 'extract_order_ids', array( array( 'Id' => 5 ) ) ) );
		$this->assertSame(
			array( 1, 2 ),
			self::callPrivate( Callback::class, 'extract_order_ids', array( array( array( 'Id' => 1 ), array( 'Id' => 2 ) ) ) )
		);
		$this->assertSame( array(), self::callPrivate( Callback::class, 'extract_order_ids', array( array() ) ) );
	}

	public function test_extract_reference_data(): void {
		$this->assertSame( 'a', self::callPrivate( Callback::class, 'extract_reference_data', array( array( 'ReferenceData' => 'a' ) ) ) );
		$this->assertSame( 'b', self::callPrivate( Callback::class, 'extract_reference_data', array( array( array( 'ReferenceData' => 'b' ) ) ) ) );
		$this->assertSame( '', self::callPrivate( Callback::class, 'extract_reference_data', array( array( 'foo' => 'bar' ) ) ) );
	}
}
