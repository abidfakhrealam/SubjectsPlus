<?php
/**
 * save show/hide dates for a guide box (called from the box settings panel).
 */
header( 'Content-Type: application/json' );
header( 'Cache-Control: no-store' );

require_once( "../../includes/autoloader.php" );
require_once( "../../includes/config.php" );
require_once( __DIR__ . "/../../includes/staff_only.php" );

use SubjectsPlus\Control\Guide\BoxSchedule;

// only from the editor's own scripts (a cross-site form cannot set this header)
if ( $_SERVER['REQUEST_METHOD'] !== 'POST' || ( $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '' ) !== 'XMLHttpRequest' ) {
	http_response_code( 400 );
	echo json_encode( array( 'ok' => false, 'message' => 'Bad request' ) );
	exit;
}
$id = (int) ( $_POST['pluslet_id'] ?? 0 );
if ( ! $id || ! BoxSchedule::canEdit( $id ) ) {
	http_response_code( 403 );
	echo json_encode( array( 'ok' => false, 'message' => _( "You cannot change this box. Save the guide first if the box is new." ) ) );
	exit;
}
$r = BoxSchedule::save( $id, $_POST['show_from'] ?? '', $_POST['hide_after'] ?? '', $_SESSION['staff_id'] ?? 0 );
if ( $r !== true ) {
	echo json_encode( array( 'ok' => false, 'message' => $r ) );
	exit;
}
echo json_encode( array( 'ok' => true, 'message' => _( "Saved." ), 'label' => BoxSchedule::label( $id ) ) );
