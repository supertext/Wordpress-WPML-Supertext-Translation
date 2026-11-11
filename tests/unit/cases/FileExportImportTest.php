<?php
/**
 * @package Supertext_Wpml
 */

namespace Supertext\Wpml\Tests\Cases;

use Supertext\Wpml\Admin\Files_Page;
use Supertext\Wpml\Files\File_Item;
use Supertext\Wpml\Files\File_Language;
use Supertext\Wpml\Files\Translation_File_Glue;
use Supertext\Wpml\Files\Translation_File_Glue_Error;
use Supertext\Wpml\Files\Translation_File_Store;
use Supertext\Wpml\Sdk\Files\FileJob;
use Supertext\Wpml\Sdk\Files\FileSegment;
use Supertext\Wpml\Sdk\Files\TranslationFileError;
use Supertext\Wpml\Sdk\Files\TranslationFiles;
use Supertext\Wpml\Tests\TestCase;

/**
 * Fake store: posts, languages and segments in memory; records writes.
 */
class Fake_File_Store implements Translation_File_Store {
	/** @var array<int, File_Item> */
	public $items = array();
	/** @var array<string, File_Language> */
	public $languages = array();
	/** @var array<int, FileSegment[]> */
	public $segments = array();
	/** @var array<string, bool> post:slug => allowed */
	public $denied = array();
	/** @var array<int, array{0: int, 1: string, 2: array<string, string>}> */
	public $written = array();

	public function item( int $post_id ): ?File_Item {
		return $this->items[ $post_id ] ?? null;
	}

	public function language( int $post_id, string $slug ): ?File_Language {
		return $this->languages[ $slug ] ?? null;
	}

	public function segments( int $post_id, string $slug ): array {
		return $this->segments[ $post_id ] ?? array();
	}

	public function can_edit( int $post_id, string $slug ): bool {
		return empty( $this->denied[ $post_id . ':' . $slug ] );
	}

	public function write( int $post_id, string $slug, array $texts ): array {
		$this->written[] = array( $post_id, $slug, $texts );
		return array();
	}
}

/**
 * Export for translation / import translation (Translation_File_Glue) with a fake store.
 */
class FileExportImportTest extends TestCase {
	/** @var Fake_File_Store */
	private $store;
	/** @var Translation_File_Glue */
	private $glue;

	const TITLE = '{"field":"post_title","id":"","encoding":""}';
	const BODY  = '{"field":"post_content","id":"","encoding":""}';

	protected function setUp(): void {
		parent::setUp();
		$this->store             = new Fake_File_Store();
		$this->store->items[42]  = new File_Item( 42, 'Hello world' );
		$this->store->segments[42] = array(
			new FileSegment( self::TITLE, 'Hello world', true ),
			new FileSegment( self::BODY, '<p>Fresh <b>bread</b> every day.</p>', true ),
		);
		// Mapped (de-CH), resolved by default (fr → fr-FR), no Supertext code (en_IE); source English.
		$this->store->languages['de'] = new File_Language( 'de', 'Deutsch', 'de_CH', 'de-CH', 'en_US', 'en-US' );
		$this->store->languages['fr'] = new File_Language( 'fr', 'Français', 'fr_FR', 'fr-FR', 'en_US', 'en-US' );
		$this->store->languages['ie'] = new File_Language( 'ie', 'English (Ireland)', 'en_IE', null, 'en_US', 'en-US' );
		$this->glue                   = new Translation_File_Glue( $this->store );
	}

	private static function error_code( callable $fn ): string {
		try {
			$fn();
			return 'no error';
		} catch ( Translation_File_Glue_Error $e ) {
			return $e->error_code;
		} catch ( TranslationFileError $e ) {
			return $e->errorCode;
		} catch ( \InvalidArgumentException $e ) {
			return 'invalid_argument:' . $e->getMessage();
		}
	}

	/**
	 * Fills the <target> of trans-units like a translation tool.
	 *
	 * @param array<string, string> $targets Key => target content (XLIFF inline markup).
	 */
	private static function translate( string $xliff, array $targets ): string {
		foreach ( $targets as $key => $target ) {
			$id    = htmlspecialchars( $key, ENT_QUOTES | ENT_XML1 );
			$xliff = (string) preg_replace( '#(<trans-unit id="' . preg_quote( $id, '#' ) . '"[^>]*>\s*<source>.*?</source>)#s', '$1<target>' . $target . '</target>', $xliff, 1 );
		}
		return $xliff;
	}

	public function test_reference_round_trip(): void {
		$this->assertSame( 'post:42:de', Translation_File_Glue::reference( 42, 'de' ) );
		$this->assertSame( array( 'post' => 42, 'lang' => 'pt-br' ), Translation_File_Glue::parse_reference( 'post:42:pt-br' ) );
		foreach ( array( 'post:42', 'post:0:de', 'page:42:de', 'post:42:DE', 'post:42:de:x', '' ) as $bad ) {
			$this->assertNull( Translation_File_Glue::parse_reference( $bad ), $bad );
		}
		$this->assertSame( 'wordpress-42-de-CH.xlf', Translation_File_Glue::file_name( 42, array( 'de-CH' ), 'xliff' ) );
		$this->assertSame( 'wordpress-42.xlf', Translation_File_Glue::file_name( 42, array( 'de-CH', 'fr-FR' ), 'xliff' ) );
		$this->assertSame( 'wordpress-42-de-CH.html', Translation_File_Glue::file_name( 42, array( 'de-CH' ), 'html' ) );
	}

