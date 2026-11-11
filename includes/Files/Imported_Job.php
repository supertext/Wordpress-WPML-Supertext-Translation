<?php
/**
 * @package Supertext_Wpml
 */

namespace Supertext\Wpml\Files;

defined( 'ABSPATH' ) || exit;

/**
 * The result of one job (one post in one language) of an imported file.
 *
 * @since 0.1.0
 */
class Imported_Job {
	/** @var int|null */
	public $post_id = null;
	/** @var string */
	public $title = '';
	/** @var string */
	public $language_name = '';
	/** @var Translation_File_Glue_Error|null The job's error, if it couldn't be imported at all. */
	public $error = null;
	/** @var int */
	public $imported = 0;
	/** @var int */
	public $source_changed = 0;
	/** @var int */
	public $untranslated = 0;
	/** @var int */
	public $invalid = 0;
	/** @var int */
	public $missing = 0;
	/** @var string[] Write-back errors, already translated. */
	public $messages = array();

	/**
	 * @param string $reference       The job's reference.
	 * @param string $target_language The job's target language code.
	 */
	public function __construct(
		public readonly string $reference,
		public readonly string $target_language,
	) {}
}
