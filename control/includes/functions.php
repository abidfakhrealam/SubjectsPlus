<?php
require_once __DIR__ . '/i18n.php'; // public-page language (sp__)
require_once __DIR__ . '/security.php'; // escaping, same-origin checks, safe outbound HTTP

use SubjectsPlus\Control\Querier;

// safer session cookies for every page that includes this file (before any session_start()).
// SameSite=Lax still allows the sign-in services (Microsoft, Google, SAML) to return to auth.php.
if ( session_status() === PHP_SESSION_NONE && ! headers_sent() ) {
	ini_set( 'session.use_strict_mode', '1' );
	ini_set( 'session.cookie_httponly', '1' );
	ini_set( 'session.cookie_samesite', 'Lax' );
	if ( ( ! empty( $_SERVER['HTTPS'] ) && $_SERVER['HTTPS'] !== 'off' ) || ( $_SERVER['SERVER_PORT'] ?? '' ) == 443 ) {
		ini_set( 'session.cookie_secure', '1' );
	}
}

include_once( "autoloader.php" );

if ( file_exists( "config.php" ) ) {
	include_once( "config.php" );
}
if ( function_exists( 'sp_apply_error_policy' ) ) {
	sp_apply_error_policy(); // re-apply now that $sp_debug from config.php is known
}
// workflow permission for publishing records; added here so older config.php files get it too
if ( isset( $all_ptags ) && is_array( $all_ptags ) && ! in_array( 'approver', $all_ptags, true ) ) {
	$all_ptags[] = 'approver';
}


//////////////////////////////
// If gettext isn't installed
// just return the string
//////////////////////////////

if ( ! function_exists( "gettext" ) ) {

	function sp__( $string ) {
		return $string;
	}

	function gettext( $string ) {
		return $string;
	}

}

/////////////////////
// Password hashing
// Passwords are stored with password_hash() (bcrypt/argon2, salted, slow).
// Legacy unsalted md5 hashes from SubjectsPlus <= 4.6 are still accepted once,
// then upgraded automatically at the next successful login.
/////////////////////

function sp_hash_password( $plain ) {
	return password_hash( (string) $plain, PASSWORD_DEFAULT );
}

function sp_is_legacy_md5_hash( $stored ) {
	return is_string( $stored ) && preg_match( '/^[a-f0-9]{32}$/i', $stored ) === 1;
}

function sp_verify_password( $plain, $stored ) {
	if ( ! is_string( $stored ) || $stored === '' || $plain === '' ) {
		return false;
	}
	if ( sp_is_legacy_md5_hash( $stored ) ) {
		// SubjectsPlus <= 4.6 hashed md5(scrubData(password)) at login/reset but md5(password) when an
		// admin created the account, so accept either form.
		$stored = strtolower( $stored );

		return hash_equals( $stored, md5( $plain ) ) || hash_equals( $stored, md5( scrubData( $plain ) ) );
	}

	return password_verify( $plain, $stored );
}

function sp_password_needs_rehash( $stored ) {
	return sp_is_legacy_md5_hash( $stored ) || password_needs_rehash( $stored, PASSWORD_DEFAULT );
}

/////////////////////
// Login throttling
// After $sp_login_max_attempts failures (default 5) for the same email or IP within
// $sp_login_window_minutes (default 15), further attempts are refused until the window passes.
// Uses the `login_attempt` table created by tools/migrate.php. If the table is missing,
// throttling is skipped (and logged) rather than locking everyone out.
/////////////////////

function sp_client_ip() {
	return isset( $_SERVER['REMOTE_ADDR'] ) ? substr( (string) $_SERVER['REMOTE_ADDR'], 0, 45 ) : '';
}