	public function test_export_uses_the_collector_keys_and_the_mapping(): void {
		$file = $this->glue->export( 42, array( 'de', 'fr', 'ie' ), 'xliff' );
		$this->assertSame( 'wordpress-42.xlf', $file->file_name );
		$this->assertSame( 'application/x-xliff+xml', $file->content_type );
		$jobs = TranslationFiles::read( $file->content );
		$this->assertSame(
			array(
				array( 'supertext-wordpress-wpml', 'post:42:de', 'en', 'de-CH' ),
				array( 'supertext-wordpress-wpml', 'post:42:fr', 'en', 'fr-FR' ),
				array( 'supertext-wordpress-wpml', 'post:42:ie', 'en', 'en-IE' ),
			),
			array_map( static fn( $j ) => array( $j->plugin, $j->reference, $j->sourceLanguage, $j->targetLanguage ), $jobs )
		);
		$this->assertSame( array( self::TITLE, self::BODY ), array_map( static fn( $u ) => $u->key, $jobs[0]->units ) );

		$html = $this->glue->export( 42, array( 'de' ), 'html' );
		$this->assertSame( array( 'wordpress-42-de-CH.html', 'text/html; charset=utf-8' ), array( $html->file_name, $html->content_type ) );
		$this->assertSame( array(), $this->store->written );
	}

	public function test_export_refusals(): void {
		$this->assertSame( 'invalid_argument:html_one_language', self::error_code( fn() => $this->glue->export( 42, array( 'de', 'fr' ), 'html' ) ) );
		$this->assertSame( 'invalid_argument:no_language', self::error_code( fn() => $this->glue->export( 42, array(), 'xliff' ) ) );
		$this->assertSame( Translation_File_Glue_Error::NOT_FOUND, self::error_code( fn() => $this->glue->export( 7, array( 'de' ), 'xliff' ) ) );
		$this->assertSame( Translation_File_Glue_Error::NO_LANGUAGE, self::error_code( fn() => $this->glue->export( 42, array( 'xx' ), 'xliff' ) ) );
		$this->store->denied['42:de'] = true;
		$this->assertSame( Translation_File_Glue_Error::NOT_ALLOWED, self::error_code( fn() => $this->glue->export( 42, array( 'de' ), 'xliff' ) ) );
		$this->store->items[43]    = new File_Item( 43, 'Empty' );
		$this->store->segments[43] = array( new FileSegment( 'x', '   ', true ) );
		$this->assertSame( Translation_File_Glue_Error::NOTHING_TO_TRANSLATE, self::error_code( fn() => $this->glue->export( 43, array( 'fr' ), 'xliff' ) ) );
	}

	public function test_import_writes_ok_and_counts_the_rest(): void {
		$xliff = $this->glue->export( 42, array( 'de' ), 'xliff' )->content;
		$done  = self::translate(
			$xliff,
			array(
				self::BODY => '<bpt id="1">&lt;p&gt;</bpt>Frisches <bpt id="2">&lt;b&gt;</bpt>Brot<ept id="2">&lt;/b&gt;</ept> jeden Tag.<ept id="1">&lt;/p&gt;</ept>',
			)
		);
		// A field added since the export.
		$this->store->segments[42][] = new FileSegment( 'new', 'Added later', true );

		$jobs = $this->glue->import( $done, false );
		$this->assertCount( 1, $jobs );
		$job = $jobs[0];
		$this->assertNull( $job->error );
		$this->assertSame( array( 42, 'Deutsch', 1, 0, 1, 0, 1 ), array( $job->post_id, $job->language_name, $job->imported, $job->source_changed, $job->untranslated, $job->invalid, $job->missing ) );
		$this->assertSame( array( array( 42, 'de', array( self::BODY => '<p>Frisches <b>Brot</b> jeden Tag.</p>' ) ) ), $this->store->written );
		$this->assertSame( array( 'Imported: 1', 'Not translated in the file: 1', 'Not in the file: 1' ), Files_Page::summary_lines( $job ) );
		$this->assertSame( 'Hello world → Deutsch', Files_Page::heading( $job ) );
	}

