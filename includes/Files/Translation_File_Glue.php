<?php
/**
 * @package Supertext_Wpml
 */

namespace Supertext\Wpml\Files;

defined( 'ABSPATH' ) || exit;

use Supertext\Wpml\Machine_Translation\Language_Codes;
use Supertext\Wpml\Sdk\Files\FileJob;
use Supertext\Wpml\Sdk\Files\ImportedSegment;
use Supertext\Wpml\Sdk\Files\TranslationFileError;
use Supertext\Wpml\Sdk\Files\TranslationFiles;
use Supertext\Wpml\Sdk\V1\SupertextException;

/**
 * Export for translation / import translation (XLIFF 1.2 or HTML) with the
 * Supertext SDK's TranslationFiles. Has no WordPress dependency: the store
 * (Wpml_File_Store) collects the segments with Content\Collector
 * and writes the imported texts back with Content\Writer, as AI and
 * human translations are written.
 *
 * Job reference: `post:<post id>:<WPML language code>`, e.g. `post:42:de`.
 *
 * @since 0.1.0
 */
class Translation_File_Glue {
	/** The job's `plugin`: this repository's name. */
	const PLUGIN = 'supertext-wordpress-wpml';
	/** First part of the download file name. */
	const SHORT_NAME = 'wordpress';
	const FORMAT_XLIFF = 'xliff';
	const FORMAT_HTML  = 'html';
	/** Uploads larger than this are refused before they are read (10 MB). */
	const MAX_UPLOAD_BYTES = 10485760;

	/**
	 * @param Translation_File_Store $store WordPress side.
	 */
	public function __construct( private readonly Translation_File_Store $store ) {}

	/**
	 * The job reference of a post in a language.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $slug    WPML language code.
	 * @return string
	 */
	public static function reference( int $post_id, string $slug ): string {
		return 'post:' . $post_id . ':' . $slug;
	}

	/**
	 * Parses a job reference.
	 *
	 * @param string $reference The reference.
	 * @return array{post: int, lang: string}|null Null: not a reference of this plugin.
	 */
	public static function parse_reference( string $reference ): ?array {
		if ( 1 !== preg_match( '/^post:([1-9][0-9]{0,18}):([a-z0-9_-]{1,64})$/D', $reference, $m ) ) {
			return null;
		}
		return array(
			'post' => (int) $m[1],
			'lang' => $m[2],
		);
	}

	/**
	 * `wordpress-<post>-<target>.xlf` (or `.html`), `wordpress-<post>.xlf` for several targets.
	 *
	 * @param int      $post_id Post ID.
	 * @param string[] $targets Supertext target codes.
	 * @param string   $format  xliff or html.
	 * @return string
	 */
	public static function file_name( int $post_id, array $targets, string $format ): string {
		$name = self::SHORT_NAME . '-' . $post_id;
		if ( 1 === count( $targets ) ) {
			$name .= '-' . preg_replace( '/[^A-Za-z0-9-]+/', '-', (string) reset( $targets ) );
		}
		return $name . ( self::FORMAT_HTML === $format ? '.html' : '.xlf' );
	}

	/**
	 * The download's Content-Type.
	 *
	 * @param string $format xliff or html.
	 * @return string
	 */
	public static function content_type( string $format ): string {
		return self::FORMAT_HTML === $format ? 'text/html; charset=utf-8' : 'application/x-xliff+xml';
	}

	/**
	 * The job's target language: the code the mapping resolves, else the locale with
	 * `-` for `_` (a file may go to any translator, so an unmapped language doesn't
	 * block the export).
	 *
	 * @param File_Language $language The language.
	 * @return string
	 */
	public static function target_code( File_Language $language ): string {
		return $language->target ?? str_replace( '_', '-', $language->locale );
	}

	/**
	 * The job's source language: the post language's code in its source form (as
	 * the AI client sends it), else its locale with `-` for `_`.
	 *
	 * @param File_Language $language The language.
	 * @return string
	 */
	public static function source_code( File_Language $language ): string {
		$locale = $language->source_locale;
		try {
			$code = Language_Codes::source( $locale, null !== $language->source_target ? array( $locale => $language->source_target ) : array() );
		} catch ( SupertextException $e ) {
			$code = '';
		}
		return '' !== $code ? $code : str_replace( '_', '-', $locale );
	}

