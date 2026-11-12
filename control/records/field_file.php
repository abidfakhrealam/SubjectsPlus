<?php
/**
 * download a file attached to a record's custom field.
 * Staff who are signed in can download any file; others only files in fields marked public.
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/autoloader.php';

use SubjectsPlus\Control\Querier;
use SubjectsPlus\Control\RecordFields;

if ( session_status() !== PHP_SESSION_ACTIVE ) {
	session_start();
}

$titleId = (int) ( $_GET['title_id'] ?? 0 );
$fieldId = (int) ( $_GET['field_id'] ?? 0 );

$db = new Querier;
$st = $db->getConnection()->prepare( "SELECT v.value, f.is_public, f.type FROM record_field_value v JOIN record_field f ON f.field_id = v.field_id WHERE v.title_id = ? AND v.field_id = ? AND f.type = 'file'" );
$st->execute( array( $titleId, $fieldId ) );
$row = $st->fetch( PDO::FETCH_ASSOC );

$isStaff = checkSession() === 'ok';
// visitors: the field must be public AND the record itself must be shown to the public
$publicRecord = false;
if ( $row && ! $isStaff && (int) $row['is_public'] === 1 ) {
	$vis = $db->getConnection()->prepare( "SELECT l.record_status FROM location_title lt JOIN location l ON l.location_id = lt.location_id WHERE lt.title_id = ?" );
	$vis->execute( array( $titleId ) );
	foreach ( $vis->fetchAll( PDO::FETCH_COLUMN ) as $statusName ) {
		$st = \SubjectsPlus\Control\RecordSettings::status( (string) $statusName );
		if ( $st === null || (int) $st['is_public'] === 1 ) { // unknown/legacy status: treated as public, as on the A-Z list
			$publicRecord = true;
			break;
		}
	}
}
if ( ! $row || ( ! $isStaff && ! $publicRecord ) ) {
	http_response_code( 404 );
	exit( 'Not found' );
}

$path = RecordFields::storageDir( $titleId ) . DIRECTORY_SEPARATOR . basename( $row['value'] );
if ( ! is_file( $path ) ) {
	http_response_code( 404 );
	exit( 'Not found' );
}

$name = RecordFields::originalName( $row['value'] );
$mime = function_exists( 'mime_content_type' ) ? ( mime_content_type( $path ) ?: 'application/octet-stream' ) : 'application/octet-stream';
header( 'Content-Type: ' . $mime );
header( 'Content-Length: ' . filesize( $path ) );
header( 'Content-Disposition: attachment; filename="' . str_replace( '"', '', $name ) . '"' );
header( 'X-Content-Type-Options: nosniff' );
header( 'Cache-Control: private, no-store' );
readfile( $path );
