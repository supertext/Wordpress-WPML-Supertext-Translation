<?php
/**
 * @package Supertext_Wpml
 */

namespace Supertext\Wpml\Files;

defined( 'ABSPATH' ) || exit;

/**
 * A post that can be exported for translation.
 *
 * @since 0.1.0
 */
class File_Item {
	/**
	 * @param int    $id    Post ID.
	 * @param string $title Post title.
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $title,
	) {}
}
