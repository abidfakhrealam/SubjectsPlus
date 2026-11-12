<?php
/**
 * accessibility problems and review state of one guide (editor "Check" panel).
 */
header( 'Content-Type: application/json' );
header( 'Cache-Control: no-store' );
require_once( "../../includes/autoloader.php" );
require_once( "../../includes/config.php" );
require_once( __DIR__ . "/../../includes/staff_only.php" );

use SubjectsPlus\Control\Querier;
use SubjectsPlus\Control\Guide\GuideA11y;
use SubjectsPlus\Control\Guide\GuideReviews;

$sid  = (int) ( $_REQUEST['subject_id'] ?? 0 );
$what = (string) ( $_REQUEST['what'] ?? '' );

// may this person edit the guide?
$canEdit = ! empty( $_SESSION['admin'] ) || ! empty( $_SESSION['allguides'] );
if ( ! $canEdit ) {
	$st = ( new Querier )->getConnection()->prepare( "SELECT COUNT(*) FROM staff_subject WHERE subject_id = ? AND staff_id = ?" );
	$st->execute( array( $sid, (int) $_SESSION['staff_id'] ) );
	$canEdit = (int) $st->fetchColumn() > 0;
}

if ( $what === 'a11y' ) {
	echo json_encode( array( 'problems' => GuideA11y::checkGuides( $sid ) ) );
	exit;
}
if ( ! GuideReviews::ready() ) {
	echo json_encode( array( 'ok' => false, 'message' => _( "Apply the database updates (Admin → Database Updates) to use guide reviews." ) ) );
	exit;
}
if ( $what === 'reviewed' && $_SERVER['REQUEST_METHOD'] === 'POST' && ( $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '' ) === 'XMLHttpRequest' ) {
	if ( ! $canEdit ) {
		http_response_code( 403 );
		echo json_encode( array( 'ok' => false, 'message' => _( "Only the guide's editors can mark it as reviewed." ) ) );
		exit;
	}
	GuideReviews::markReviewed( $sid, $_SESSION['staff_id'] ?? 0 );
}
$r = GuideReviews::one( $sid );
if ( $r && $r['last_reviewed'] ) {
	$r['last_reviewed'] = date( 'j M Y', strtotime( $r['last_reviewed'] ) );
}
if ( $r && $r['due'] ) {
	$r['due'] = date( 'j M Y', strtotime( $r['due'] ) );
}
echo json_encode( array( 'ok' => (bool) $r, 'review' => $r ) );