	public function test_source_changed_is_written_only_with_the_option(): void {
		$done = self::translate( $this->glue->export( 42, array( 'de' ), 'xliff' )->content, array( self::TITLE => 'Hallo Welt' ) );
		$this->store->segments[42][0] = new FileSegment( self::TITLE, 'Hello, world', true );

		$job = $this->glue->import( $done, false )[0];
		$this->assertSame( array( 0, 1 ), array( $job->imported, $job->source_changed ) );
		$this->assertSame( array(), $this->store->written );
		$this->assertSame( 'Skipped, source changed since the export: 1', Files_Page::summary_lines( $job )[1] );

		$job = $this->glue->import( $done, true )[0];
		$this->assertSame( array( 1, 0 ), array( $job->imported, $job->source_changed ) );
		$this->assertSame( array( self::TITLE => 'Hallo Welt' ), $this->store->written[0][2] );
	}

	public function test_invalid_markup_is_not_written(): void {
		$done = self::translate( $this->glue->export( 42, array( 'de' ), 'xliff' )->content, array( self::BODY => 'Frisches <ph id="9"/>Brot' ) );
		$job  = $this->glue->import( $done, false )[0];
		$this->assertSame( array( 0, 1, 1 ), array( $job->imported, $job->invalid, $job->untranslated ) );
		$this->assertSame( array(), $this->store->written );
	}

	public function test_jobs_of_other_systems_unknown_references_and_missing_languages(): void {
		$segments = $this->store->segments[42];
		$file     = TranslationFiles::exportXliff(
			array(
				new FileJob( 'Contao-Supertext-Translation', 'post:42:de', 'en', 'de-CH', $segments ),
				new FileJob( Translation_File_Glue::PLUGIN, 'page:12:1', 'en', 'de-CH', $segments ),
				new FileJob( Translation_File_Glue::PLUGIN, 'post:99:de', 'en', 'de-CH', $segments ),
				new FileJob( Translation_File_Glue::PLUGIN, 'post:42:it', 'en', 'it-CH', $segments ),
			)
		);
		$this->store->denied['42:fr'] = true;
		$jobs = $this->glue->import( $file, true );
		$this->assertSame(
			array(
				array( Translation_File_Glue_Error::OTHER_SYSTEM, 'Contao-Supertext-Translation' ),
				array( Translation_File_Glue_Error::NOT_FOUND, '' ),
				array( Translation_File_Glue_Error::NOT_FOUND, '' ),
				array( Translation_File_Glue_Error::NO_LANGUAGE, 'it-CH' ),
			),
			array_map( static fn( $j ) => array( $j->error->error_code, $j->error->argument ), $jobs )
		);
		$this->assertSame( array( 'This file belongs to another system (Contao-Supertext-Translation).' ), Files_Page::summary_lines( $jobs[0] ) );
		$this->assertSame( array( 'The site has no language it-CH.' ), Files_Page::summary_lines( $jobs[3] ) );
		$this->assertSame( array(), $this->store->written );
	}

	public function test_a_user_who_may_not_edit_the_translation_imports_nothing(): void {
		$file                         = $this->glue->export( 42, array( 'fr' ), 'html' )->content;
		$this->store->denied['42:fr'] = true;
		$job                          = $this->glue->import( $file, false )[0];
		$this->assertSame( Translation_File_Glue_Error::NOT_ALLOWED, $job->error->error_code );
		$this->assertSame( array(), $this->store->written );
	}

	public function test_html_imported_unchanged_writes_the_source_back(): void {
		$job = $this->glue->import( $this->glue->export( 42, array( 'fr' ), 'html' )->content, false )[0];
		$this->assertSame( 2, $job->imported );
		$this->assertSame( 'fr', $this->store->written[0][1] );
		$this->assertSame( 'Hello world', $this->store->written[0][2][ self::TITLE ] );
	}

	public function test_file_errors(): void {
		$this->assertSame( TranslationFileError::INVALID_FILE, self::error_code( fn() => $this->glue->import( 'hello', false ) ) );
		$this->assertSame( TranslationFileError::MISSING_METADATA, self::error_code( fn() => $this->glue->import( '<html><head></head><body><p data-st-id="a">x</p></body></html>', false ) ) );
		$this->assertSame( "This file isn't a valid XLIFF or HTML translation file.", Files_Page::error_message( new TranslationFileError( TranslationFileError::INVALID_FILE, 'x' ) ) );
	}

	public function test_files_over_10_mb_are_refused_before_reading(): void {
		$path   = (string) tempnam( sys_get_temp_dir(), 'st' );
		$handle = fopen( $path, 'wb' );
		ftruncate( $handle, Translation_File_Glue::MAX_UPLOAD_BYTES + 1 );
		fclose( $handle );
		try {
			$this->assertSame( Translation_File_Glue_Error::TOO_LARGE, self::error_code( fn() => $this->glue->import_file( $path, false ) ) );
		} finally {
			unlink( $path );
		}
		$this->assertSame( Translation_File_Glue_Error::TOO_LARGE, self::error_code( fn() => $this->glue->import( str_repeat( ' ', Translation_File_Glue::MAX_UPLOAD_BYTES + 1 ), false ) ) );
		$this->assertSame( 'The file is too large (at most 10 MB).', Files_Page::error_message( new Translation_File_Glue_Error( Translation_File_Glue_Error::TOO_LARGE ) ) );
	}
}
