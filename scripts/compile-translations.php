<?php
/**
 * Compiles languages/*.po into the .mo files WordPress loads (no gettext tools needed).
 *
 *   php scripts/compile-translations.php
 *
 * Handles the subset of PO the plugin's files use: msgctxt-free entries, one
 * msgstr each (no plurals), strings over several lines.
 *
 * @package Supertext_Wpml
 */

$dir = dirname( __DIR__ ) . '/languages';

/**
 * Parses a PO file into msgid => msgstr (the header under '').
 *
 * @param string $file The .po file.
 * @return array<string, string>
 */
function supertext_parse_po( string $file ): array {
	$entries = array();
	$field   = null;
	$current = array(
		'msgid'  => null,
		'msgstr' => null,
	);
	$flush   = static function () use ( &$current, &$entries ): void {
		if ( null !== $current['msgid'] && null !== $current['msgstr'] && ( '' === $current['msgid'] || '' !== $current['msgstr'] ) ) {
			$entries[ $current['msgid'] ] = $current['msgstr'];
		}
		$current = array(
			'msgid'  => null,
			'msgstr' => null,
		);
	};
	foreach ( file( $file, FILE_IGNORE_NEW_LINES ) as $line ) {
		$line = trim( $line );
		if ( '' === $line || '#' === $line[0] ) {
			continue;
		}
		if ( preg_match( '/^(msgid|msgstr) "(.*)"$/', $line, $m ) ) {
			if ( 'msgid' === $m[1] && null !== $current['msgid'] ) {
				$flush();
			}
			$field             = $m[1];
			$current[ $field ] = stripcslashes( $m[2] );
		} elseif ( preg_match( '/^"(.*)"$/', $line, $m ) && null !== $field ) {
			$current[ $field ] .= stripcslashes( $m[1] );
		} else {
			fwrite( STDERR, "$file: cannot parse: $line\n" );
			exit( 1 );
		}
	}
	$flush();

	return $entries;
}

/**
 * Writes a GNU MO file.
 *
 * @param array<string, string> $entries msgid => msgstr.
 * @param string                $file    Target file.
 * @return void
 */
function supertext_write_mo( array $entries, string $file ): void {
	ksort( $entries, SORT_STRING );
	$count  = count( $entries );
	$ids    = '';
	$strs   = '';
	$id_tab = array();
	$st_tab = array();
	foreach ( $entries as $id => $str ) {
		$id_tab[] = array( strlen( (string) $id ), strlen( $ids ) );
		$ids     .= $id . "\0";
		$st_tab[] = array( strlen( $str ), strlen( $strs ) );
		$strs    .= $str . "\0";
	}
	$ids_start  = 28 + 16 * $count;
	$strs_start = $ids_start + strlen( $ids );
	$out        = pack( 'V7', 0x950412de, 0, $count, 28, 28 + 8 * $count, 0, 28 + 16 * $count );
	foreach ( $id_tab as $entry ) {
		$out .= pack( 'V2', $entry[0], $ids_start + $entry[1] );
	}
	foreach ( $st_tab as $entry ) {
		$out .= pack( 'V2', $entry[0], $strs_start + $entry[1] );
	}
	file_put_contents( $file, $out . $ids . $strs );
}

foreach ( glob( $dir . '/*.po' ) as $po ) {
	$entries = supertext_parse_po( $po );
	supertext_write_mo( $entries, substr( $po, 0, -3 ) . '.mo' );
	echo basename( $po ) . ': ' . ( count( $entries ) - 1 ) . " strings\n";
}
