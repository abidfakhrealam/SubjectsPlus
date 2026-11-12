<?php
/**
 * change a record's status with a workflow button (from the Workflow box on record.php).
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/autoloader.php';

use SubjectsPlus\Control\Querier;
use SubjectsPlus\Control\Workflow;

if ( session_status() !== PHP_SESSION_ACTIVE ) {
	session_start();
}

$titleId = (int) ( $_POST['title_id'] ?? 0 );
$back    = 'record.php?record_id=' . $titleId;

if ( checkSession() !== 'ok' || empty( $_SESSION['records'] ) && empty( $_SESSION['admin'] ) && empty( $_SESSION['eresource_mgr'] ) ) {
	header( 'Location: ../login.php' );
	exit;
}
if ( $_SERVER['REQUEST_METHOD'] !== 'POST' || ! $titleId ) {
	header( 'Location: index.php' );
	exit;
}
if ( empty( $_POST['csrf'] ) || empty( $_SESSION['sp_wf_record_csrf'] ) || ! hash_equals( $_SESSION['sp_wf_record_csrf'], (string) $_POST['csrf'] ) ) {
	$_SESSION['sp_wf_flash'] = array( 'error', _( "Your session expired. Please try again." ) );
	header( 'Location: ' . $back );
	exit;
}

$transitionId = (int) ( $_POST['transition'] ?? 0 );
$db           = new Querier;
$st           = $db->getConnection()->prepare( "SELECT s.name FROM workflow_transition t JOIN record_status_option s ON s.status_id = t.to_status_id WHERE t.transition_id = ?" );
$st->execute( array( $transitionId ) );
$to = $st->fetchColumn();

$result = $to === false
	? _( "That action is no longer available." )
	: Workflow::changeStatus( $titleId, $to, (string) ( $_POST['comment'] ?? '' ), (int) $_SESSION['staff_id'], 'staff', $transitionId );

$_SESSION['sp_wf_flash'] = $result === true
	? array( 'ok', sprintf( _( "Status changed to %s." ), $to ) )
	: array( 'error', $result );

header( 'Location: ' . $back );