	/**
	 * Exports a post for translation.
	 *
	 * @param int      $post_id Post ID.
	 * @param string[] $slugs   Target language slugs (HTML: exactly one).
	 * @param string   $format  xliff or html.
	 * @return Exported_File
	 * @throws Translation_File_Glue_Error not_found, no_language, not_allowed, nothing_to_translate.
	 * @throws \InvalidArgumentException Unknown format, no language, HTML with several languages.
	 */
	public function export( int $post_id, array $slugs, string $format ): Exported_File {
		if ( ! in_array( $format, array( self::FORMAT_XLIFF, self::FORMAT_HTML ), true ) ) {
			throw new \InvalidArgumentException( 'format' );
		}
		$slugs = array_values( array_unique( array_map( 'strval', $slugs ) ) );
		if ( array() === $slugs ) {
			throw new \InvalidArgumentException( 'no_language' );
		}
		if ( self::FORMAT_HTML === $format && 1 !== count( $slugs ) ) {
			throw new \InvalidArgumentException( 'html_one_language' );
		}
		if ( null === $this->store->item( $post_id ) ) {
			throw new Translation_File_Glue_Error( Translation_File_Glue_Error::NOT_FOUND );
		}
		$jobs    = array();
		$targets = array();
		foreach ( $slugs as $slug ) {
			$language = $this->store->language( $post_id, $slug );
			if ( null === $language ) {
				throw new Translation_File_Glue_Error( Translation_File_Glue_Error::NO_LANGUAGE, $slug );
			}
			if ( ! $this->store->can_edit( $post_id, $slug ) ) {
				throw new Translation_File_Glue_Error( Translation_File_Glue_Error::NOT_ALLOWED );
			}
			$segments = $this->store->segments( $post_id, $slug );
			if ( array() === $segments ) {
				throw new Translation_File_Glue_Error( Translation_File_Glue_Error::NOTHING_TO_TRANSLATE );
			}
			$target    = self::target_code( $language );
			$targets[] = $target;
			try {
				$jobs[] = new FileJob( self::PLUGIN, self::reference( $post_id, $slug ), self::source_code( $language ), $target, $segments );
			} catch ( TranslationFileError $e ) {
				throw new Translation_File_Glue_Error( Translation_File_Glue_Error::NOTHING_TO_TRANSLATE );
			}
		}
		try {
			$content = self::FORMAT_HTML === $format ? TranslationFiles::exportHtml( $jobs[0] ) : TranslationFiles::exportXliff( $jobs );
		} catch ( TranslationFileError $e ) {
			// invalid_job: only blank segments.
			throw new Translation_File_Glue_Error( Translation_File_Glue_Error::NOTHING_TO_TRANSLATE );
		}
		return new Exported_File( self::file_name( $post_id, $targets, $format ), self::content_type( $format ), $content );
	}

	/**
	 * Imports an uploaded file; files over MAX_UPLOAD_BYTES are refused before reading.
	 *
	 * @param string $path            Path of the uploaded file.
	 * @param bool   $include_changed Also write texts whose source changed since the export.
	 * @return Imported_Job[]
	 * @throws Translation_File_Glue_Error too_large.
	 * @throws TranslationFileError invalid_file, missing_metadata, too_large.
	 */
	public function import_file( string $path, bool $include_changed ): array {
		$size = is_readable( $path ) ? filesize( $path ) : false;
		if ( false === $size ) {
			throw new TranslationFileError( TranslationFileError::INVALID_FILE, 'unreadable upload' );
		}
		if ( $size > self::MAX_UPLOAD_BYTES ) {
			throw new Translation_File_Glue_Error( Translation_File_Glue_Error::TOO_LARGE );
		}
		return $this->import( (string) file_get_contents( $path ), $include_changed ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	/**
	 * Imports a translated file.
	 *
	 * @param string $content         File content.
	 * @param bool   $include_changed Also write texts whose source changed since the export.
	 * @return Imported_Job[] One per job of the file.
	 * @throws Translation_File_Glue_Error too_large.
	 * @throws TranslationFileError invalid_file, missing_metadata, too_large.
	 */
	public function import( string $content, bool $include_changed ): array {
		if ( strlen( $content ) > self::MAX_UPLOAD_BYTES ) {
			throw new Translation_File_Glue_Error( Translation_File_Glue_Error::TOO_LARGE );
		}
		$results = array();
		foreach ( TranslationFiles::read( $content ) as $file ) {
			$job       = new Imported_Job( $file->reference, $file->targetLanguage );
			$results[] = $job;
			if ( self::PLUGIN !== $file->plugin ) {
				$job->error = new Translation_File_Glue_Error( Translation_File_Glue_Error::OTHER_SYSTEM, $file->plugin );
				continue;
			}
			$reference = self::parse_reference( $file->reference );
			$item      = null !== $reference ? $this->store->item( $reference['post'] ) : null;
			if ( null === $reference || null === $item ) {
				$job->error = new Translation_File_Glue_Error( Translation_File_Glue_Error::NOT_FOUND );
				continue;
			}
			$job->post_id = $item->id;
			$job->title   = $item->title;
			$language     = $this->store->language( $item->id, $reference['lang'] );
			if ( null === $language ) {
				$job->error = new Translation_File_Glue_Error( Translation_File_Glue_Error::NO_LANGUAGE, $file->targetLanguage );
				continue;
			}
			$job->language_name = $language->name;
			if ( ! $this->store->can_edit( $item->id, $language->slug ) ) {
				$job->error = new Translation_File_Glue_Error( Translation_File_Glue_Error::NOT_ALLOWED );
				continue;
			}
			$texts = array();
			foreach ( TranslationFiles::importTranslations( $file, $this->store->segments( $item->id, $language->slug ) ) as $segment ) {
				switch ( $segment->status ) {
					case ImportedSegment::OK:
						$texts[ $segment->key ] = (string) $segment->text;
						break;
					case ImportedSegment::SOURCE_CHANGED:
						if ( $include_changed ) {
							$texts[ $segment->key ] = (string) $segment->text;
						} else {
							++$job->source_changed;
						}
						break;
					case ImportedSegment::UNTRANSLATED:
						++$job->untranslated;
						break;
					case ImportedSegment::INVALID_MARKUP:
						++$job->invalid;
						break;
					default:
						++$job->missing;
				}
			}
			if ( array() !== $texts ) {
				$job->messages = $this->store->write( $item->id, $language->slug, $texts );
				$job->imported = count( $texts );
			}
		}
		return $results;
	}
}
