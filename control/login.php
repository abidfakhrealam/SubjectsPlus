<?php
/**
 * @file login.php
 * @brief Where you login--or not.
 *
 * @author adarby, dgonzalez
 * @date last update, fall 2012.  Forgot password functionality added.
 */

//expected postvars
$postvar_thecount = "";
$postvar_username = "";
$postvar_password = "";

if ( isset( $_POST['thecount'] ) && isset( $_POST['username'] ) && isset( $_POST['password'] ) ) {
	$postvar_thecount = (int) $_POST['thecount'];
	$postvar_username = $_POST['username'];
	$postvar_password = $_POST['password'];
}
// keep the password exactly as typed for verification; $postvar_password is HTML-escaped below.
$raw_password = isset( $_POST['password'] ) ? (string) $_POST['password'] : '';
$debugger = "no";

include( "includes/functions.php" );
include( "includes/autoloader.php" );


//added in order to redirect to proper page if config file doesn't exist
if ( ! file_exists( "includes/config.php" ) || filesize( "includes/config.php" ) < 10 ) {
	$lstrURL = getControlURL();

	if ( ! file_exists( "includes/config-default.php" ) ) {
		header( "location:{$lstrURL}includes/configErrorPage.php?error=nobasefile" );
		exit;
	}

	header( "location:{$lstrURL}installer/" ); // no config.php yet: start the installer wizard
	exit;
}

require_once( "includes/config.php" );

// Let's start by making sure they aren't using Shibboleth; if someone is in Shibboleth, but not in SP, they could land on this page

if ( isset( $use_shibboleth ) && $use_shibboleth == true ) {

	header("Location: index.php");

	$message = "<h2>" . _( "Shibboleth Authentication Error" ) . "</h2>
    <p>" . _( "If you are seeing this page, please contact the" ) . " <a href=\"mailto:$administrator_email\">" . ( "SubjectsPlus Admin" ) . "</a>.</p>
    <p>" . _( "Perhaps you have not yet been entered as a user into SubjectsPlus, or your email address has changed since you were entered." ) . "</p>";

	$logo = "<img src=\"$AssetPath" . "images/admin/logo_v3_full.png\" border=\"0\" />\n
<br />";

	?>

    <!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN"
            "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
    <html xmlns="http://www.w3.org/1999/xhtml">
    <head>
        <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
        <link rel="stylesheet" href="<?php echo getControlURL(); ?>includes/css.php<?php echo sp_asset_v(); ?>" type="text/css" media="all"/>
        <title>Shibboleth Failure</title>
    </head>

    <body id="controlpage">

    <div style="margin: 4em auto; width: 500px;">
		<?php

		makePluslet( $logo, $message, "" );
		?>

    </div>

    </body>
    </html>

	<?php


	exit;
}

//added in order to redirect to proper page if we cannot connect to mySQL database
if ( ! isset( $tryDB ) || $tryDB != 'no' ) {
	/*
	global $uname;
	global $pword;
	global $dbName_SPlus;
	global $hname;

		$lstrURL = getControlURL();

		if ( strstr( $e->getMessage() , 'Could not choose database.' ) )
		{
			header("location:{$lstrURL}includes/configErrorPage.php?error=database");
		}else
		{
			header("location:{$lstrURL}includes/configErrorPage.php?error=connection");
		}
		exit();
*/
}


//added in order to redirect to proper page if SubjectsPlus is not installed. Only check if $installCheck variable doesn't exists and says no
if ( ! isset( $installCheck ) || $installCheck != 'no' ) {
	$isInstalled = isInstalled();

	if ( ! $isInstalled ) {
		$lstrURL = getControlURL();

		header( "location:{$lstrURL}installer/" );
		exit;
	}
}

//added in order to redirect to proper page if SubjectsPlus is not updated to 2.0. Only check if $updateCheck variable doesn't exists and says no
if ( ! isset( $updateCheck ) || $updateCheck != 'no' ) {
	$isUpdated = isUpdated();

	if ( ! $isUpdated ) {
		$lstrURL = getControlURL();

		header( "location:{$lstrURL}update.php" );
		exit;
	}
}

//added in order to redirect to control home if already logged in. Only check if $sessionCheck variable doesn't exists and says no
$sessionCheck = checkSession();

if ( $sessionCheck != "failure" ) {
	global $CpanelPath;

	header( "location:$CpanelPath" );
	exit;
}

