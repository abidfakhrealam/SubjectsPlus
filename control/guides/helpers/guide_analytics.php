<?php
/**
 * usage of one guide for the editor's Analytics panel (JSON), or as an Excel download.
 */
require_once( "../../includes/autoloader.php" );
require_once( "../../includes/config.php" );
require_once( __DIR__ . "/../../includes/staff_only.php" );

use SubjectsPlus\Control\DataExchange\GuideUsage;
use SubjectsPlus\Control\Spreadsheet;

$days = in_array( (int) ( $_GET['days'] ?? 90 ), array( 0, 30, 90, 365 ), true ) ? (int) $_GET['days'] : 90;
$data = GuideUsage::data( (int) ( $_GET['subject_id'] ?? 0 ), $days );
if ( ! $data ) {
	http_response_code( 404 );
	exit;
}
if ( ( $_GET['format'] ?? '' ) === 'xlsx' ) {
	Spreadsheet::send( 'guide-usage', GuideUsage::sheets( $data ), 'xlsx' );
}
header( 'Content-Type: application/json' );
header( 'Cache-Control: no-store' );
echo json_encode( $data );
