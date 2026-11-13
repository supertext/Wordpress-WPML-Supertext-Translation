<?php
/**
 * @package Supertext_Wpml
 */

namespace Supertext\Wpml\Content;

defined( 'ABSPATH' ) || exit;

use WP_Block_Parser_Block;

/**
 * Texts kept in block attributes instead of the block's HTML, such as the fields of
 * ACF blocks (`<!-- wp:acf/text {"data":{"text":"…"}} /-->`). Content_Splitter never
 * sends HTML comments, so without this those texts stay in the source language.
 *
 * The texts come from WPML's public block hooks, the same ones WPML's own translation
 * editor uses: `wpml_found_strings_in_block` lists a block's texts, and
 * `wpml_update_strings_in_block` writes their translations back. ACF Multilingual
 * (ACFML) answers them for ACF blocks and follows each field's translation preference
 * (Translate, Copy, Copy once, Don't translate). Without ACFML, ACF blocks are read by
 * Acf_Block_Data: every text field value, never the `_…` field references, numbers,
 * switches or links.
 *
 * Each text's context is `block:<id>`, the id WPML uses for it (the same text in the
 * same block type has the same id, so it is sent once).
 *
 * @since 1.1.0
 */
final class Block_Strings {
	/** Context prefix. */
	public const PREFIX = 'block:';

	/** Translation status WPML calls complete (ICL_TM_COMPLETE). */
	private const COMPLETE = 10;

	/**
	 * The block texts of a post's content.
	 *
	 * @param string $content Post content.
	 * @return array<string, array{text: string, html: bool}> Id => text.
	 */
	public static function collect( string $content ): array {
		if ( ! self::has_blocks( $content ) ) {
			return array();
		}
		$out = array();
		self::walk(
			parse_blocks( $content ),
			static function ( WP_Block_Parser_Block $block ) use ( &$out ): void {
				foreach ( self::strings( $block ) as $string ) {
					$out[ $string['id'] ] = array(
						'text' => $string['value'],
						'html' => $string['html'],
					);
				}
			}
		);

		return $out;
	}

	/**
	 * Puts translated block texts into content (the source content, its other texts
	 * already translated).
	 *
	 * @param string                $content     Content.
	 * @param array<string, string> $map         Context => translated text.
	 * @param string                $target_code WPML code of the target language.
	 * @param int                   $filled      Counter of the texts used.
	 * @return string
	 */
	public static function apply( string $content, array $map, string $target_code, int &$filled = 0 ): string {
		if ( ! self::has_blocks( $content ) ) {
			return $content;
		}
		$translations = array();
		foreach ( $map as $context => $text ) {
			if ( str_starts_with( (string) $context, self::PREFIX ) && '' !== trim( (string) $text ) ) {
				$translations[ substr( (string) $context, strlen( self::PREFIX ) ) ] = (string) $text;
			}
		}
		if ( array() === $translations ) {
			return $content;
		}

		$used    = array();
		$changed = false;
		$blocks  = self::map_blocks(
			parse_blocks( $content ),
			static function ( WP_Block_Parser_Block $block ) use ( $translations, $target_code, &$used, &$changed ): WP_Block_Parser_Block {
				$found = array();
				foreach ( self::strings( $block ) as $string ) {
					if ( isset( $translations[ $string['id'] ] ) ) {
						$found[ $string['id'] ] = $translations[ $string['id'] ];
					}
				}
				if ( array() === $found ) {
					return $block;
				}
				$before  = $block->attrs;
				$block   = self::update( $block, $found, $target_code );
				$changed = $changed || $before !== $block->attrs;
				$used   += array_fill_keys( array_keys( $found ), true );
				return $block;
			}
		);
		if ( ! $changed ) {
			return $content;
		}
		$filled += count( $used );

		return serialize_blocks( $blocks );
	}

	/**
	 * Whether content has blocks with attributes worth parsing.
	 *
	 * @param string $content Content.
	 * @return bool
	 */
	private static function has_blocks( string $content ): bool {
		return str_contains( $content, '<!-- wp:' ) && function_exists( 'parse_blocks' );
	}

