<?php
/**
 * @package Supertext_Wpml
 */

namespace Supertext\Wpml\Files;

defined( 'ABSPATH' ) || exit;

use Supertext\Wpml\Sdk\Files\FileSegment;

/**
 * What Translation_File_Glue needs from WordPress and WPML: the post, its
 * languages, its segments (Content\Collector, as for AI translation),
 * the permission check and the write-back. Wpml_File_Store is the real one;
 * the unit tests use a fake.
 *
 * @since 0.1.0
 */
interface Translation_File_Store {
	/**
	 * The post, null when it doesn't exist or has no WPML language.
	 *
	 * @param int $post_id Post ID.
	 * @return File_Item|null
	 */
	public function item( int $post_id ): ?File_Item;

	/**
	 * A WPML language of the site other than the post's own, null when there is none.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $slug    WPML language code.
	 * @return File_Language|null
	 */
	public function language( int $post_id, string $slug ): ?File_Language;

	/**
	 * The post's translatable segments for a target language (key = the Collector's context).
	 *
	 * @param int    $post_id Post ID.
	 * @param string $slug    Target language slug.
	 * @return FileSegment[]
	 */
	public function segments( int $post_id, string $slug ): array;

	/**
	 * Whether the current user may translate the post into the language (edit the
	 * post, and edit its translation or create one).
	 *
	 * @param int    $post_id Post ID.
	 * @param string $slug    Target language slug.
	 * @return bool
	 */
	public function can_edit( int $post_id, string $slug ): bool;

	/**
	 * Writes imported translations into the post's translation, as AI translations are written.
	 *
	 * @param int                   $post_id Post ID.
	 * @param string                $slug    Target language slug.
	 * @param array<string, string> $texts   Segment key => translated text.
	 * @return string[] Errors, already translated.
	 */
	public function write( int $post_id, string $slug, array $texts ): array;
}