function sp_login_is_throttled( $email ) {
	global $sp_login_max_attempts, $sp_login_window_minutes;
	$max    = ! empty( $sp_login_max_attempts ) ? (int) $sp_login_max_attempts : 5;
	$window = ! empty( $sp_login_window_minutes ) ? (int) $sp_login_window_minutes : 15;
	try {
		$db   = new Querier;
		$stmt = $db->getConnection()->prepare( "SELECT
				SUM(email = :email) AS by_email, SUM(ip = :ip) AS by_ip
			FROM login_attempt
			WHERE success = 0 AND attempted_at > (NOW() - INTERVAL $window MINUTE)" );
		$stmt->execute( array( ':email' => strtolower( (string) $email ), ':ip' => sp_client_ip() ) );
		$row = $stmt->fetch( PDO::FETCH_ASSOC );

		return $row && ( (int) $row['by_email'] >= $max || (int) $row['by_ip'] >= $max * 4 );
	} catch ( \Throwable $e ) {
		error_log( 'SubjectsPlus login throttle unavailable (run tools/migrate.php): ' . $e->getMessage() );

		return false;
	}
}

function sp_record_login_attempt( $email, $success ) {
	try {
		$db   = new Querier;
		$stmt = $db->getConnection()->prepare( "INSERT INTO login_attempt (email, ip, success, attempted_at) VALUES (:email, :ip, :ok, NOW())" );
		$stmt->execute( array( ':email' => strtolower( substr( (string) $email, 0, 255 ) ), ':ip' => sp_client_ip(), ':ok' => $success ? 1 : 0 ) );
	} catch ( \Throwable $e ) {
		error_log( 'SubjectsPlus login attempt not recorded: ' . $e->getMessage() );
	}
}

/////////////////////
// Staff sign-in providers
// Configured in config.php with $sp_auth_local and $sp_auth_providers; see INSTALL.md.
/////////////////////

/** Enabled sign-in providers, keyed by id. */
function sp_auth_providers() {
	global $sp_auth_providers;
	$out = array();
	if ( ! empty( $sp_auth_providers ) && is_array( $sp_auth_providers ) ) {
		foreach ( $sp_auth_providers as $p ) {
			if ( ! is_array( $p ) || empty( $p['id'] ) || empty( $p['type'] ) || ( isset( $p['enabled'] ) && ! $p['enabled'] ) ) {
				continue;
			}
			if ( ! in_array( $p['type'], array( 'oidc', 'saml', 'sip2' ), true ) ) {
				continue;
			}
			$out[ preg_replace( '/[^a-z0-9_-]/i', '', $p['id'] ) ] = $p;
		}
	}

	return $out;
}

/** Is the email/password form shown? (default: yes) */
function sp_auth_local_enabled() {
	global $sp_auth_local;

	return ! isset( $sp_auth_local ) || $sp_auth_local;
}

/**
 * Sign in a person already authenticated by an external provider.
 * Only existing, active SubjectsPlus staff accounts can sign in; nobody is created automatically.
 *
 * @return string "success", or an error message for the login page
 */
function sp_external_login( $email, array $provider ) {
	global $sp_auth_allowed_domains;
	$email = strtolower( trim( (string) $email ) );

	if ( $email === '' || ! filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
		return sp__( "The sign-in service did not return an email address." );
	}
	$domains = isset( $provider['allowed_domains'] ) ? (array) $provider['allowed_domains'] : (array) ( $sp_auth_allowed_domains ?? array() );
	if ( $domains ) {
		$domain = substr( strrchr( $email, '@' ), 1 );
		if ( ! in_array( $domain, array_map( 'strtolower', $domains ), true ) ) {
			sp_record_login_attempt( $email, false );

			return sprintf( sp__( "Accounts from %s cannot sign in here." ), htmlspecialchars( $domain ) );
		}
	}
	if ( sp_login_is_throttled( $email ) ) {
		return sp__( "Too many failed login attempts. Please wait a few minutes and try again, or contact your SubjectsPlus administrator." );
	}

	$result = isCool( $email, "", true );
	sp_record_login_attempt( $email, $result === "success" );
	if ( $result === "success" ) {
		$_SESSION['sp_auth_provider'] = $provider['id'];

		return "success";
	}

	return sprintf( sp__( "%s does not have an active SubjectsPlus staff account. Ask your SubjectsPlus administrator to add you." ), htmlspecialchars( $email ) );
}

/** Where to go after signing in (only pages inside this SubjectsPlus control area). */
function sp_after_login_url() {
	$target = isset( $_SESSION['desired_page'] ) ? (string) $_SESSION['desired_page'] : '';
	if ( $target !== '' && preg_match( '#^/[^/\\\\]#', $target ) && strpos( $target, '/control/' ) !== false ) {
		return $target;
	}

	global $BaseURL;

	return rtrim( (string) $BaseURL, '/' ) . '/control/index.php';
}

/////////////////////
// Branding — $sp_brand in config.php; see INSTALL.md
/////////////////////

function sp_brand_color( $c ) {
	$c = trim( (string) $c );
	if ( preg_match( '/^#([0-9a-f]{3})$/i', $c, $m ) ) {
		$c = '#' . $m[1][0] . $m[1][0] . $m[1][1] . $m[1][1] . $m[1][2] . $m[1][2];
	}

	return preg_match( '/^#[0-9a-f]{6}$/i', $c ) ? strtolower( $c ) : '';
}

/** WCAG relative luminance contrast ratio between two #rrggbb colours. */
function sp_brand_contrast( $a, $b ) {
	$lum = function ( $hex ) {
		$out = 0;
		foreach ( array( array( 0.2126, 1 ), array( 0.7152, 3 ), array( 0.0722, 5 ) ) as list( $w, $pos ) ) {
			$v    = hexdec( substr( $hex, $pos, 2 ) ) / 255;
			$v    = $v <= 0.03928 ? $v / 12.92 : pow( ( $v + 0.055 ) / 1.055, 2.4 );
			$out += $w * $v;
		}

		return $out;
	};
	$l1 = $lum( $a );
	$l2 = $lum( $b );

	return ( max( $l1, $l2 ) + 0.05 ) / ( min( $l1, $l2 ) + 0.05 );
}

/** Text colour (white or near-black) that is readable on $bg. */
function sp_brand_on( $bg ) {
	return sp_brand_contrast( $bg, '#ffffff' ) >= sp_brand_contrast( $bg, '#1a1a1a' ) ? '#ffffff' : '#1a1a1a';
}

function sp_brand() {
	global $sp_brand;
	$b = is_array( $sp_brand ?? null ) ? $sp_brand : array();

	return array(
		'name'     => trim( (string) ( $b['name'] ?? '' ) ),
		'logo'     => trim( (string) ( $b['logo'] ?? '' ) ),
		'home_url' => trim( (string) ( $b['home_url'] ?? '' ) ),
		'primary'  => sp_brand_color( $b['primary'] ?? '' ),
		'accent'   => sp_brand_color( $b['accent'] ?? '' ),
		'favicon'  => trim( (string) ( $b['favicon'] ?? '' ) ),
	);
}

/** <style>/<link> tags for the public pages; empty when no branding is configured. */
function sp_brand_head() {
	$b   = sp_brand();
	// language switcher and "Last reviewed" line (all public headers)
	$out = '<style id="sp-public-extra">.sp-lang{float:right;font-size:.95em;margin:.4em 0;padding:2px 10px;background:rgba(255,255,255,.92);border-radius:12px}'
		. '.sp-lang a,.sp-lang strong{color:#1d1d1d!important;text-decoration:none;text-shadow:none}.sp-lang a{text-decoration:underline}'
		. '.sp-lang [lang=hi],:lang(hi){font-family:"Noto Sans Devanagari","Mangal","Nirmala UI",Lato,sans-serif}'
		. '.sp-reviewed{clear:both;color:#555;font-size:.9em;margin:1.5em 0 .5em;padding:.5em 16px 0;border-top:1px solid #ddd}</style>' . "\n";
	if ( $b['favicon'] !== '' ) {
		$out .= '<link rel="icon" href="' . htmlspecialchars( $b['favicon'], ENT_QUOTES ) . '">' . "\n";
	}
	if ( $b['primary'] === '' && $b['accent'] === '' ) {
		return $out;
	}
	$primary = $b['primary'] !== '' ? $b['primary'] : '#ffffff';
	$accent  = $b['accent'] !== '' ? $b['accent'] : $primary;
	// Links sit on white: use the brand colour only if it is readable there (WCAG AA 4.5:1)
	$link = sp_brand_contrast( $primary, '#ffffff' ) >= 4.5 ? $primary : ( sp_brand_contrast( $accent, '#ffffff' ) >= 4.5 ? $accent : '#1d4f91' );

	return $out . '<style id="sp-brand">
:root{--sp-brand-primary:' . $primary . ';--sp-brand-on-primary:' . sp_brand_on( $primary ) . ';--sp-brand-accent:' . $accent . ';--sp-brand-on-accent:' . sp_brand_on( $accent ) . ';--sp-brand-link:' . $link . '}
#header{background:var(--sp-brand-primary);height:auto;min-height:3em}
#header_inner_wrap{height:auto}
#header h1{margin:.2em 0;padding:.2em 0}
#header h1,#header a{color:var(--sp-brand-on-primary);text-shadow:none}
a,a:link,a:visited{color:var(--sp-brand-link)}
.pure-button-primary,.pure-button-topsearch,.button-primary{background:var(--sp-brand-accent)!important;color:var(--sp-brand-on-accent)!important}
.titlebar{border-top:3px solid var(--sp-brand-primary)}
:focus-visible{outline:3px solid var(--sp-brand-accent);outline-offset:2px}
</style>' . "\n";
}

/** Logo link for the public header. */
function sp_brand_logo( $defaultSrc, $defaultHref ) {
	$b    = sp_brand();
	$src  = $b['logo'] !== '' ? $b['logo'] : $defaultSrc;
	$href = $b['home_url'] !== '' ? $b['home_url'] : $defaultHref;
	$alt  = $b['name'] !== '' ? $b['name'] . ' — ' . sp__( "home" ) : sp__( "Home Page" );

	return '<a href="' . htmlspecialchars( $href, ENT_QUOTES ) . '"><img class="main_logo" src="' . htmlspecialchars( $src, ENT_QUOTES ) . '" alt="' . htmlspecialchars( $alt, ENT_QUOTES ) . '" /></a>';
}

/**
 * off-campus link for a licensed resource, using the providers set up in
 * Admin > Off-campus Access (EZproxy, OpenAthens, RemoteXs, RemoteLog, MyLOFT, ...).
 * Falls back to $proxyURL from config.php when no provider is configured.
 */
function sp_access_url( $url, $restriction ) {
	return \SubjectsPlus\Control\AccessLinks::url( $url, $restriction );
}

function checkSession() {

	global $salt;

	if ( isset( $_SESSION['checkit'] ) ) {

		if ( md5( $_SESSION['email'] ) . $salt == $_SESSION['checkit'] ) {
			$result = "ok";
		} else {
			$result = "failure";
		}
	} else {
		$result = "failure";
	}

	return $result;
}


/////////////////////
// Gets info about the user, based on IP or .htaccess, according to your config file
// This is called by control/includes/header.php, and control/login.php
/////////////////////////

function isCool( $emailAdd = "", $password = "", $shibboleth = false ) {

	$db = new Querier;

	global $subcat;
	global $CpanelPath;
	global $PublicPath;
	global $debugger;
	global $salt;


	if ( $shibboleth == true ) {

		$connection = $db->getConnection();
		$statement  = $connection->prepare( "SELECT staff_id, ip, fname, lname, email, user_type_id, ptags, extra
        FROM staff
        WHERE email = :mail AND active = 1" ); // inactive staff cannot sign in via SSO either
		$statement->bindParam( ":mail", $emailAdd );
		$statement->execute();
		$result = $statement->fetchAll( PDO::FETCH_BOTH ); // numeric keys used below, whatever the connection default

	} else {

		// $password is the plain-text password typed by the user (no longer pre-hashed with md5 by login.php).
		$result = array();
		$db     = new Querier;
		$connection = $db->getConnection();
		$statement  = $connection->prepare( "SELECT staff_id, ip, fname, lname, email, user_type_id, ptags, extra, password
        FROM staff
        WHERE email = :mail AND active = 1" );
		$statement->bindValue( ":mail", scrubData( $emailAdd, "email" ) );
		$statement->execute();
		$candidates = $statement->fetchAll( PDO::FETCH_BOTH );

		if ( count( $candidates ) === 1 && sp_verify_password( (string) $password, (string) $candidates[0]['password'] ) ) {
			if ( sp_password_needs_rehash( (string) $candidates[0]['password'] ) ) {
				// Transparently upgrade legacy md5 (or older bcrypt) hashes on successful login.
				$upgrade = $connection->prepare( "UPDATE staff SET password = :hash WHERE staff_id = :id" );
				$upgrade->execute( array( ':hash' => sp_hash_password( (string) $password ), ':id' => $candidates[0]['staff_id'] ) );
			}
			$result = $candidates;
		}

	}

	$numrows = count( $result );

	if ( $numrows > 0 ) {

		$user = $result;
		if ( is_array( $user ) ) {


//set session variables
			if ( session_status() !== PHP_SESSION_ACTIVE ) { session_start(); }
			session_regenerate_id( true ); // delete the pre-login session (session fixation)

// Create session vars for the basic types
			$_SESSION['checkit']      = md5( $user[0][4] ) . $salt;
			$_SESSION['staff_id']     = $user[0][0];
			$_SESSION['ok_ip']        = $user[0][1];
			$_SESSION['fname']        = $user[0][2];
			$_SESSION['lname']        = $user[0][3];
			$_SESSION['email']        = $user[0][4];
			$_SESSION['user_type_id'] = $user[0][5];

// unpack our extra
			if ( $user[0][7] != null ) {
				$jobj            = json_decode( (string) $user[0][7] );
				$_SESSION['css'] = $jobj->{'css'};
			}

// unpack our ptags
			$current_ptags = explode( "|", (string) $user[0][6] );

			foreach ( $current_ptags as $value ) {
				$_SESSION[ $value ] = 1;
			}

			$result = "success";
		}
	} else {

		$result = "failure";
	}


	return $result;
}

///////////////////////////
// A not-awesome way of deciding which jquery to load
// options:  datepicker, filetree, colorbox, hover, ui, ui_styles
///////////////////////////

function generatejQuery( $use_jquery ) {

	global $AssetPath;

// Always load jQuery core, ui, livequery
	// jQuery 3.7.1 + Migrate served locally (was jQuery 1.11.0 from Google's CDN)
	$myjquery = "<script type=\"text/javascript\" src=\"$AssetPath" . "jquery/jquery-3.7.1.min.js\"></script>\n
	<script type=\"text/javascript\" src=\"$AssetPath" . "jquery/jquery-migrate-3.5.2.min.js\"></script>\n
	<script type=\"text/javascript\" src=\"$AssetPath" . "jquery/libs/jquery.livequery.min.js\"></script>\n";

// If there's not an array of values, send 'er back
	if ( ! is_array( $use_jquery ) ) {
		return $myjquery;
	}

// Check to see what additional jquery files need to be loaded
	if ( in_array( "colorbox", $use_jquery ) ) {
		$myjquery .= "<script type=\"text/javascript\" src=\"$AssetPath" . "jquery/libs/jquery.colorbox-min.js\"></script>\n
	<style type=\"text/css\">@import url($AssetPath" . "css/shared/colorbox.css);</style>\n";
	}
	if ( in_array( "hover", $use_jquery ) ) {
		$myjquery .= "<script type=\"text/javascript\" src=\"$AssetPath" . "jquery/libs/jquery.hoverIntent.js\"></script>\n";
	}

	if ( in_array( "ui", $use_jquery ) ) {
		$myjquery .= "<script type=\"text/javascript\" src=\"$AssetPath" . "jquery/jquery-ui-1.13.3.min.js\"></script>"
			. "<script type=\"text/javascript\" src=\"$AssetPath" . "jquery/jquery-ui-compat.js\"></script>"; // tabs('select') for 1.9-era code
	}

	if ( in_array( "ui_styles", $use_jquery ) ) {
		$myjquery .= "<link rel=\"stylesheet\" href=\"$AssetPath" . "css/shared/jquery-ui.css\" type=\"text/css\" media=\"all\" />"
			. "<link rel=\"stylesheet\" href=\"$AssetPath" . "css/shared/jquery-ui-113-fixes.css\" type=\"text/css\" media=\"all\" />";
	}

	// "tablesorter" option removed: no page used it and its files are not shipped (it produced 404s)

	if ( in_array( "sp_legacy", $use_jquery ) ) {
		$myjquery = "<script type=\"text/javascript\" src=\"$AssetPath" . "jquery/libs/jquery.livequery.min.js\"></script>\n
        <script type=\"text/javascript\" src=\"$AssetPath" . "jquery/jquery-ui-1.13.3.min.js\"></script>\n
        <script type=\"text/javascript\" src=\"$AssetPath" . "jquery/jquery-ui-compat.js\"></script>\n
      <link rel=\"stylesheet\" href=\"$AssetPath" . "css/shared/jquery-ui.css\" type=\"text/css\" media=\"all\" />\n      <link rel=\"stylesheet\" href=\"$AssetPath" . "css/shared/jquery-ui-113-fixes.css\" type=\"text/css\" media=\"all\" />\n
      <script type=\"text/javascript\" src=\"$AssetPath" . "jquery/libs/jquery.colorbox-min.js\"></script>\n
      <link rel=\"stylesheet\" href=\"$AssetPath" . "css/shared/colorbox.css\" type=\"text/css\" />\n";
	}

	return $myjquery;
}

function noPermission( $text ) {
	$returnval = "<div id=\"maincontent\">
    <br /><br />
    <div class=\"box\" style=\"width: 50%;\">
    <p>$text</div>
    </div>";

	return $returnval;
}

/// cURL helper function
// used by video ingest module

function curl_get( $url ) {
	$curl = curl_init( $url );
	curl_setopt( $curl, CURLOPT_RETURNTRANSFER, 1 );
	curl_setopt( $curl, CURLOPT_TIMEOUT, 30 );
	curl_setopt( $curl, CURLOPT_SSL_VERIFYPEER, false );

	//added @ symbol to not display if curlopt_followlocation option cannot be set.
	//This function still works without it.
	@curl_setopt( $curl, CURLOPT_FOLLOWLOCATION, 1 );
	$return = curl_exec( $curl );
	curl_close( $curl );

	return $return;
}

///////////////
// Some truncation functions
// TruncByWord is for word-based trunc, Truncate for character-based
//////////////
// Found in talkback

function TruncByWord( $phrase, $max_words ) {
	//remove all tags (if any) so that html is not included
	$phrase = preg_replace( '/<[^>]*>/', '', $phrase );

	$phrase_array = explode( ' ', (string) $phrase );
	if ( count( $phrase_array ) > $max_words && $max_words > 0 ) {
		$phrase = implode( ' ', array_slice( $phrase_array, 0, $max_words ) ) . '...';
	}

	return $phrase;
}

/*
 * string Truncate ( string str , int length)
 * @param  string  str     string to truncate /abbreviate
 * @param  int     length  length to truncate /abbreviate to
 * @param  string  traling string to use for trailing on truncated strings
 * @return string  abbreviated string
 */

function Truncate( $str, $length = 10, $trailing = ' [more]' ) {
// take off chars for the trailing
	$length -= strlen( $trailing );
	if ( strlen( $str ) > $length ) {
// string exceeded length, truncate and add trailing dots
		return substr( $str, 0, $length ) . $trailing;
	} else {
// string was already short enough, return the string
		$res = $str;
	}

	return $res;
}

function stripP( $text ) {
///////////////////////////
// Fix FCKeditor madness!
// * check if it begins and ends with a P tag; if so, remove them
// this problem in FCKeditor 2.6.3; perhaps will be fixed in later versions
///////////////////////////

	$matcher = preg_match( "/^<p>.*<\/p>$/", trim( $text ) );

	if ( $matcher == 1 ) {
// trim off those p tags!
		$text = preg_replace( '/^<p>(.*)<\/p>$/', '$1', $text );
	}

	return $text;
}

function uploader2( $temp_path, $target_path ) {

	global $upload_whitelist;

	/* Verifiy File extension;  code modified from here:
	  http://hungred.com/useful-information/secure-file-upload-check-list-php/ */

	$fileName = strtolower( $_FILES['uploadedfile']['name'] );

	$sp_parts = explode( '.', (string) $fileName );
	if ( ! in_array( end( $sp_parts ), (array) $upload_whitelist, true ) ) {
		echo sp__( "Invalid file type: not on whitelist of ok file types" );
		exit( 0 );
	}

	/* Add the original filename to our target path.
	  Result is "uploads/filename.extension" */

	$target_path_1 = $temp_path . basename( $_FILES['uploadedfile']['name'] );
	$_FILES['uploadedfile']['tmp_name'];


	$target_path_2 = $target_path . sp_safe_filename( $_FILES['uploadedfile']['name'] );

	if ( move_uploaded_file( $_FILES['uploadedfile']['tmp_name'], $target_path_2 ) ) {
		return sp_safe_filename( $_FILES['uploadedfile']['name'] );
	} else {
		return "no";
	}
}

function getSubBoxes( $prefix = "", $trunc = "", $subs = "") {

	$subs_option_boxes = "";

	switch($subs) {
		case "1": // all types
		case "all":
			// all types
			$subs_query = "SELECT distinct subject_id, subject, type FROM subject ORDER BY type, subject";
			break;
		case "subject":
			// only by "Subject" type
			$subs_query = "SELECT distinct subject_id, subject, type FROM subject WHERE type LIKE 'Subject' AND active = '1' ORDER BY type, subject";
			break;
		case "staff":
		default:
			$subs_query = "SELECT distinct s.subject_id, subject, type
				FROM subject s, staff_subject ss
				WHERE s.subject_id = ss.subject_id
				AND ss.staff_id = " . $_SESSION['staff_id'] . "
				ORDER BY type, subject";
			break;
			
	}

	// if ( $subs == "all" ) {
	// 	$subs_query = "SELECT distinct subject_id, subject, type FROM subject ORDER BY type, subject";
	// } else {
	// 	$subs_query = "SELECT distinct s.subject_id, subject, type
    //         FROM subject s, staff_subject ss
    //         WHERE s.subject_id = ss.subject_id
    //         AND ss.staff_id = " . $_SESSION['staff_id'] . "
    //         ORDER BY type, subject";
	// }

	$db          = new Querier;
	$subs_result = $db->query( $subs_query );

	$num_subs = count( $subs_result );

	if ( $num_subs > 0 ) {

// create the option
		$current_type      = "";
		$subs_option_boxes = "";

		foreach ( $subs_result as $myrow ) {
			$subs_id   = $myrow[0];
			$subs_name = $myrow[1];
			$subs_type = $myrow[2];

			if ( $trunc ) {
				$subs_name = Truncate( $subs_name, $trunc, '' );
			}

			if ( $current_type != $subs_type ) {

				$subs_option_boxes .= "<option value=\"\" style=\"background-color: #F6E3E7\">~~" . strtoupper( $subs_type ) . "~~</option>";
			}

			$subs_option_boxes .= "<option value=\"$prefix$subs_id\">$subs_name</option>";

			$current_type = $subs_type;
		}
	}

	return $subs_option_boxes;
}

function getDBbySubBoxes( $selected_sub, $additionaltype = "Placeholder" ) {
	$db                = new Querier;
	$subs_option_boxes = "";
	$alphabet          = "";
	$morequery         = "";

	if ( $additionaltype != "" ) {
		$morequery = "OR type = " . ( new Querier )->quote( (string) $additionaltype );
	}

	//$subs_query = "SELECT distinct subject_id, subject, type FROM `subject` WHERE (type = 'Subject' " . $morequery . ") AND active = '1' ORDER BY subject";

	$subs_query  = "SELECT s.subject_id, s.subject, s.type
FROM subject as s WHERE exists(
SELECT t.title, l.record_status, r.title_id, r.rank_id, r.description_override
FROM `rank` r, location_title lt, location l, title t
    WHERE subject_id = s.subject_id
    AND lt.title_id = r.title_id
    AND l.location_id = lt.location_id
    AND t.title_id = lt.title_id
    AND l.eres_display = 'Y'
    AND l.record_status IN (" . \SubjectsPlus\Control\RecordSettings::publicStatusSql() . ")
    AND r.dbbysub_active = 1)
AND s.active = 1
ORDER BY s.subject";
	$subs_result = $db->query( $subs_query );


	$num_subs = count( $subs_result );

	if ( $num_subs > 0 ) {
		foreach ( $subs_result as $myrow ) {
			$subs_id   = $myrow[0];
			$subs_name = $myrow[1];

			$subs_name = Truncate( $subs_name, 50, '' );

			$subs_option_boxes .= "<option value=\"databases.php?letter=bysub&amp;subject_id=$subs_id\"";
			if ( $selected_sub == $subs_id ) {
				$subs_option_boxes .= " selected=\"selected\"";
			}
			$subs_option_boxes .= ">" . sp__( $subs_name ) . "</option>";
		}
	}

	$alphabet .= " <select name=\"browser\" id=\"select_subject\" onChange=\"window.location=this.options[selectedIndex].value\" title=\"" . sp__( "Databases by Subject" ) . "\">  
        $subs_option_boxes
        </select>";

	return $alphabet;
}

function changeMe( $table, $flag, $item_id, $record_title, $staff_id ) {
	$db = new Querier;

	global $dbName_SPlus;

	$record_title = TruncByWord( $record_title, 15 );

// Can be insert, update, delete; only the first creates a new record, so...
	if ( $flag == "insert" || $flag == "delete" ) {
		$q = "insert into chchchanges (staff_id, ourtable, record_id, record_title, message)
        values(" . $staff_id . ", \"$table\", " . $item_id . ", \"" . $record_title . "\", \"$flag\")";

		$r = $db->exec( $q );
		if ( $r ) {
			return true;
		} else {
			return false;
		}
	} else {
// find out person who made last change to this record
		$qtest = "SELECT staff_id, chchchanges_id, message
        FROM `chchchanges`
        WHERE record_id = \"$item_id\" and ourtable = \"$table\" ORDER BY date_added DESC";

		$result = $db->query( $qtest );


// If there are no results, we need to insert a record
		if ( ! $result ) {
			$q = "insert into chchchanges (staff_id, ourtable, record_id, record_title, message)
            values(" . $staff_id . ", \"$table\", " . $item_id . ", \"" . $record_title . "\", \"$flag\")";

			$r = $db->exec( $q );
			if ( $r ) {
				return true;
			} else {
				return false;
			}
		} else {
// If the editor is the same as last time & it's not the first record,
// just update the time; Otherwise, add a new entry to the table

			if ( ( $result[0] == $staff_id ) && ( $result[2] != "insert" ) ) {
// Editor is same as last guide updater, just update the time
				$q = "UPDATE chchchanges SET message = 'update', date_added = NOW() WHERE chchchanges_id = " . $result[1];
			} else {
//Editor is different, add entry to table
				$q = "insert into chchchanges (staff_id, ourtable, record_id, record_title, message)
                    values(" . $staff_id . ", \"$table\", " . $item_id . ", \"" . $record_title . "\", \"update\")";
			}
//print $q;

			$r = $db->exec( $q );
			if ( $r ) {
				return true;
			} else {
				return false;
			}
		}
	}
}

function lastModded( $table, $record_id, $zero_message = 1, $show_email = 1 ) {
	$q = "SELECT email, DATE_FORMAT(date_added, '%b %D %Y') as last_modified
        FROM chchchanges c, staff s
        WHERE c.staff_id = s.staff_id
        AND ourtable = '$table'
        AND record_id = '$record_id'
        ORDER BY date_added DESC";
//print $q;
	$db     = new Querier;
	$r      = $db->query( $q );
	$my_mod = $r;


	if ( $my_mod ) {
		$val = $my_mod[0]['last_modified'];
		if ( $show_email == 1 ) {
			$val .= ", " . $my_mod[0]['email'];
		}
	} else {
		if ( $zero_message == 1 ) {
			$val = sp__( "Last modification date unknown." );
		} else {
			$val = "";
		}
	}

	return $val;
//return sp__("last modified") . " " . $my_mod[1] . " - " . $my_mod[0];
}

/*
 * scrubData
 * @param  mixed 		string	string to scrub
 * @param  string		type		options are: integer, text, ascii, richtext, email
 * @return string  	scrubbed string
 */

/**
 * one shared HTMLPurifier instance (was rebuilt on every scrubData() call), with its definition
 * cache in assets/cache/htmlpurifier instead of inside the library folder.
 */
function sp_purifier() {
	static $purifier = null;
	if ( $purifier === null ) {
		$config   = HTMLPurifier_Config::createDefault();
		$config->set( 'Core.EscapeNonASCIICharacters', true );
		$cacheDir = dirname( dirname( __DIR__ ) ) . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'htmlpurifier';
		if ( is_dir( $cacheDir ) || @mkdir( $cacheDir, 0755, true ) ) {
			$config->set( 'Cache.SerializerPath', $cacheDir );
		}
		if ( ! is_writable( $cacheDir ) ) {
			$config->set( 'Cache.DefinitionImpl', null ); // no cache rather than a warning on every request
		}
		$purifier = new HTMLPurifier( $config );
	}

	return $purifier;
}

function scrubData( $string, $type = "text" ) {

	switch ( $type ) {
		case "text":
			$string = strip_tags( (string) $string );
			$string = htmlspecialchars( $string, ENT_QUOTES );
			$string = sp_purifier()->purify( $string );
			break;
		case "richtext":
			break;
		case "richtext_html_purifier":
			$string = sp_purifier()->purify( (string) $string );
			break;
		case "email":
			//removes any tags protecting against javascript injection
			$string = filter_var( $string, FILTER_SANITIZE_EMAIL );

			//checks to see if the email is in valid email format, if not return a blank string
			if ( ! isValidEmailAddress( $string ) ) {
				$string = '';
			}

			break;
		case "integer":
		case "int":
			// whole number; non-numeric input becomes 0 (round() throws a TypeError on PHP 8)
			$string = sp_int( $string, 0 );
			break;
	}


	return $string;
}

// just to handle all the error messages
function blunDer( $message, $type = 1 ) {
	global $administrator;

	print "<h2>" . sp__( "Someone Has Blundered!" ) . "</h2>";
	if ( $type == 0 ) {
		print "<p>" . sp__( "(But probably not you.)" ) . "</p>";
	}
	print "<p>$message</p>\n";
	print sp__( "<p>Please contact the <a href=\"mailto:$administrator\">Administrator</a></p>" );
	include( "../includes/footer.php" );
	exit();
}

//////////////
// Erstwhile guide_functions.php
////////////////

function theme_file( $filename, $subjects_theme = null, $header_type = null) {
	// This works because $header_type is currently only used on files directly in includes/
	if ( isset($header_type) && $header_type != "" && $header_type != "default" ) {
		$guide_fname = preg_replace( '/\.php$/', "_$header_type.php", $filename);
		if ( file_exists($guide_fname) ) return $guide_fname;
	}

	if ( isset($subjects_theme) && $subjects_theme != "" ) {
		$theme_fname = "themes/$subjects_theme/$filename";
		if ( file_exists($theme_fname) ) return $theme_fname;
	}

	return $filename;
}


function findDescOverride( $subject_id, $title_id ) {
	$db = new Querier();

	$override_text = $db->queryWithPreparedStatement( "SELECT description_override FROM `rank` WHERE subject_id = :sid AND title_id = :tid", NULL,
		array( ':sid' => sp_int( $subject_id ), ':tid' => sp_int( $title_id ) ) );

	if ( count( $override_text ) != 0 ) {
		return $override_text[0][0];
	}

	return '';
}

function showDocIcon( $extension ) {
	switch ( $extension ) {
		case "avi":
		case "mpg":
		case "mpeg":
		case "mp4":
		case "mov":
		case "wmv":
			return "film.png";
			break;
		case "doc":
		case "rtf":
		case "docx":
			return "doc.png";
			break;
		case "fla":
			return "flash.png";
			break;
		case "bmp":
		case "gif":
		case "jpg":
		case "jpeg":
		case "png";
		case "tif":
		case "tiff":
			return "picture.png";
			break;
		case "m4p":
		case "mp3":
		case "ogg":
		case "wav":
			return "music.png";
			break;
		case "pdf":
			return "pdf.png";
			break;
		case "ppt":
		case "pptx":
			return "ppt.png";
			break;
		case "psd":
			return "psd.png";
			break;
		case "swf":
			return "flash.png";
			break;
		case "txt":
			return "txt.png";
			break;
		case "xls":
		case "xlsx":
			return "xls.png";
			break;
		case "xml":
			return "code.png";
			break;
		case "zip":
			return "zip.png";
			break;
	}
}

function showIcons( $ctags, $showtext = 0 ) {
	global $PublicPath;
	global $IconPath;
	global $AssetPath;
	$icons = "";
	if ( $ctags != "" ) {
		foreach ( $ctags as $value ) {
			$value = strtolower( $value );
			switch ( $value ) {
				case "restricted":
					//$icons .= "<img src=\"$IconPath/lock.png\" border=\"0\" alt=\"" . sp__("Restricted Resource") . "\" title=\"" . sp__("Restricted Resource") . "\" /> ";
					//$icons .= "<img src=\"$IconPath/v2-lock.png\" border=\"0\" alt=\"" . sp__( "Restricted Resource" ) . "\" title=\"" . sp__( "Restricted Resource" ) . "\" /> ";
                    $icons .= "<i class=\"fa fas fa-lock\" aria-hidden=\"true\" title=\"" . sp__( "Restricted Resource" ) . "\"></i>";

					if ( $showtext == 1 ) {
						$icons .= " = " . sp__( "Restricted resource" ) . "<br />";
					}
					break;
				case "unrestricted":
					//$icons .= "<img src=\"$IconPath/lock_unlock.png\" border=\"0\" alt=\"" . sp__( "Unrestricted Resource" ) . "\" title=\"" . sp__( "Unrestricted Resource" ) . "\" /> ";
                    $icons .= "<i class=\"fa fas fa-unlock\" aria-hidden=\"true\" title=\"" . sp__( "Unrestricted Resource" ) . "\"></i>";
					if ( $showtext == 1 ) {
						$icons .= " = " . sp__( "Unrestricted resource" ) . "<br />";
					}
					break;
				case "full_text":
					//$icons.= " <img src=\"$IconPath/document-26.png\" border=\"0\" alt=\"" . sp__("Some full text available") . "\" title=\"" . sp__("Some full text available") . "\" />";
					//$icons .= " <img src=\"$IconPath/full_text.gif\" border=\"0\" alt=\"" . sp__( "Some full text available" ) . "\" title=\"" . sp__( "Some full text available" ) . "\" />";
                    $icons .= " <i class=\"fas fa-file-alt fa fa-file-text\" aria-hidden=\"true\" title=\"" . sp__( "Some full text available" ) . "\"></i>";

					if ( $showtext == 1 ) {
						$icons .= " = " . sp__( "Some full text" ) . "<br />";
					}
					break;
				case "openurl":
					//$icons .= "<img src=\"$IconPath/link-26.png\" border=\"0\" alt=\"openURL\" title=\"openURL\" /> ";
					//$icons .= "<img src=\"$IconPath/article_linker.gif\" border=\"0\" alt=\"openURL\" title=\"openURL\" /> ";
                    $icons .= "<i class=\"fa fa-external-link fas fa-external-link-alt\" aria-hidden=\"true\" title=\"" . sp__( "OpenURL enabled" ) . "\"></i>";
					if ( $showtext == 1 ) {
						$icons .= " = " . sp__( "OpenURL enabled" ) . "<br /><br />";
					}
					break;
				case "images":
					//$icons.= " <img src=\"$IconPath/image_file-26.png\" border=\"0\" alt=\"" . sp__("Resource contains images") . "\" title=\"" . sp__("Resource contains images") . "\" />";
					//$icons .= " <img src=\"$IconPath/camera.gif\" border=\"0\" alt=\"" . sp__( "Resource contains images" ) . "\" title=\"" . sp__( "Resource contains images" ) . "\" />";
                    $icons .="<i class=\"fa fas fa-camera\" aria-hidden=\"true\" title=\"" . sp__( "Resource contains images" ) . "\"></i>";

					if ( $showtext == 1 ) {
						$icons .= " = " . sp__( "Images" ) . "<br />";
					}
					break;
				case "video":
					//$icons.= " <img src=\"$IconPath/video_file-26.png\"  border=\"0\" alt=\"" . sp__("Resource contains video") . "\" title=\"" . sp__("Resource contains video") . "\" />";
					//$icons .= " <img src=\"$IconPath/television.gif\"  border=\"0\" alt=\"" . sp__( "Resource contains video" ) . "\" title=\"" . sp__( "Resource contains video" ) . "\" />";
                    $icons .="<i class=\"fa fa-video-camera fas fa-video\" aria-hidden=\"true\" title=\"" . sp__( "Resource contains video" ) . "\"></i>";

					if ( $showtext == 1 ) {
						$icons .= " = " . sp__( "Video files" ) . "<br />";
					}
					break;
				case "audio":
					//$icons.= " <img src=\"$IconPath/audio_file-26.png\" border=\"0\" alt=\"" . sp__("Resource contains audio") . "\" title=\"" . sp__("Resource contains audio") . "\" />";
					//$icons .= " <img src=\"$IconPath/sound.gif\" border=\"0\" alt=\"" . sp__( "Resource contains audio" ) . "\" title=\"" . sp__( "Resource contains audio" ) . "\" />";
					$icons .="<i class=\"fas fa fa-volume-up\" aria-hidden=\"true\" title=\"" . sp__( "Resource contains audio" ) . "\"></i>";

					if ( $showtext == 1 ) {
						$icons .= " = " . sp__( "Audio files" ) . "<br />";
					}
					break;
			}
		}
	}

	return $icons;
}

function seeRecentChanges( $staff_id, $limit = 10 ) {

	global $IconPath;
	global $CpanelPath;
	$recent_activity = "";

	if ( $staff_id ) {

		$sq2 = "SELECT ourtable, record_id, record_title, message, date_added
        FROM chchchanges
        WHERE staff_id = " . (int) $staff_id . "
        ORDER BY date_added DESC
        LIMIT 0, $limit";
	} else {
		$sq2 = "SELECT ourtable, record_id, record_title, message, date_added, CONCAT( fname, ' ', lname ) AS fullname
        FROM chchchanges, staff
        WHERE chchchanges.staff_id = staff.staff_id
        ORDER BY date_added DESC
        LIMIT 0 , $limit";
	}


	//print $sq2;
	$db = new Querier;

	$sr2 = $db->query( $sq2 );

	$num_rows = count( $sr2 );

	$row_count = 0;
	$colour1   = "oddrow";
	$colour2   = "evenrow";


	if ( $num_rows != 0 ) {

		foreach ( $sr2 as $myrow2 ) {

			$row_colour = ( $row_count % 2 ) ? $colour1 : $colour2;

			$intro   = "";
			$message = $myrow2["3"];

			switch ( $myrow2["0"] ) {
				case "guide":
					$intro  = sp__( "Research Guides" ) . " $message";
					$linkit = $CpanelPath . "guides/guide.php?subject_id=$myrow2[1]";
					break;
				case "faq":
					$intro  = sp__( "FAQ" ) . " $message";
					$linkit = $CpanelPath . "faq/faq.php?faq_id=$myrow2[1]";
					break;
				case "talkback":
					$intro  = sp__( "Talk Back" ) . " $message";
					$linkit = $CpanelPath . "talkback/talkback.php?talkback_id=$myrow2[1]";
					break;
				case "subject":
					switch ( $message ) {
						case "insert":
							$intro = sp__( "Research Guide created" );
							break;
						case "update":
							$intro = sp__( "Research Guide metadata" );
							break;
						case "delete":
							$intro = sp__( "Research Guide deleted" );
							break;
					}

					$linkit = $CpanelPath . "guides/guide.php?subject_id=$myrow2[1]";
					break;
				case "record":
					$intro  = sp__( "Record" ) . " $message";
					$linkit = $CpanelPath . "records/record.php?record_id=$myrow2[1]";
					break;
				case "staff_details":
					$intro = sp__( "Staff Details updated." );
					break;
				case "staff":
					switch ( $message ) {
						case "insert":
							$intro  = sp__( "New User Added" );
							$linkit = $CpanelPath . "admin/user.php?staff_id=$myrow2[1]";
							break;
						case "update":
							$intro  = sp__( "User Information updated" );
							$linkit = $CpanelPath . "admin/user.php?staff_id=$myrow2[1]";
							break;
						case "delete":
							$intro = sp__( "User deleted" );
							break;
					}

					break;
				case "video":
					$intro  = sp__( "Video" ) . " $message";
					$linkit = $CpanelPath . "videos/video.php?video_id=$myrow2[1]";
					break;
			}

			$recent_activity .= "<div class=\"recent-activity $row_colour\"> <img src=\"$IconPath/required.png\"  /></img> $intro";
			if ( $myrow2["2"] != "" ) {
				$recent_activity .= ": <a href=\"$linkit\" classs=\"recent-activity-link\" title=\"Took place: $myrow2[4]\">$myrow2[2]</a>";
			}

			if ( ! $staff_id ) {
				$recent_activity .= " <span class=\"recent-activity-span\">$myrow2[5]</span>";
			}
			$recent_activity .= "</div>";
			$row_count ++;
		}
	}

	return $recent_activity;
}

/* This function is only for the University of Miami; on UM site, rename this to getHeadshot and comment out the other one */
function getHeadshot( $email, $pic_size = "medium", $class = "staff_photo", $um_theme = false ) {


	$name_id   = explode( "@", (string) $email );
	$lib_image = "_" . $name_id[0];
	global $AssetPath;
	// Get the real file path for the headshot image
	$headshot_path = dirname( dirname( dirname( __FILE__ ) ) ) . "/assets/users/$lib_image/headshot.jpg";

	if ( ! file_exists( $headshot_path ) ) {
		$headshot_path = dirname( dirname( dirname( __FILE__ ) ) ) . "/assets/users/$lib_image/headshot_large.jpg";
	}

	if ( file_exists( $headshot_path ) ) {

		// Check if the image is the UM logo
		$image_hash = md5_file( $headshot_path );
		$um_logo    = "91b8c9ec083c5abc898a5c482aac959e";

		if ( $image_hash == $um_logo && ! $um_theme ) {
            $headshot = "<img src=\"" . $AssetPath . "" . "users/$lib_image/headshot.jpg\" alt=\"$email\" title=\"$email\"";

            switch ( $pic_size ) {
                case "small":
                    $headshot .= " width=\"50\"";
                    break;
                case "medium":
                    $headshot .= " width=\"70\"";
                    break;
            }

            $headshot .= " class=\"staff_photo\"  align=\"left\" />";

            // If the image exists and isn't the UM logo return the img html
            return $headshot;
		} else {

			$headshot = "<img src=\"" . $AssetPath . "" . "users/$lib_image/headshot.jpg\" alt=\"$email\" title=\"$email\"";

			switch ( $pic_size ) {
				case "small":
					$headshot .= " width=\"50\"";
					break;
				case "medium":
					$headshot .= " width=\"70\"";
					break;
			}

			$headshot .= " class=\"staff_photo\"  align=\"left\" />";

			// If the image exists and isn't the UM logo return the img html
			return $headshot;
		}
	}
}


/* This function is only for the University of Miami; on UM site, rename this to getHeadshot and comment out the other one */
function getHeadshotUM( $email, $pic_size = "medium", $class = "staff_photo" ) {


	$name_id   = explode( "@", (string) $email );
	$lib_image = "_" . $name_id[0];
	global $AssetPath;
	// Get the real file path for the headshot image
	$headshot_path = dirname( dirname( dirname( __FILE__ ) ) ) . "/assets/users/$lib_image/headshot.jpg";

	if ( file_exists( $headshot_path ) ) {

		// Check if the image is the UM logo
		$image_hash = md5_file( $headshot_path );
		$um_logo    = "91b8c9ec083c5abc898a5c482aac959e";

		if ( $image_hash == $um_logo ) {
		} else {

			$headshot = "<img src=\"" . $AssetPath . "" . "users/$lib_image/headshot.jpg\" alt=\"$email\" title=\"$email\"";

			switch ( $pic_size ) {
				case "small":
					$headshot .= " width=\"50\"";
					break;
				case "medium":
					$headshot .= " width=\"70\"";
					break;
			}

			$headshot .= " class=\"staff_photo\"  align=\"left\" />";

			// If the image exists and isn't the UM logo return the img html
			return $headshot;
		}
	}
}

function getHeadshotFull( $email, $pic_size = "full", $class = "staff_photo_full" ) {

	$name_id   = explode( "@", (string) $email );
	$lib_image = "_" . $name_id[0];
	global $AssetPath;

	$headshot_large = "<img src=\"" . $AssetPath . "" . "/users/$lib_image/headshot_large.jpg\" alt=\"$email\" title=\"$email\"  width=\"225\" class=\"$class\" />";

	return $headshot_large;
}


// Display staff images
function showStaff( $email, $picture = 1, $pic_size = "medium", $link_name = 0 ) {
	global $tel_prefix;
	global $mod_rewrite;

	$q = "SELECT fname, lname, title, tel, email FROM staff WHERE email = " . ( new Querier )->quote( (string) $email );

	$db = new Querier;
	$r  = $db->query( $q );

	$row_count = count( $r );

	if ( $row_count == 0 ) {
		return;
	}


	foreach ( $r as $myrow ) {

		if ( $link_name == 1 ) {
			$email   = $myrow["email"];
			$name_id = explode( "@", (string) $email );

			if ( $mod_rewrite == 1 ) {
				if ( isset( $institution_code ) && $institution_code == "um" ) {
					$linky = "/subjects/profile/" . $name_id[0]; // um custom
				} else {
					$linky = $linky = "staff/" . $name_id[0];
				}

			} else {
				$linky = "staff_details.php?name=" . $name_id[0];
			}
			$full_name = "<a href=\"$linky\">" . $myrow[0] . " " . $myrow[1] . "</a>";
			$img_link  = "<a href=\"$linky\">";
		} else {
			$full_name = $myrow[0] . " " . $myrow[1];
			$img_link  = "";
		}


		$staffer = "<div class=\"staffpic\">";
		$staffer .= $img_link;
		$staffer .= getHeadshot( $email, $pic_size );
		$staffer .= "</a></div><div class=\"staff-meta\"><h4>$full_name</h4><p><em>$myrow[2]</em></p><p>$tel_prefix $myrow[3]</p><p><a href=\"mailto:$myrow[4]\">$myrow[4]</a></p></div></td>";
	}

	return $staffer;
}

function getDBbyTypeBoxes( $selected_type = "", $show_formats = true ) {

	$types_option_boxes = "";
	$alphabet           = "";
	global $all_ctags;

	sort( $all_ctags );

	foreach ( $all_ctags as $tag ) {

		$new_tag = ucwords( preg_replace( '/_/', ' ', $tag ) );

		$types_option_boxes .= "<option value=\"databases.php?letter=bytype&amp;type=$tag\"";

		if ( $selected_type == $tag ) {
			$types_option_boxes .= " selected=\"selected\"";
		}
		$types_option_boxes .= ">" . sp__( $new_tag ) . "</option>";
	}

	if ( $show_formats == true ) {
		$alphabet .= " <select name=\"browser\" id=\"select_format\" onChange=\"window.location=this.options[selectedIndex].value\" title=\"" . sp__( "Databases by Format" ) . "\">
      <option value=\"databases.php?letter=bytype\">" . sp__( "List All Format Types" ) . "</option>
      $types_option_boxes
      </select>";
	}

	return $alphabet;
}

function getLetters( $table, $selected = "A", $numbers = 1, $show_formats = true, $show_free = true ) {

	$selected = scrubData( $selected );

	$selected_subject = "";
	if ( isset( $_GET["subject_id"] ) ) {
		$selected_subject = intval( $_GET["subject_id"] );
	}

	$selected_type = "";
	if ( isset( $_GET["type"] ) ) {
		$selected_type = $_GET["type"];
	}

	$showsearch = 0;
	$abc_link   = "";

// If it's an array, just plunk that stuff in //

	if ( is_array( $table ) ) {
		$letterz    = $table;
		$showsearch = 0;
		$azRange    = $table;
	} else {

		$shownew = 1;
		$extras  = "";

		switch ( $table ) {
			case "databases":
				$lq       = "SELECT distinct UCASE(left(title,1)) AS initial
                    FROM location l, location_title lt, title t
                    WHERE l.location_id = lt.location_id AND lt.title_id = t.title_id
                    AND eres_display = 'Y'
                    AND left(title,1) REGEXP '[A-Z]'
                    ORDER BY initial";
				$abc_link = "databases.php";
				$shownew  = 0;
				break;
		}

//print $lq;
		$db = new Querier;
		$lr = $db->query( $lq );

		foreach ( $lr as $mylets ) {
			$letterz[] = $mylets[0][0];
		}

		// let's init an array of all letters
		$azRange = range( 'A', 'Z' );

		if ( $numbers == 1 ) {
			$letterz[] = "Num";
			$azRange[] = "Num";
		}

		$letterz[] = "All";
		$azRange[] = "All";

		if ($show_free == true) {
			$letterz[] = "Free";
			$azRange[] = "Free";
		}
		

		if ( ! $selected ) {
			$selected = "ALL";
		}
	}


	$alphabet = "<div id=\"letterhead\" align=\"center\">";

	foreach ( $azRange as $char ) {
		$label = mb_strlen( $char ) > 1 ? sp__( $char ) : $char; // "All", "Num", "By Department"… translatable
		if ( in_array( $char, $letterz ) ) {
			if ( $char == $selected ) {
				$alphabet .= "<span id=\"selected_letter\">$label</span> ";
			} else {
				$alphabet .= "<a href=\"$abc_link?letter=" . rawurlencode( $char ) . "\">$label</a> ";
			}
		} else {
			$alphabet .= "<span class=\"inactive\">$label</span> ";
		}
	}

	/*
	  foreach ($letterz as $value) {
		if ($value == $selected) {
		  $alphabet .= "<span id=\"selected_letter\">$value</span> ";
		} else {
		  $alphabet .= "<a href=\"$abc_link?letter=$value\">$value</a>";
		}
	  }
	*/
	if ( $table == "databases" ) {
		$alphabet .= getDBbyTypeBoxes( $selected_type, $show_formats );
		$alphabet .= getDBbySubBoxes( $selected_subject );
	}

	if ( $showsearch != 0 ) {
		$alphabet .= "<input type=\"text\" id=\"letterhead_suggest\" size=\"30\"  />";
	}


	$alphabet .= "</div>";

	return $alphabet;
}

function prepareTH( $array ) {

	$th = "
<table width=\"100%\" class=\"item_listing other-staff\">
<tr class=\"pure-g staff-heading\">";

	foreach ( $array as $key => $value ) {
		$th .= "<th>$value</th>";
	}

	$th .= "</tr>";

	return $th;
}

function prepareTHUM( $array ) {
	$th = "
    <table class=\"footable foo1 other-staff\" data-filter=\"#filter\">
      <thead>
        <tr class=\"staff-heading\">";

	foreach ( $array as $header ) {

		if ( $header["hide"] == true ) {
			if ( $header["nosort"] == true ) {
				$th .= "<th data-hide=\"phone,mid\" data-sort-ignore=\"true\">"
				       . $header['label'] . "</th>";
			} else {
				$th .= "<th data-hide=\"phone,mid\">"
				       . $header['label'] . "</th>";
			}

		} else {
			if ( $header["nosort"] == true ) {
				$th .= "<th data-sort-ignore=\"true\">" . $header['label'] . "</th>";
			} else {
				$th .= "<th>" . $header['label'] . "</th>";
			}
		}

	}

	$th .= "</tr></thead>";

	return $th;
}

function isValidEmailAddress( $lstrPotentialEmail ) {
	$lstrExpression = '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,4}/i';

	$lintMatch = preg_match( $lstrExpression, $lstrPotentialEmail );

	if ( $lintMatch > 0 ) {
		return true;
	}

	return false;
}

/**
 * displayLogoOnlyHeader() - this function displays a logo only header
 *
 * @return void
 */
function displayLogoOnlyHeader() {
	//find where control folder is and get assest URL to display logo
	$lstrURL = $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];

	$lobjSplit = explode( '/', (string) $lstrURL );

	for ( $i = ( count( $lobjSplit ) - 1 ); $i >= 0; $i -- ) {
		if ( $lobjSplit[ $i ] == 'control' ) {
			unset( $lobjSplit[ $i ] );
			$lstrURL = implode( '/', $lobjSplit );
			break;
		} else {
			unset( $lobjSplit[ $i ] );
		}
	}

	//display logo only header
	?>
    <header id="header">

        <img class="login-only-logo" src="<?php echo '//' . $lstrURL . '/assets/'; ?>images/admin/logo_small.png"/>

    </header>
	<?php
}

/**
 * isInstalled() - this funtion determines whether or not SujectsPlus is installed
 *
 * @return boolean
 */
function isInstalled() {
	//does key SubjectsPlus tables exist query
	$lstrQuery = 'SHOW TABLES LIKE \'staff%\'';

	$db           = new Querier;
	$rscResults   = $db->query( $lstrQuery );
	$lintRowCount = count( $rscResults );

	//no key SubjectsPlus tables exists
	if ( $lintRowCount == 0 ) {
		return false;
	}

	return true;
}

/**
 * isUpdated() - this funtion determines whether or not SujectsPlus is updated to 2.0
 *
 * @return boolean
 */
function isUpdated() {
	//does key SubjectsPlus 2.0 tables exist query
	$lstrQuery    = 'SHOW TABLES LIKE \'staff_department\'';
	$db           = new Querier;
	$rscResults   = $db->query( $lstrQuery );
	$lintRowCount = count( $rscResults );

	//no key SubjectsPlus 2.0 tables exists
	if ( $lintRowCount == 0 ) {
		return false;
	}

	return true;
}

/**
 * getAssetPath() - returns path of asset folder. Used for when wanting to get
 * asset path when configuration AssetPath is not set yet (e.g. Installation)
 *
 * @return string
 */
function getAssetPath() {
	$lstrPath = dirname( dirname( dirname( __FILE__ ) ) ) . DIRECTORY_SEPARATOR . "assets" . DIRECTORY_SEPARATOR;

	return $lstrPath;
}

/**
 * getControlPath() - returns path of control folder. Used for when wanting to get
 * control path when configuration CpanelPath is not set yet (e.g. Installation)
 *
 * @return string
 */
function getControlPath() {
	$lstrPath = dirname( dirname( __FILE__ ) ) . DIRECTORY_SEPARATOR;

	return $lstrPath;
}

/**
 * getAssetURL() - returns url of asset folder. SUed for when wanting to get
 * asset url when configuration AssestURL is not set yet (e.g. Installation).
 *
 * @return string
 */
function getAssetURL() {
	$lstrURL = $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];

	$lobjSplit = explode( '/', (string) $lstrURL );

	for ( $i = ( count( $lobjSplit ) - 1 ); $i >= 0; $i -- ) {
		if ( $lobjSplit[ $i ] == 'control' || $lobjSplit[ $i ] == 'subjects' ) {
			unset( $lobjSplit[ $i ] );
			$lstrURL = implode( '/', $lobjSplit );
			break;
		} else {
			unset( $lobjSplit[ $i ] );
		}
	}


	return '//' . $lstrURL . '/assets/';

}

/**
 * getControlRL() - returns url of control folder. SUed for when wanting to get
 * control url when configuration CPanelURL is not set yet (e.g. Installation).
 *
 * @return string
 */
function getControlURL() {
	$lstrURL = $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];

	$lobjSplit = explode( '/', (string) $lstrURL );

	for ( $i = ( count( $lobjSplit ) - 1 ); $i >= 0; $i -- ) {
		if ( $lobjSplit[ $i ] == 'subjects' ) {
			unset( $lobjSplit[ $i ] );
			$lstrURL = implode( '/', $lobjSplit );

			$lstrURL = '//' . $lstrURL . '/control/';

			break;
		} elseif ( $lobjSplit[ $i ] == 'control' ) {
			$lstrURL = implode( '/', $lobjSplit );
			$lstrURL = '//' . $lstrURL . '/';

			break;
		} else {
			unset( $lobjSplit[ $i ] );
		}
	}

	return $lstrURL;
}


function getSubjectsURL() {
	$lstrURL = $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];

	$lobjSplit = explode( '/', (string) $lstrURL );

	for ( $i = ( count( $lobjSplit ) - 1 ); $i >= 0; $i -- ) {
		if ( $lobjSplit[ $i ] == 'subjects' ) {
			unset( $lobjSplit[ $i ] );
			$lstrURL = implode( '/', $lobjSplit );

			$lstrURL = '//' . $lstrURL . '/subjects/';

			break;
		} elseif ( $lobjSplit[ $i ] == 'control' ) {
			$lstrURL = implode( '/', $lobjSplit );

			$lstrURL = '//' . $lstrURL . '/';

			break;
		} else {
			unset( $lobjSplit[ $i ] );
		}
	}

	return $lstrURL;
}

/**
 * getRewriteBase() - this function will find the base for SubjectsPlus so that
 * a .htaccess file use the rewrite base
 *
 * @return string
 */
function getRewriteBase() {
	$lstrURI = $_SERVER['REQUEST_URI'];
	$lstrRewriteBase = '/'; // was undefined when the path has no control/ or subjects/ segment

	$lobjSplit = explode( '/', (string) $lstrURI );

	for ( $i = ( count( $lobjSplit ) - 1 ); $i >= 0; $i -- ) {
		if ( $lobjSplit[ $i ] == 'control' ) {
			unset( $lobjSplit[ $i ] );
			$lstrRewriteBase = implode( '/', $lobjSplit );
			$lstrRewriteBase = $lstrRewriteBase . '/';
			break;
		} else {
			unset( $lobjSplit[ $i ] );
		}
	}

	return $lstrRewriteBase;
}

/**
 * gcd() and reduce() will return us the lowest common denominator fraction.
 * Used to get pure.css-friendly numbers
 *
 * @return array
 */

function gcd( $a, $b ) {
	return $b ? gcd( $b, $a % $b ) : $a;
}

function reduce( $numerator, $denominator ) {
	$gcd = gcd( $numerator, $denominator );

	return array( $numerator / $gcd, $denominator / $gcd );
}

/**
 * makePluslet() is just to save time in creating pluslets
 * you pass in title and body, it returns pluslet
 *
 * @return string
 */

function makePluslet( $title = "", $body = "", $bonus_styles = "", $printout = true , $inline_css = "") {
	$pluslet = "
  <div class=\"pluslet $bonus_styles\" style=\"$inline_css\">
    <div class=\"titlebar\">
      <div class=\"titlebar_text\">$title</div>
      <div class=\"titlebar_options\"></div>
    </div>
    <div class=\"pluslet_body\">$body
    </div>
  </div>";

	if ( $printout == true ) {
		print $pluslet;
	} else {
		return $pluslet;
	}
}

/**
 * feedBack() is to wrap the feedback in some styling
 * $display expects either "response" (from guides, which is positioned absolutely)
 * or the default "feedback" (which is relative).  Look at css for these ids in admin_styles.css
 * @return string
 */

function feedBack( $message = "", $display = "feedback" ) {
	//print "HELLO our message is $message";
	if ( $message != "" ) {
		print "<div class=\"master-feedback\" style=\"display:block;\">$message</div>";
	} else {
		return "";
	}

}

// Mod in_array to work with multidimensional arrays
function in_array_r( $needle, $haystack, $strict = false ) {
	foreach ( $haystack as $item ) {
		if ( ( $strict ? $item === $needle : $item == $needle ) || ( is_array( $item ) && in_array_r( $needle, $item, $strict ) ) ) {
			return true;
		}
	}

	return false;
}


// http://stackoverflow.com/questions/2815162/is-there-a-php-function-like-pythons-zip
function zip() {
	$args   = func_get_args();
	$zipped = array();
	$n      = count( $args );
	for ( $i = 0; $i < $n; ++ $i ) {
		reset( $args[ $i ] );
	}
	while ( $n ) {
		$tmp = array();
		for ( $i = 0; $i < $n; ++ $i ) {
			if ( key( $args[ $i ] ) === null ) {
				break 2;
			}
			$tmp[] = current( $args[ $i ] );
			next( $args[ $i ] );
		}
		$zipped[] = $tmp;
	}

	return $zipped;
}

// Color console output 

function colorize( $text, $status ) {
	$out = "";
	switch ( $status ) {
		case "SUCCESS":
			$out = "[42m"; //Green background
			break;
		case "FAILURE":
			$out = "[41m"; //Red background
			break;
		case "WARNING":
			$out = "[43m"; //Yellow background
			break;
		case "NOTE":
			$out = "[44m"; //Blue background
			break;
		default:
			throw new Exception( "Invalid status: " . $status );
	}

	return chr( 27 ) . "$out" . "$text" . chr( 27 ) . "[0m";
}

/**
 * tokenizeText() is used to convert tokens created via FCKeditor wysiwyg
 * into something prettily output
 *
 * The original is in the Pluslet class, and these two should probably be made one
 *
 * @return string
 */

function tokenizeText( $our_text, $our_subject_id = "" ) {
	global $proxyURL;
	global $PublicPath;
	global $FAQPath;
	global $UserPath;
	global $IconPath;
	global $open_string;
	global $close_string;
	global $open_string_kw;
	global $close_string_kw;
	global $open_string_cn;
	global $close_string_cn;
	global $open_string_bib;

	$db = new Querier();

	$icons = "";
	//$target = "target=\"_" . $target . "\"";
	$target    = "";
	$tokenized = "";

	$parts = preg_split( '/<span[^>]*>{{|}}<\/span>/', $our_text );

	if ( count( $parts ) == 1 ) {
		$parts = preg_split( '/{{|}}/', $our_text );
	}

	if ( count( $parts ) > 1 ) { // there are tokens in $body
		foreach ( $parts as $part ) {
			if ( preg_match( '/^dab},\s?{\d+},\s?{.+},\s?{[01]{2}$/', $part ) || preg_match( '/^faq},\s?{(\d+,)*\d+$/', $part )
			     || preg_match( '/^cat},\s?{.+},\s?{.*},\s?{\w+$/', $part ) || preg_match( '/^fil},\s?{.+},\s?{.+$/', $part )
			     || preg_match( '/^sss},\s?{[^}]*/', $part ) || preg_match( '/^toc},\s?{[^}]*/', $part ) ) { // $part is a properly formed token
				$fields = preg_split( '/},\s?{/', $part );
				$prefix = substr( $part, 0, 3 );
				switch ( $prefix ) {
					case "faq":
						$query     = "SELECT faq_id, question FROM `faq` WHERE faq_id IN(" . $fields[1] . ") ORDER BY question";
						$result    = $db->query( $query );
						$tokenized .= "<ul>";
						foreach ( $result as $myrow ) {
							$tokenized .= "<li><a href=\"$FAQPath" . "?faq_id=$myrow[0]\" $target>" . stripslashes( htmlspecialchars_decode( $myrow[1] ) ) . "</a></li>";
						}
						$tokenized .= "</ul>";
						break;
					case "fil":
						$ext       = explode( ".", (string) $fields[1] );
						$i         = count( $ext ) - 1;
						$our_icon  = showDocIcon( $ext[ $i ] );
						$file      = "$UserPath/$fields[1]";
						$tokenized .= "<a href=\"$file\" $target>$fields[2]</a> <img style=\"position:relative; top:.3em;\" src=\"$IconPath/$our_icon\" alt=\"$ext[$i]\" />";
						break;
					case "cat":
						$pretext = "";
						switch ( $fields[3] ) {
							case "subject":
								$cat_url  = $open_string . $fields[1] . $close_string;
								$pretext  = $fields[2] . " ";
								$linktext = $fields[1];
								break;
							case "keywords":
								$cat_url  = $open_string_kw . $fields[1] . $close_string_kw;
								$linktext = $fields[2];
								break;
							case "call_num":
								$cat_url  = $open_string_cn . $fields[1] . $close_string_cn;
								$linktext = $fields[2];
								break;
							case "bib":
								$cat_url  = $open_string_bib . $fields[1];
								$linktext = $fields[2];
								break;
						}
						$tokenized .= "$pretext<a href=\"$cat_url\" $target>$linktext</a>";
						break;
					case "dab":
						//print_r($fields);
						$description = "";
						///////////////////
						// Check for icons or descriptions in fields[3]
						// 00 = neither; 10 = icons no desc; 01 = desc no icons; 11 = both
						///////////////////
						if ( isset( $fields["3"] ) ) {
							switch ( $fields["3"] ) {
								case "00":
									$show_icons = "";
									$show_desc  = "";
									$show_rank  = 0;
									break;
								case "10":
									$show_icons = "yes";
									$show_desc  = "";
									$show_rank  = 0;
									break;
								case "01":
									$show_icons = "";
									$show_desc  = 1;
									$icons      = "";
									break;
								case "11":
									$show_icons = "yes";
									$show_desc  = 1;
									break;
							}
						}
						$query = "SELECT location, access_restrictions, format, ctags, helpguide, citation_guide, description, call_number, t.title
                                    FROM location l, location_title lt, title t
                                    WHERE l.location_id = lt.location_id
                                    AND lt.title_id = t.title_id
                                    AND t.title_id = $fields[1]";
						//print $query . "<br /><br />";
						$result = $db->query( $query );

						foreach ( $result as $myrow ) {

							// eliminate final line breaks -- offset fixed 11/15/2011 agd
							$myrow[6] = preg_replace( '/(<br \/>)+/', '', $myrow[6] );
							// See if it's a web format
							if ( $myrow[2] == 1 ) {
								if ( ! \SubjectsPlus\Control\RecordSettings::isRestricted( $myrow[1] ) ) {
									$url        = $myrow[0];
									$rest_icons = "unrestricted";
								} else {
									$url        = htmlspecialchars( sp_access_url( $myrow[0], $myrow[1] ), ENT_QUOTES ); // escaped for the href
									$rest_icons = "restricted";
								}

								$current_ctags = explode( "|", (string) $myrow[3] );

								// add our $rest_icons info to this array at the beginning
								array_unshift( $current_ctags, $rest_icons );

								if ( $show_icons == "yes" ) {
									$icons = showIcons( $current_ctags );
								}

								if ( $show_desc == 1 ) {
									// if we know the subject_id, good; for public, must look up
									$subject_id = '';
									if ( isset( $_GET["subject_id"] ) ) {
										$subject_id = $_GET["subject_id"];
									} elseif ( isset( $_GET["subject"] ) ) {
										$r1         = $db->queryWithPreparedStatement( "SELECT subject_id FROM subject WHERE shortform = :sf", NULL, array( ':sf' => (string) $_GET["subject"] ) );
										$subject_id = $r1 ? $r1[0][0] : ''; // was concatenated into the SQL (SQL injection)
									}

									$override = findDescOverride( $subject_id, $fields[1] );
									// if they do want to display the description:
									if ( $override != "" ) {
										// show the subject-specific "description_override" if it exists
										$description = "<br />" . scrubData( $override );
									} else {
										$description = "<br />" . scrubData( $myrow[6] );
									}
									//$description = "<br />$myrow[9]";
								}
								$tokenized .= "<a href=\"$url\" $target>$myrow[8]</a> $icons $description";
							} else {
								// It's print
								$format = "other";
								if ( $show_icons == "yes" ) {
									$icons = showIcons( $current_ctags );
								}
								if ( $show_desc != "" ) {
									$description = "<br />$myrow[6]";
								}

								// Simple Print (2), or Print with URL (3)
								if ( $myrow[2] == 3 ) {
									$tokenized .= "<em>$myrow[8]</em><br />" . sp__( "" ) . "
                                        <a href=\"$myrow[0]\" $target>$myrow[7]</a>
                                        $icons $description";
								} else {

									// check if it's a url
									if ( preg_match( '/^(https?|www)/', $myrow[0] ) ) {
										$tokenized .= "<a href=\"$myrow[0]\" $target>$myrow[8]</a> $icons $description";
									} else {
										$tokenized .= "$myrow[8] <em>$myrow[0]</em> $icons $description";
									}
								}
							}
						}
						break;
					case 'sss':
						global $tel_prefix;

						$querier = new Querier();
						$qs      = "SELECT lname, fname, email, tel, title from staff WHERE email IN ('" . str_replace( ',', "','", $fields[1] ) . "') ORDER BY lname, fname";

						//print $qs;

						$staffArray = $querier->query( $qs );

						foreach ( $staffArray as $value ) {

							// get username from email
							$truncated_email = explode( "@", (string) $value[2] );

							$staff_picture = $relative_asset_path . "users/_" . $truncated_email[0] . "/headshot.jpg";

							// Output Picture and Contact Info
							$tokenized .= "
                          <div class=\"clearboth\"><img src=\"$staff_picture\" alt=\"Picture: $value[1] $value[0]\"  class=\"staff_photo2\" align=\"left\" style=\"margin-bottom: 5px;\" />
                          <p><a href=\"mailto:$value[2]\">$value[1] $value[0]</a><br />$value[4]<br />
                          Tel: $tel_prefix $value[3]</p>\n</div>\n";
						}
						break;
					case 'toc':
						$lobjTocPluslet = new Pluslet_TOC( '', '', $our_subject_id );
						$lobjTocPluslet->setTickedItems( explode( ',', (string) $fields[1] ) );
						$lobjTocPluslet->setHideTitleBar( 1 );
						$tokenized .= $lobjTocPluslet->output();
						break;

				}
			} elseif ( preg_match( '/{|}/', $part ) && preg_match( '/\bdab\b|\bfaq\b|\bcat\b|\bfil\b/', $part ) ) { // looks kinda like a token
				$tokenized .= "<span style='background-color:yellow'>BROKEN TOKEN: " . $part . "</span>";
			} else {
				$tokenized .= $part;
			}
		} // end foreach
	} else {

		$our_text = $our_text;

		return $our_text;
	}
	$our_text = $tokenized;

	return $our_text;
}

function listGuides( $search = "", $type = "all", $display = "default" ) {
	$db = new Querier();

	$andclause = "";
	global $guide_path;

	if ( $search != "" ) {
		$search    = scrubData( $search );
		$andclause .= " AND subject LIKE '%" . $db->quote( $search ) . "%'";
	}

	if ( $type != "all" ) {
		$andclause .= " AND type=" . $db->quote( $type ) . "";
	}

	$q = "SELECT shortform, subject, type FROM subject WHERE active = '1' " . $andclause . " AND type != 'Placeholder' ORDER BY subject";
	// $r = $db->query($q);
	//print $q;

	$row_count = 0;
	$colour1   = "oddrow";
	$colour2   = "evenrow";

	$db = new Querier;

	switch ( $display ) {
		case "default":

			$list_guides = "<table class=\"footable foo3\" width=\"98%\" data-filter=#filter-guides>
                    <thead>
                      <tr class=\"staff-heading\">
                        <th data-sort-ignore=\"false\">Name</th>
                        <th data-hide=\"phone,mid\" data-sort-ignore=\"true\">Type</th>
                      </tr>
                    </thead>";
			foreach ( $db->query( $q ) as $myrow ) {

				$row_colour = ( $row_count % 2 ) ? $colour1 : $colour2;

				$guide_location = $guide_path . $myrow[0];

				$list_guides .= "<tr class=\"zebra type-$myrow[2]\" style=\"height: 1.5em;\">
     <td><a href=\"$guide_location\">" . htmlspecialchars_decode( $myrow[1] ) . "</a> 
        <div class=\"list_bonus\"></div></td>
        <td class=\"subject\">{$myrow[2]}</td>
         </tr>\n";
				$row_count ++;
			}
			$list_guides .= "</table>";
			break;
		case "2col":

			$col_1 = "<div class=\"pure-u-1-2\">";
			$col_2 = "<div class=\"pure-u-1-2\">";

			foreach ( $db->query( $q ) as $myrow ) {

				$guide_location = $guide_path . $myrow[0];

				$our_item = "<li><a href=\"$guide_location\">" . htmlspecialchars_decode( $myrow[1] ) . "</a>
        <div class=\"list_bonus\">$myrow[2]</div>
        </li>";

				if ( $row_count & 1 ) {
					// odd
					$col_2 .= $our_item;
				} else {
					// even
					$col_1 .= $our_item;
				}

				$row_count ++;
			}

			$col_1 .= "</div>";
			$col_2 .= "</div>";


			$list_guides = "<div class=\"pure-g guide_list\">" . $col_1 . $col_2 . "</div>";

			break;
	}


	return $list_guides;
}

function apiGetCourseGuidesList() {
	global $PublicPath;
	$result = array();
	$db     = new Querier();

	$q           = "Select subject_id, subject, shortform, type, description, keywords
      FROM subject where active = '1' and type != 'Placeholder' and type = 'Course' order by subject";
	$course_guides = $db->query( $q, 2 );

	foreach ( $course_guides as &$course_guide ) {
		$course_guide['subject_url'] = $PublicPath . 'guide.php?subject=' . $course_guide['shortform'];
	}

	$result['course_guides'] = $course_guides;

	header( 'Content-type: application/json' );
	echo json_encode( $result );

}

function apiGetCollectionsList() {
	global $PublicPath;
	$result = array();
	$db     = new Querier();

	$q           = "SELECT collection_id, title, description, shortform
FROM collection
ORDER BY title;";
	$collections = $db->query( $q , 2);

	foreach ( $collections as $collection ) {
		$collection_id                = $collection['collection_id'];
		$collection['collection_url'] = $PublicPath . 'collection.php?d=' . $collection['shortform'];
		$subjects                     = getSubjectsByCollectionId( $collection_id );

		if ( ! empty( $subjects ) ) {
			$collection['subjects'] = $subjects;
			$result['collections'][]  = $collection;
		}
	}


	header( 'Content-type: application/json' );
	echo json_encode( $result);
}

function getSubjectsByCollectionId( $collection_id ) {
	$result = array();
	$db     = new Querier();

	$q = "SELECT s.subject_id, s.subject, s.shortform, s.description, s.keywords
FROM subject s,
     collection_subject cs,
     collection c
WHERE s.subject_id = cs.subject_id
  AND cs.collection_id = c.collection_id
  AND c.collection_id = $collection_id AND s.active = 1 ORDER BY cs.sort";

	$subjects = $db->query( $q , 2);

	if ( ! empty( $subjects ) ) {
		generateSubjectUrl( $subjects );
		$result = $subjects;
	}

	return $result;
}

function generateSubjectUrl( &$subjects ) {
	global $PublicPath;
	foreach ( $subjects as &$subject ) {
		if ( ! empty( $subject['shortform'] ) ) {
			$subject['subject_url'] = $PublicPath . 'guide.php?subject=' . $subject['shortform'];
		}
	}
}

function apiGetTopicGuidesList() {
	global $PublicPath;
	$result = array();
	$db     = new Querier();

	$q           = "Select subject_id, subject, shortform, type, description, keywords
      FROM subject where active = '1' and type != 'Placeholder' and type = 'Topic' order by subject";
	$course_guides = $db->query( $q, 2 );

	foreach ( $course_guides as &$course_guide ) {
		$course_guide['subject_url'] = $PublicPath . 'guide.php?subject=' . $course_guide['shortform'];
	}

	$result['topic_guides'] = $course_guides;

	header( 'Content-type: application/json' );
	echo json_encode( $result );

}

function listCollections( $search = "", $display = "default", $show_children = "false" ) {
	$db = new Querier();

	$whereclause = "";
	global $guide_path;

	if ( $search != "" ) {
		$search      = scrubData( $search );
		$whereclause .= " WHERE subject LIKE '%" . $db->quote( $search ) . "%'";
	}


	$q        = "SELECT collection_id, title, description, shortform FROM $whereclause collection ORDER BY title";
	$r        = $db->query( $q );
	$num_rows = count( $r );

	$switch_row = round( $num_rows / 2 );

	$layout = "";

	//print $q;
	$row_count = 1;
	$colour1   = "oddrow";
	$colour2   = "evenrow";

	if ( $num_rows < 1 ) {
		return;
	}

	switch ( $display ) {
		case "default":

			$list_collections = "<table class=\"item_listing\" width=\"98%\">";

			foreach ( $r as $myrow ) {

				$row_colour = ( $row_count % 2 ) ? $colour1 : $colour2;

				$guide_location = "collection.php?d=" . $myrow[3];

				$list_collections .= "<tr class=\"zebra $row_colour\" style=\"height: 1.5em;\">
        <td><a href=\"$guide_location\">" . htmlspecialchars_decode( $myrow[1] ) . "</a>
        <div style=\"font-size: .9em;\">$myrow[2]</div></td></tr>\n";

				$row_count ++;
			}

			$list_collections .= "</table>";
			break;

		case "2col":

			// for 2 col
			$col_1 = "<div class=\"col-sm-6 col-lg-12 col-xl-6\"><ul class=\"guide-listing list-unstyled\">";
			$col_2 = "<div class=\"col-sm-6 col-lg-12 col-xl-6\"><ul class=\"guide-listing list-unstyled\">";

			foreach ( $r as $myrow ) {

				$icon        = "fa-plus-circle";
				$title_hover = "See all guides in this collection";

				$guide_location = "collection.php?d=" . $myrow[3];
				$list_bonus     = "<p class=\"collection-description\">$myrow[2]</p><ul class=\"collection_list\">";

				// Here, we want to show the guides associated with that collection
				if ( $show_children != "false" ) {
					// get all kids
					$q2        = "SELECT s.subject_id, s.subject, s.shortform FROM subject s, collection_subject cs, collection c 
        WHERE s.subject_id = cs.subject_id AND cs.collection_id = c.collection_id AND c.collection_id = $myrow[0] AND s.active = 1 ORDER BY cs.sort";
					$r2        = $db->query( $q2 );
					$num_rows2 = count( $r2 );

					foreach ( $r2 as $mysubguide ) {

						$guide_location2 = $guide_path . $mysubguide[2];

						$list_bonus .= "<li><a href=\"$guide_location2\" class=\"no-decoration default\">$mysubguide[1]</a></li>";
					}

				}

				$our_item = "<li title=\"{$title_hover}\"><i class=\"fas fa {$icon}\"></i> <a href=\"$guide_location\" class=\"no-decoration default\">" . htmlspecialchars_decode( $myrow[1] ) . "</a>
      <div class=\"guide_list_bonus\">$list_bonus</ul></div>
      </li>";

				if ( $row_count <= $switch_row ) {
					// first col
					$col_1 .= $our_item;

				} else {
					// even
					$col_2 .= $our_item;
				}

				$row_count ++;

			} // end foreach

			$col_1 .= "</ul></div>";
			$col_2 .= "</ul></div>";

			$layout           .= "<div class=\"tab-pane guide_list active\" id=\"section-Collection\" role=\"tabpanel\" aria-labelledby=\"show-Collection\"><div class=\"guide-list-expand\">Expand/hide all</div><div class=\"guide_list_header\"><a name=\"section-Collection\"></a><h2>" . sp__( "Guide Collections" ) . "</h2></div><div class=\"row\">" . $col_1 . $col_2 . "</div></div>";
			$list_collections = $layout;

			break;
	}

	return $list_collections;
}

function listGuideCollections( $collection_shortform ) {

	global $guide_path;
	global $AssetPath;
	global $collection_thumbnail_default;

	$db = new Querier();

	$collection_shortform = scrubData( $collection_shortform, "text" );

	$qCollection = "SELECT c.collection_id, c.title, c.description, s.subject, s.shortform, s.redirect_url, s.description, s.keywords, s.type, s.last_modified 
FROM collection c, collection_subject cs, subject s
WHERE c.collection_id = cs.collection_id
AND cs.subject_id = s.subject_id
AND c.shortform = " . $db->quote( html_entity_decode( (string) $collection_shortform, ENT_QUOTES, 'UTF-8' ) ) . " 
AND s.active = '1'
ORDER BY cs.sort";

	$rCollection = $db->query( $qCollection );

// prepare striping
	$colour1 = "oddrow";
	$colour2 = "evenrow";

	$list_guides = "<table class=\"item_listing\" width=\"98%\">";

	foreach ( $rCollection as $key => $value ) {

		$row_colour = ( $key % 2 ) ? $colour1 : $colour2;

		$guide_location = $guide_path . $value[4];
		$thumbnail      = $AssetPath . "images/guide_thumbs/$value[4].jpg";
		//$thumbnail_default = "$AssetPath/images/guide_thumbs/chc.jpg"; //um only
		$thumbnail_default = $collection_thumbnail_default;

		//check if appropriate image exists; otherwise use the default one
		if ( ! @getimagesize( $thumbnail ) ) {
			$thumbnail = $thumbnail_default;
		}

		// Stick in the title if it's the first row
		if ( $key == 0 ) {
			$list_guides .= "<span id=\"collection_parent_title\">$value[1]</span>";
		}

		$list_guides .= "<tr class=\"zebra $row_colour\">
    <td><div class=\"d-flex flex-row flex-nowrap mb-3\"><div class=\"collection-image\"><img class=\"staff_photo\" title=\"" . $value[3] . "\" alt=\"" . $value[3] . "\" src=\"$thumbnail\" /></div><div><h4><a href=\"$guide_location\" class=\"default\">" . htmlspecialchars_decode( $value[3] ) . "</a></h4>{$value[6]}</div></div></td></tr>";
	}

	$list_guides .= "</table>";

	return $list_guides;
}


// This just returns whether or not you want an anchor target to open in new window
// made this puppy a function in case people want to use it elsewhere

function targetBlanker() {
	global $target_blank;

	if ( isset( $target_blank ) && $target_blank == true ) {
		$target = "target=\"_blank\"";
	} else {
		$target = "";
	}

	return $target;

}


/**
 * Created by PhpStorm.
 * User: cbrownroberts
 * Date: 4/21/17
 * Time: 11:27 AM
 * Send a message to Slack using Webhooks integration
 */

// (string) $message - message to be passed to Slack
// (string) $channel - channel in which to write the message
// (string) $icon - You can set up custom emoji icons to use with each message
function sendSlackMsg( $message, $channel, $icon, $webhookUrl ) {
	$channel = ( $channel ) ? $channel : "talkback";
	$data    = "payload=" . json_encode( array(
			"channel"    => "#{$channel}",
			"text"       => $message,
			"icon_emoji" => $icon
		) );

	// Get your webhook endpoint from your Slack settings
	$ch = curl_init( $webhookUrl );
	curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, "POST" );
	curl_setopt( $ch, CURLOPT_POSTFIELDS, $data );
	curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
	$result = curl_exec( $ch );
	curl_close( $ch );


	return $result;
}

/**
 * Created by PhpStorm.
 * User: cbrownroberts
 * Date: 9/27/17
 * Time: 11:27 AM
 * Add Google Invisible reCaptcha to form
 * https://www.google.com/recaptcha
 *
 * Keys are set per site in config.php ($talkback_recaptcha_site_key / $talkback_recaptcha_secret_key).
 *
 * this must be in the head
 * <script src='https://www.google.com/recaptcha/api.js'></script>
 *
 * Client Side
 * button
 * <button
 * class="g-recaptcha"
 * data-sitekey="YOUR-SITE-KEY"
 * data-callback="YourOnSubmitFn">
 * Submit
 * </button>
 *
 * callback function for data-callback
 *
 *  function onSubmit(token) {
 * document.getElementById("tellus").submit();
 * }
 *
 * Server Side
 * secret (required) the secret key from config.php
 * response (required) The value of 'g-recaptcha-response'.
 * remoteip (optional) The end user's ip address.
 *
 *   // Call the function post_captcha
 * $res = post_captcha($_POST['g-recaptcha-response']);
 * //var_dump($res);
 *
 * if (!$res['success']) {
 * // What happens when the reCAPTCHA is not properly set up
 * $feedback = $submission_failure_feedback;
 * } else {
 * failed
 * }
 *
 * See subjects/themes/um/talkback.php for example
 *
 *
 */
function post_captcha( $user_response ) {

	// reCaptcha info
	global $talkback_recaptcha_secret_key;

	$secret      = $talkback_recaptcha_secret_key;
	$remoteip    = $_SERVER["REMOTE_ADDR"];
	$curlopt_url = "https://www.google.com/recaptcha/api/siteverify";


	$fields        = array(
		'secret'   => (string) $secret,
		'response' => (string) $user_response,
		'remoteip' => (string) $remoteip
	);
	$fields_string = http_build_query( $fields ); // values were concatenated without URL-encoding

	$ch = curl_init();
	curl_setopt( $ch, CURLOPT_URL, $curlopt_url );
	curl_setopt( $ch, CURLOPT_POST, count( $fields ) );
	curl_setopt( $ch, CURLOPT_POSTFIELDS, $fields_string );
	curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );

	$result = curl_exec( $ch );
	curl_close( $ch );

	return json_decode( (string) $result, true );
}

