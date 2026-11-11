<?php
/**
 * @package Supertext_Wpml
 */

namespace Supertext\Wpml\Files;

defined( 'ABSPATH' ) || exit;

/**
 * An import or export problem of the plugin's own (the SDK's file errors are TranslationFileError).
 *
 * @since 0.1.0
 */
class Translation_File_Glue_Error extends \RuntimeException {
	/** Nothing to translate in the post. */
	const NOTHING_TO_TRANSLATE = 'nothing_to_translate';
	/** The upload is larger than Translation_File_Glue::MAX_UPLOAD_BYTES. */
	const TOO_LARGE = 'too_large';
	/** The file was exported by another Supertext plugin ($argument = its name). */
	const OTHER_SYSTEM = 'other_system';
	/** The post of the reference doesn't exist (or the reference is unknown). */
	const NOT_FOUND = 'not_found';
	/** The site has no such language ($argument = the language). */
	const NO_LANGUAGE = 'no_language';
	/** The user may not edit the post or its translation. */
	const NOT_ALLOWED = 'not_allowed';

	/**
	 * @param string $error_code One of the constants.
	 * @param string $argument   The plugin or language the message names.
	 */
	public function __construct(
		public readonly string $error_code,
		public readonly string $argument = '',
	) {
		parent::__construct( '' !== $argument ? $error_code . ': ' . $argument : $error_code );
	}
}
