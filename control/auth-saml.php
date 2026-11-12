<?php
/**
 * return point for SAML / Shibboleth sign-in.
 *
 * The web server (Shibboleth SP "mod_shib" or "mod_auth_mellon") must protect THIS FILE ONLY and
 * pass the person's email in a server variable (default "mail"). Example for mod_shib:
 *
 *   <Location /sp/control/auth-saml.php>
 *       AuthType shibboleth
 *       ShibRequestSetting requireSession 1
 *       Require shib-session
 *   </Location>
 *
 * Without that protection anyone could set the variable, so this page refuses to work unless the
 * provider's 'trust_server_variable' option is explicitly set to TRUE in config.php.
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/autoloader.php';

if ( session_status() !== PHP_SESSION_ACTIVE ) {
	session_start();
}

$providers = sp_auth_providers();
$id        = (string) ( $_GET['provider'] ?? '' );
$fail      = function ( $m ) {
	$_SESSION['sp_auth_error'] = $m;
	header( 'Location: login.php' );
	exit;
};

if ( ! isset( $providers[ $id ] ) || $providers[ $id ]['type'] !== 'saml' || empty( $providers[ $id ]['trust_server_variable'] ) ) {
	$fail( _( "That sign-in method is not available." ) );
}
// the sign-in must have been started from this browser's login page (login CSRF)
if ( ( $_SESSION['sp_auth_pending'] ?? '' ) !== $id ) {
	$fail( _( "Your sign-in session expired. Please try again." ) );
}
$p    = $providers[ $id ];
$attr = (string) ( $p['email_attribute'] ?? 'mail' );
if ( stripos( $attr, 'HTTP_' ) === 0 || ! preg_match( '/^[A-Za-z0-9_.:-]+$/', $attr ) ) {
	$fail( _( "That sign-in method is not available." ) ); // HTTP_* values come from the browser
}
// Server variables only: never read HTTP_* request headers here, browsers can set those.
// Shibboleth may pass multiple values separated by ";".
$raw   = $_SERVER[ $attr ] ?? ( $_SERVER[ 'REDIRECT_' . $attr ] ?? '' );
$email = trim( explode( ';', (string) $raw )[0] );

$msg = sp_external_login( $email, $p );
if ( $msg === 'success' ) {
	unset( $_SESSION['sp_auth_pending'] );
	header( 'Location: ' . sp_after_login_url() );
	exit;
}
$fail( $msg );