/**
 * Encryption of optional staff personal data (enabled with $stats_encryption_enabled in config.php).
 *
 * New values are written as "v2:" + base64(iv . tag . ciphertext) using AES-256-GCM with a key
 * derived from $sp_encryption_key (set this in config.php to a long random string and keep it safe:
 * losing it makes the data unreadable).
 *
 * Values written by SubjectsPlus <= 4.6 (mcrypt Rijndael-256/CBC, keyed by the legacy $cryptKey)
 * are still readable through phpseclib, and are re-encrypted the next time the record is saved.
 */
function sp_encryption_key() {
	global $sp_encryption_key, $cryptKey;
	$secret = !empty( $sp_encryption_key ) ? $sp_encryption_key : ( isset( $cryptKey ) ? (string) $cryptKey : '' );
	if ( $secret === '' ) {
		trigger_error( 'SubjectsPlus: $sp_encryption_key is not set in config.php; staff data encryption is using an empty key.', E_USER_WARNING );
	}

	return hash( 'sha256', 'subjectsplus-v2|' . $secret, true );
}

function encryptIt( $q ) {
	$iv  = random_bytes( 12 );
	$tag = '';
	$ct  = openssl_encrypt( (string) $q, 'aes-256-gcm', sp_encryption_key(), OPENSSL_RAW_DATA, $iv, $tag );
	if ( $ct === false ) {
		throw new \RuntimeException( 'SubjectsPlus: encryption failed' );
	}

	return 'v2:' . base64_encode( $iv . $tag . $ct );
}

