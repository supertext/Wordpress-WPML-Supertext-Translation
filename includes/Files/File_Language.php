<?php
/**
 * @package Supertext_Wpml
 */

namespace Supertext\Wpml\Files;

defined( 'ABSPATH' ) || exit;

/**
 * A target language and the post's source language, with the Supertext codes the
 * language mapping resolves (null where it can't).
 *
 * @since 0.1.0
 */
class File_Language {
	/**
	 * @param string      $slug          Target language slug.
	 * @param string      $name          Target language name.
	 * @param string      $locale        Target language locale.
	 * @param string|null $target        Supertext target code (Service::resolve_target), null if none.
	 * @param string      $source_locale The post language's locale.
	 * @param string|null $source_target The post language's Supertext target code, null if none.
	 */
	public function __construct(
		public readonly string $slug,
		public readonly string $name,
		public readonly string $locale,
		public readonly ?string $target,
		public readonly string $source_locale,
		public readonly ?string $source_target,
	) {}
}