	/**
	 * A block's texts: from WPML's hook, else (ACF blocks without ACFML) from the block data.
	 *
	 * @param WP_Block_Parser_Block $block Block.
	 * @return array<int, array{id: string, value: string, html: bool}>
	 */
	private static function strings( WP_Block_Parser_Block $block ): array {
		$out = array();
		/**
		 * WPML: lists the translatable texts of a block (answered by ACFML for ACF blocks).
		 *
		 * @param mixed                 $strings A list of { id, name, value, type } (other plugins add to it).
		 * @param WP_Block_Parser_Block $block   The block.
		 */
		$found = apply_filters( 'wpml_found_strings_in_block', array(), $block );
		foreach ( is_array( $found ) ? $found : array() as $string ) {
			if ( ! is_object( $string ) || ! isset( $string->id, $string->value ) || ! is_string( $string->value ) || '' === trim( $string->value ) ) {
				continue;
			}
			$out[] = array(
				'id'    => (string) $string->id,
				'value' => $string->value,
				'html'  => 'VISUAL' === ( $string->type ?? '' ),
			);
		}
		if ( array() === $out && self::acf_without_acfml( $block ) ) {
			foreach ( Acf_Block_Data::strings( (string) $block->blockName, (array) $block->attrs['data'] ) as $id => $string ) {
				$out[] = array( 'id' => $id ) + $string;
			}
		}

		return $out;
	}

	/**
	 * Writes a block's translations back.
	 *
	 * @param WP_Block_Parser_Block $block        Block.
	 * @param array<string, string> $translations Id => translated text.
	 * @param string                $target_code  WPML code of the target language.
	 * @return WP_Block_Parser_Block
	 */
	private static function update( WP_Block_Parser_Block $block, array $translations, string $target_code ): WP_Block_Parser_Block {
		if ( self::acf_without_acfml( $block ) ) {
			$block->attrs['data'] = Acf_Block_Data::apply( (string) $block->blockName, (array) $block->attrs['data'], $translations );
			return $block;
		}
		$strings = array();
		foreach ( $translations as $id => $text ) {
			$strings[ $id ][ $target_code ] = array(
				'value'  => $text,
				'status' => defined( 'ICL_TM_COMPLETE' ) ? (int) ICL_TM_COMPLETE : self::COMPLETE,
			);
		}
		/**
		 * WPML: writes translated texts into a block.
		 *
		 * @param mixed                 $block   The block (WP_Block_Parser_Block; other plugins return it).
		 * @param array                 $strings Id => [ language => { value, status } ].
		 * @param string                $lang    Target language.
		 */
		$updated = apply_filters( 'wpml_update_strings_in_block', $block, $strings, $target_code );

		return $updated instanceof WP_Block_Parser_Block ? $updated : $block;
	}

	/**
	 * Whether a block is an ACF block whose data we read ourselves (no ACFML).
	 *
	 * @param WP_Block_Parser_Block $block Block.
	 * @return bool
	 */
	private static function acf_without_acfml( WP_Block_Parser_Block $block ): bool {
		return ! defined( 'ACFML_VERSION' )
			&& str_starts_with( (string) $block->blockName, 'acf/' )
			&& isset( $block->attrs['data'] ) && is_array( $block->attrs['data'] );
	}

	/**
	 * Calls a function for every block, inner blocks included.
	 *
	 * @param array<int, array<string, mixed>> $blocks   Parsed blocks.
	 * @param callable                         $callback Gets each block.
	 * @return void
	 */
	private static function walk( array $blocks, callable $callback ): void {
		self::map_blocks(
			$blocks,
			static function ( WP_Block_Parser_Block $block ) use ( $callback ): WP_Block_Parser_Block {
				$callback( $block );
				return $block;
			}
		);
	}

	/**
	 * Maps every block, inner blocks included, keeping the parsed array shape.
	 *
	 * @param array<int, array<string, mixed>> $blocks   Parsed blocks.
	 * @param callable                         $callback Block => block.
	 * @return array<int, array<string, mixed>>
	 */
	private static function map_blocks( array $blocks, callable $callback ): array {
		foreach ( $blocks as $i => $block ) {
			if ( empty( $block['blockName'] ) ) {
				continue;
			}
			$object = new WP_Block_Parser_Block(
				(string) $block['blockName'],
				is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array(),
				self::map_blocks( is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : array(), $callback ),
				(string) ( $block['innerHTML'] ?? '' ),
				is_array( $block['innerContent'] ?? null ) ? $block['innerContent'] : array()
			);
			$object = $callback( $object );

			$blocks[ $i ] = array(
				'blockName'    => $object->blockName,
				'attrs'        => $object->attrs,
				'innerBlocks'  => $object->innerBlocks,
				'innerHTML'    => $object->innerHTML,
				'innerContent' => $object->innerContent,
			);
		}

		return $blocks;
	}
}