function decryptIt( $q ) {
	if ( $q === null || $q === '' ) {
		return '';
	}

	if ( strncmp( $q, 'v2:', 3 ) === 0 ) {
		$raw = base64_decode( substr( $q, 3 ), true );
		if ( $raw === false || strlen( $raw ) < 28 ) {
			return '';
		}
		$pt = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', sp_encryption_key(), OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ) );

		return $pt === false ? '' : $pt;
	}

	return sp_legacy_decrypt( $q );
}

/**
 * Read values encrypted by SubjectsPlus <= 4.6 (mcrypt MCRYPT_RIJNDAEL_256 + CBC, zero padding).
 */
function sp_legacy_decrypt( $q ) {
	global $cryptKey;
	$key = md5( (string) $cryptKey );
	$iv  = md5( md5( (string) $cryptKey ) );
	$raw = base64_decode( $q, true );
	if ( $raw === false || $raw === '' || strlen( $raw ) % 32 !== 0 ) {
		// Not encrypted (e.g. saved before encryption was switched on): return as-is.
		return $q;
	}

	$cipher = new \phpseclib3\Crypt\Rijndael( 'cbc' );
	$cipher->setBlockLength( 256 );
	$cipher->setKeyLength( 256 );
	$cipher->setKey( $key );
	$cipher->setIV( $iv );
	$cipher->disablePadding();

	return rtrim( $cipher->decrypt( $raw ), "\0" );
}

/**
 * "?v=<version>" for the bundled CSS/JS URLs, so browsers load the new files right after an
 * upgrade instead of using their cached copy (the bundles are cached for 6 hours).
 */
function sp_asset_v() {
	static $v = null;
	if ( $v === null ) {
		$sp_version = '';
		include __DIR__ . '/version.php';
		$v = '?v=' . rawurlencode( (string) $sp_version );
	}
	return $v;
}