// If they have tried too many times, send them away
// Could add some sort of log of this failed attempt . . .

if ( $postvar_thecount > 3 ) {
	header( "location:$PublicPath" );
	exit;
}

$postvar_username = scrubData( $postvar_username );
$postvar_password = scrubData( $postvar_password );

// Start the counting after the first $_POST submission

if ( $postvar_username == "" ) {
	$postvar_thecount = 1;
} else {
	$postvar_thecount ++;
}

$success = "no";

$introtext = "
<br />" . _( "Please enter your <strong>credentials</strong> to proceed." ) . "</p>";

$login_form = "<div align=\"center\">\n

<form action=\"login.php\" method=\"post\" style=\"font-size: 1em;\">\n
<input type=\"hidden\" name=\"thecount\" value=\"" . $postvar_thecount . "\" />
<table cellpadding=\"7\" cellspacing=\"0\" border=\"0\" class=\"striped_data\">\n
<tr>\n
<td valign=\"top\" class=\"odd\"><strong>" . _( "Login" ) . "</strong></td>\n
<td valign=\"top\" class=\"odd\" align=\"left\"><input name=\"username\" type=\"text\" value=\"$postvar_username\" size=\"20\" /></td>\n
</tr>\n
<tr>\n
<td valign=\"top\" class=\"even\"><strong>" . _( "Password" ) . "</strong></td>\n
<td valign=\"top\" class=\"even\" align=\"left\"><input name=\"password\" type=\"password\" value=\"\" size=\"20\" /></td>\n
</tr>\n
<tr>\n
<td valign=\"top\" class=\"odd\" colspan=\"2\"><div align=\"center\"><input class=\"button\"  type=\"submit\" value=\"login\" /></div></td>\n
</tr>\n
<tr>\n".
//<td valign=\"top\" class=\"even\" colspan=\"2\"><div align=\"right\"><a href=\"forgotpassword.php\">" . _( "Forgot Password" ) . "</a></div></td>\n
"
</tr>\n
</table>\n
</form>\n
</div>\n";


// external sign-in providers and SIP2
if ( session_status() !== PHP_SESSION_ACTIVE ) {
	session_start();
}
if ( empty( $_SESSION['sp_login_csrf'] ) ) {
	$_SESSION['sp_login_csrf'] = bin2hex( random_bytes( 32 ) );
}
$sp_auth_error = '';
if ( ! empty( $_SESSION['sp_auth_error'] ) ) {
	$sp_auth_error = $_SESSION['sp_auth_error'];
	unset( $_SESSION['sp_auth_error'] );
}
if ( ! sp_auth_local_enabled() ) {
	$postvar_username = ""; // email/password sign-in switched off in config.php
}

// login CSRF: the email/password form must carry this session's token
$sp_login_token_ok = isset( $_POST['sp_login_csrf'] ) && is_string( $_POST['sp_login_csrf'] ) && hash_equals( $_SESSION['sp_login_csrf'], $_POST['sp_login_csrf'] );
if ( $postvar_username != "" && ! $sp_login_token_ok ) {
	$introtext        = "<p align=\"center\"><strong>" . _( "Your session expired. Please try again." ) . "</strong></p><br />";
	$postvar_username = "";
}

if ( ( $postvar_username != "" ) AND ( isset( $postvar_password ) ) ) {

	// just to try to be nice, try appending the defined (in config.php) campus ending (@yourcollege.edu)
	// if the login lacks one
	$pos = strpos( $postvar_username, "@" );
	if ( $pos === false ) {
		$postvar_username = $postvar_username . $email_key;
	}

// try this against the db
// Note that passwords are hashed in the database

	$emailAdd = $postvar_username;

	if ( sp_login_is_throttled( $emailAdd ) ) {
		$checker = "throttled";
	} else {
		// Plain-text password is checked with password_verify() inside isCool().
		$checker = isCool( $emailAdd, $raw_password );
		sp_record_login_attempt( $emailAdd, $checker == "success" );
	}

	if ( $checker == "throttled" ) {
		$introtext = "<p align=\"center\"><strong>" . sprintf( _( "Too many failed login attempts. Please wait %d minutes and try again, or contact your SubjectsPlus administrator." ), ! empty( $sp_login_window_minutes ) ? (int) $sp_login_window_minutes : 15 ) . "</strong></p><br />";
		$success   = "no";
	} elseif ( $checker == "success" ) {
		if ( isset( $_SESSION["desired_page"] ) ) {
			$loc = sp_safe_local_redirect( $_SESSION["desired_page"], "index.php" ); // no //evil.example open redirects
			unset( $_SESSION["desired_page"] );
			header( "location:$loc" );
			exit();
		} else {
			header( "location:index.php" );
			exit();
		}
	} else {
		// Bad credentials, try again.  Only 3 tries before you get bumped
		$introtext = "<p align=\"center\"><strong>" . _( "Please check your email and password and try again.  (Note that login attempts are logged.)" ) . "</strong></p><br />";
		$success   = "no";
	}

	//print $checker;
	//print "<pre>";
	//print_r($_SESSION);
} else {

}

