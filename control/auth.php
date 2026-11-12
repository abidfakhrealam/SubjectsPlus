<?php
/**
 * staff sign-in through external providers.
 *
 *   auth.php?start=<id>       begin sign-in with an OpenID Connect (Microsoft Entra ID, Google, ...) or SAML provider
 *   auth.php?code=...&state=  OpenID Connect callback (the redirect URI to register is <BaseURL>control/auth.php)
 *   POST auth.php             SIP2 (library barcode + PIN) sign-in from the login page
 *
 * SAML/Shibboleth returns through auth-saml.php, which the web server protects.
 * Providers are configured in config.php ($sp_auth_providers); see INSTALL.md.
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/autoloader.php';

use Jumbojett\OpenIDConnectClient;
use SubjectsPlus\Control\Auth\Sip2Client;

if ( session_status() !== PHP_SESSION_ACTIVE ) {
	session_start();
}

function sp_auth_fail( $message ) {
	$_SESSION['sp_auth_error'] = $message;
	header( 'Location: login.php' );
	exit;
}

function sp_auth_succeed() {
	unset( $_SESSION['sp_auth_pending'] );
	header( 'Location: ' . sp_after_login_url() );
	exit;
}

function sp_auth_oidc_client( array $p ) {
	global $BaseURL;
	$oidc = new OpenIDConnectClient( $p['issuer'], $p['client_id'], $p['client_secret'] ?? null );
	$oidc->setRedirectURL( rtrim( (string) $BaseURL, '/' ) . '/control/auth.php' );
	$oidc->addScope( array( 'openid', 'email', 'profile' ) );
	$oidc->setCodeChallengeMethod( 'S256' ); // PKCE
	if ( ! empty( $p['hosted_domain'] ) ) {
		$oidc->addAuthParam( array( 'hd' => $p['hosted_domain'] ) ); // Google Workspace: suggest the university domain
	}
	if ( ! empty( $p['prompt'] ) ) {
		$oidc->addAuthParam( array( 'prompt' => $p['prompt'] ) );
	}
	if ( isset( $p['verify_tls'] ) && ! $p['verify_tls'] ) { // for local testing only
		$oidc->setVerifyHost( false );
		$oidc->setVerifyPeer( false );
		$oidc->setHttpUpgradeInsecureRequests( false );
	}

	return $oidc;
}

/** Pick the staff email out of the ID token claims (Entra: email / preferred_username / upn; Google: email). */
function sp_auth_oidc_email( OpenIDConnectClient $oidc, array $p ) {
	$claims = $oidc->getVerifiedClaims();
	if ( ! empty( $p['hosted_domain'] ) && ( ! isset( $claims->hd ) || strcasecmp( $claims->hd, $p['hosted_domain'] ) !== 0 ) ) {
		return false; // signed in with an account outside the university's Google Workspace
	}
	if ( isset( $claims->email_verified ) && $claims->email_verified === false ) {
		return '';
	}
	foreach ( array( $p['email_claim'] ?? null, 'email', 'preferred_username', 'upn' ) as $c ) {
		if ( $c && ! empty( $claims->$c ) && filter_var( $claims->$c, FILTER_VALIDATE_EMAIL ) ) {
			return $claims->$c;
		}
	}

	return '';
}

$providers = sp_auth_providers();

