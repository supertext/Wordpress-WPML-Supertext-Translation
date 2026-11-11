<?php
/**
 * @package Supertext_Wpml
 */

namespace Supertext\Wpml\Files;

defined( 'ABSPATH' ) || exit;

use Supertext\Wpml\Content\Collector;
use Supertext\Wpml\Human_Translation\Writeback;
use Supertext\Wpml\Machine_Translation\Ai_Settings;
use Supertext\Wpml\Sdk\Files\FileSegment;
use Supertext\Wpml\Wpml\Wpml;
use WP_Post;

/**
 * The WordPress/WPML side of Translation_File_Glue.
 *
 * Segments are the texts AI translation sends (Content\Collector), keyed by their
 * context; plain-text fields are plain-text segments. Writing goes through
 * Human_Translation\Writeback::save_map (Content\Writer: creates the WPML
 * translation as a draft or updates it).
 *
 * @since 0.1.0
 */
class Wpml_File_Store implements Translation_File_Store {
	/**
	 * {@inheritDoc}
	 */
	public function item( int $post_id ): ?File_Item {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || 'trash' === $post->post_status || '' === Wpml::post_language( $post_id ) ) {
			return null;
		}
		return new File_Item( $post_id, (string) get_the_title( $post ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function language( int $post_id, string $slug ): ?File_Language {
		$target = Wpml::language( $slug );
		$source = Wpml::language( Wpml::post_language( $post_id ) );
		if ( null === $target || null === $source || $target->code === $source->code ) {
			return null;
		}
		return new File_Language(
			$target->code,
			$target->name,
			$target->locale,
			Ai_Settings::target_or_null( $target ),
			$source->locale,
			Ai_Settings::target_or_null( $source )
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function segments( int $post_id, string $slug ): array {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || null === Wpml::language( $slug ) ) {
			return array();
		}
		$segments = array();
		$seen     = array();
		foreach ( Collector::collect( $post, $slug ) as $entry ) {
			// Keys must be unique in a job; contexts are.
			if ( isset( $seen[ $entry['context'] ] ) || '' === trim( $entry['singular'] ) ) {
				continue;
			}
			$seen[ $entry['context'] ] = true;
			$segments[]                = new FileSegment( $entry['context'], $entry['singular'], $entry['html'] );
		}
		return $segments;
	}

	/**
	 * {@inheritDoc}
	 */
	public function can_edit( int $post_id, string $slug ): bool {
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return false;
		}
		$translation = Wpml::post_translation( $post_id, $slug );
		if ( $translation > 0 ) {
			return current_user_can( 'edit_post', $translation );
		}
		$type = get_post_type_object( (string) get_post_type( $post_id ) );
		return null !== $type && current_user_can( $type->cap->create_posts );
	}

	/**
	 * {@inheritDoc}
	 */
	public function write( int $post_id, string $slug, array $texts ): array {
		$post   = get_post( $post_id );
		$target = Wpml::language( $slug );
		if ( ! $post instanceof WP_Post || null === $target ) {
			return array();
		}
		// The SDK returns plain-text segments as plain text: nothing to decode.
		return Writeback::save_map( $post, $target, $texts, null, false )['errors'];
	}
}