// one button per external provider, a SIP2 form for each library-system provider
$sp_auth_html = '';
if ( $sp_auth_error !== '' ) {
	$sp_auth_html .= '<p class="sp-auth-error" role="alert" style="background:#fdecea;padding:.6em;border-radius:4px">' . $sp_auth_error . '</p>';
}
foreach ( sp_auth_providers() as $sp_pid => $sp_p ) {
	$sp_label = htmlspecialchars( $sp_p['label'] ?? $sp_pid, ENT_QUOTES );
	if ( $sp_p['type'] === 'sip2' ) {
		$sp_auth_html .= '<form action="auth.php" method="post" class="pure-form pure-form-stacked sp-auth-sip2" autocomplete="off">
			<fieldset><legend>' . $sp_label . '</legend>
			<input type="hidden" name="provider" value="' . htmlspecialchars( $sp_pid, ENT_QUOTES ) . '">
			<input type="hidden" name="csrf" value="' . htmlspecialchars( $_SESSION['sp_login_csrf'], ENT_QUOTES ) . '">
			<label for="sip-' . $sp_pid . '-barcode">' . _( "Library card number" ) . '</label>
			<input id="sip-' . $sp_pid . '-barcode" name="barcode" type="text" class="pure-input-1" required>
			<label for="sip-' . $sp_pid . '-pin">' . _( "PIN / password" ) . '</label>
			<input id="sip-' . $sp_pid . '-pin" name="pin" type="password" class="pure-input-1" required>
			<button type="submit" class="pure-button pure-button-primary" style="margin-top:.5em">' . _( "Sign in" ) . '</button>
			</fieldset></form>';
	} else {
		$sp_auth_html .= '<p><a class="pure-button pure-button-primary sp-auth-button" style="display:block;text-align:center" href="auth.php?start=' . rawurlencode( $sp_pid ) . '">' . $sp_label . '</a></p>';
	}
}
if ( $sp_auth_html !== '' && sp_auth_local_enabled() ) {
	$sp_auth_html .= '<p style="text-align:center;color:#666">' . _( "or sign in with your SubjectsPlus password" ) . '</p>';
}

$login_form = $introtext . $sp_auth_html . ( sp_auth_local_enabled() ? '
<form action="login.php" method="post" class="pure-form pure-form-stacked">
<input type="hidden" name="thecount" value="' . (int) $postvar_thecount . '" />
<input type="hidden" name="sp_login_csrf" value="' . sp_h( $_SESSION['sp_login_csrf'] ) . '" />
    <fieldset>
    
        <div class="pure-control-group">
            <label for="email">' . _( "Email" ) . '</label>
            <input id="email" type="email" name="username" value="' . $postvar_username . '" placeholder="' . _( "Email Address" ) . '">
        </div>

        <div class="pure-control-group">
            <label for="password">' . _( "Password" ) . '</label>
            <input id="password" name="password" type="password" placeholder="' . _( "Password" ) . '">
        </div>
        <br />
        <div class="pure-controls">

            <button type="submit" class="pure-button pure-button-primary">' . _( "Submit" ) . '</button>
        </div>
    </fieldset>
    <br />'.
//    <div align="center"><a href="forgotpassword.php">' . _( "I have forgotten my password" ) . '</a></div>
'</form>
' : '' );

$logo = "<img src=\"$AssetPath" . "images/admin/logo_v3_full.png\" border=\"0\" />\n
<br />";


?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <link rel="stylesheet" href="<?php echo getControlURL(); ?>includes/css.php<?php echo sp_asset_v(); ?>" type="text/css" media="all"/>
    <title>Login</title>
</head>

<body id="controlpage">

<div style="margin: 4em auto; width: 350px;">
	<?php

	makePluslet( $logo, $login_form, "" );
	?>

</div>

</body>
</html>

