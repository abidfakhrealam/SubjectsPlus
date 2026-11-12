<?php
/**
 * Software bill of materials (CycloneDX 1.5 JSON) for a SubjectsPlus release: the PHP libraries in lib/vendor
 * (from Composer's installed.json) and the browser libraries shipped in assets/ and ckeditor/.
 *
 *   php build/sbom.php > dist/SubjectsPlus-sbom.cdx.json
 */
$root = dirname( __DIR__ );
include $root . '/control/includes/version.php';
$components = array();

$installed = json_decode( (string) @file_get_contents( $root . '/lib/vendor/composer/installed.json' ), true );
foreach ( (array) ( $installed['packages'] ?? $installed ) as $p ) {
	if ( empty( $p['name'] ) ) {
		continue;
	}
	$v            = ltrim( (string) ( $p['version'] ?? '' ), 'v' );
	$components[] = array( 'type' => 'library', 'name' => $p['name'], 'version' => $v, 'purl' => 'pkg:composer/' . $p['name'] . '@' . $v,
	                       'licenses' => array_map( function ( $l ) { return array( 'license' => array( 'id' => $l ) ); }, (array) ( $p['license'] ?? array() ) ) );
}

// browser libraries: version read from the shipped file name or file header
$web = array(
	array( 'jquery', 'MIT', 'assets/jquery', '/^jquery-(\d[\d.]*)\.min\.js$/' ),
	array( 'jquery-migrate', 'MIT', 'assets/jquery', '/^jquery-migrate-(\d[\d.]*)\.min\.js$/' ),
	array( 'jquery-ui', 'MIT', 'assets/jquery', '/^jquery-ui-(\d[\d.]*)\.min\.js$/' ),
	array( 'datatables.net', 'MIT', 'assets/jquery', '/^datatables-(\d[\d.]*)$/' ),
);
foreach ( $web as $w ) {
	foreach ( (array) @scandir( $root . '/' . $w[2] ) as $f ) {
		if ( preg_match( $w[3], (string) $f, $m ) ) {
			$components[] = array( 'type' => 'library', 'name' => $w[0], 'version' => $m[1], 'purl' => 'pkg:npm/' . $w[0] . '@' . $m[1], 'licenses' => array( array( 'license' => array( 'id' => $w[1] ) ) ) );
		}
	}
}
if ( preg_match( '/version:"(\d[\d.]*)/', (string) @file_get_contents( $root . '/ckeditor/ckeditor.js' ), $m ) ) {
	$components[] = array( 'type' => 'library', 'name' => 'ckeditor4', 'version' => $m[1], 'purl' => 'pkg:npm/ckeditor4@' . $m[1],
	                       'licenses' => array( array( 'expression' => 'GPL-2.0-or-later OR LGPL-2.1-or-later OR MPL-1.1' ) ) );
}

echo json_encode( array(
	'bomFormat'    => 'CycloneDX',
	'specVersion'  => '1.5',
	'serialNumber' => 'urn:uuid:' . ( function () {
		$b    = random_bytes( 16 );
		$b[6] = chr( ( ord( $b[6] ) & 0x0f ) | 0x40 );
		$b[8] = chr( ( ord( $b[8] ) & 0x3f ) | 0x80 );

		return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $b ), 4 ) );
	} )(),
	'version'      => 1,
	'metadata'     => array( 'timestamp' => gmdate( 'Y-m-d\TH:i:s\Z' ),
	                         'component' => array( 'type' => 'application', 'name' => 'subjectsplus', 'version' => $sp_version, 'licenses' => array( array( 'license' => array( 'id' => 'GPL-2.0-or-later' ) ) ) ) ),
	'components'   => $components,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
