<?php

include("includes/config.php");

global $use_shibboleth;
global $shibboleth_logout;


// Initialize the session.
if ( session_status() !== PHP_SESSION_ACTIVE ) { session_start(); }

if ( ! empty( $_SESSION['staff_id'] ) ) {
	include_once( "includes/autoloader.php" );
	\SubjectsPlus\Control\AuditLog::add( 'auth.logout', 'staff', (string) (int) $_SESSION['staff_id'], 'Signed out: ' . ( $_SESSION['email'] ?? '' ) );
}

// Unset all of the session variables.
$_SESSION = array();

// Also delete the session cookie.

if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time()-42000, '/');
}

// Finally, destroy the session.
session_destroy();

if ($use_shibboleth == TRUE) {
	header("Location:$shibboleth_logout");
} else {
	header("Location:login.php");	
}
exit;

?> 