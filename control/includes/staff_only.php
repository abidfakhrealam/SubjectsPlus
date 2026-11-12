<?php
/**
 * login check for admin helper scripts that are called by JavaScript and do not load
 * includes/header.php (which does this check for normal admin pages). Upstream SubjectsPlus
 * left these open, so anyone could e.g. read guide data, add tabs to a guide or change
 * e-resource records without signing in.
 *
 * Include after includes/config.php. Answers 403 (JSON) when there is no valid staff session.
 */
if ( ! function_exists( 'checkSession' ) ) {
	require_once __DIR__ . '/functions.php';
}
if ( session_status() !== PHP_SESSION_ACTIVE ) {
	session_start();
}
if ( checkSession() !== 'ok' || empty( $_SESSION['staff_id'] ) ) {
	if ( ! headers_sent() ) {
		http_response_code( 403 );
		header( 'Content-Type: application/json' );
	}
	echo json_encode( array( 'error' => 'Not signed in. Please sign in to SubjectsPlus again.' ) );
	exit;
}
// helper scripts are only called by SubjectsPlus' own pages: refuse cross-site requests (CSRF),
// including GET requests, because several helpers change data on GET.
sp_require_same_origin( true );