// ---------- SIP2: barcode + PIN posted from the login page ----------
if ( $_SERVER['REQUEST_METHOD'] === 'POST' ) {
	$id = (string) ( $_POST['provider'] ?? '' );
	if ( ! isset( $providers[ $id ] ) || $providers[ $id ]['type'] !== 'sip2' ) {
		sp_auth_fail( _( "That sign-in method is not available." ) );
	}
	if ( ! isset( $_POST['csrf'], $_SESSION['sp_login_csrf'] ) || ! hash_equals( $_SESSION['sp_login_csrf'], (string) $_POST['csrf'] ) ) {
		sp_auth_fail( _( "Your session expired. Please try again." ) );
	}
	$p       = $providers[ $id ];
	$barcode = trim( (string) ( $_POST['barcode'] ?? '' ) );
	$pin     = (string) ( $_POST['pin'] ?? '' );
	$key     = 'sip2:' . strtolower( $barcode );
	if ( $barcode === '' || $pin === '' ) {
		sp_auth_fail( _( "Please enter your library card number and PIN." ) );
	}
	if ( sp_login_is_throttled( $key ) ) {
		sp_auth_fail( _( "Too many failed login attempts. Please wait a few minutes and try again, or contact your SubjectsPlus administrator." ) );
	}
	$sip    = new Sip2Client( $p );
	$result = $sip->checkPatron( $barcode, $pin );
	if ( $result === false ) {
		error_log( 'SubjectsPlus SIP2 sign-in error (' . $id . '): ' . $sip->lastError );
		sp_auth_fail( _( "The library system could not be reached. Please try again later." ) );
	}
	if ( ! $result['valid'] ) {
		sp_record_login_attempt( $key, false );
		sp_auth_fail( _( "Library card number or PIN not recognised." ) );
	}
	$email = $result['email'];
	if ( ( $p['email_from'] ?? 'BE' ) === 'barcode' ) {
		// ILS has no email field: build it from the card number, e.g. "12345" + "@library.edu"
		$email = strtolower( $result['patron'] ) . (string) ( $p['email_suffix'] ?? '' );
	}
	sp_record_login_attempt( $key, true );
	$msg = sp_external_login( $email, $p );
	$msg === 'success' ? sp_auth_succeed() : sp_auth_fail( $msg );
}

// ---------- Start sign-in ----------
if ( isset( $_GET['start'] ) ) {
	$id = (string) $_GET['start'];
	if ( ! isset( $providers[ $id ] ) || $providers[ $id ]['type'] === 'sip2' ) {
		sp_auth_fail( _( "That sign-in method is not available." ) );
	}
	$p                           = $providers[ $id ];
	$_SESSION['sp_auth_pending'] = $id;

	if ( $p['type'] === 'saml' ) {
		global $BaseURL;
		$return = rtrim( (string) $BaseURL, '/' ) . '/control/auth-saml.php?provider=' . rawurlencode( $id );
		$login  = (string) ( $p['login_url'] ?? '/Shibboleth.sso/Login' );
		header( 'Location: ' . $login . ( strpos( $login, '?' ) === false ? '?' : '&' ) . 'target=' . rawurlencode( $return ) );
		exit;
	}

	try {
		sp_auth_oidc_client( $p )->authenticate(); // redirects to the identity provider
	} catch ( \Throwable $e ) {
		error_log( 'SubjectsPlus OIDC start error (' . $id . '): ' . $e->getMessage() );
		sp_auth_fail( _( "The sign-in service could not be reached. Please try again later." ) );
	}
	exit;
}

// ---------- OpenID Connect callback ----------
if ( isset( $_GET['code'] ) || isset( $_GET['error'] ) ) {
	$id = (string) ( $_SESSION['sp_auth_pending'] ?? '' );
	if ( ! isset( $providers[ $id ] ) || $providers[ $id ]['type'] !== 'oidc' ) {
		sp_auth_fail( _( "Your sign-in session expired. Please try again." ) );
	}
	if ( isset( $_GET['error'] ) ) {
		sp_auth_fail( _( "Sign-in was cancelled or refused by the sign-in service." ) );
	}
	$p = $providers[ $id ];
	try {
		$oidc = sp_auth_oidc_client( $p );
		$oidc->authenticate(); // checks state, exchanges the code, verifies the ID token signature, issuer, audience and nonce
		$email = sp_auth_oidc_email( $oidc, $p );
	} catch ( \Throwable $e ) {
		error_log( 'SubjectsPlus OIDC callback error (' . $id . '): ' . $e->getMessage() );
		sp_auth_fail( _( "Sign-in could not be completed. Please try again." ) );
	}
	if ( $email === false ) {
		sp_auth_fail( sprintf( _( "Please sign in with your %s account." ), htmlspecialchars( $p['hosted_domain'] ) ) );
	}
	$msg = sp_external_login( $email, $p );
	$msg === 'success' ? sp_auth_succeed() : sp_auth_fail( $msg );
}

header( 'Location: login.php' );
