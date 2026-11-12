<?php
/**
 *   @file header.php
 *   @brief Include file, called by virtually everything.
 *   @description includes config and functions files, language file if not English; checks authorization
 *   to view calling page; builds header portion of page
 *
 *   @author adarby
 *   @date mar 2011
 */

require_once("autoloader.php");

require_once("functions.php");

//include_once(dirname(dirname(dirname(__FILE__))) . "/lib/CSRF-Protector-PHP-nojs-support/libs/csrf/csrfprotector.php");

use SubjectsPlus\Control\DBConnector;
use SubjectsPlus\Control\BuildNav;
use SubjectsPlus\Control\Querier;



//added in order to redirect to proper page if config file doesn't exist or if only placeholder
if( !file_exists( dirname(__FILE__) . '/config.php' ) || filesize( dirname(__FILE__) . '/config.php' ) < 10 )
{
	$lstrURL = getControlURL();

	if( !file_exists( dirname(__FILE__) . '/config-default.php' ) )
	{
		header("location:{$lstrURL}includes/configErrorPage.php?error=nobasefile");
		exit;
	}

	header( "location:{$lstrURL}installer/" ); // no config.php yet: start the installer wizard
	exit;
}


require_once(dirname(__FILE__) . "/config.php");

// "wintype" only ever means "pop" (open in a pop-up); several pages echoed it back unescaped (reflected XSS)
foreach ( array( '_GET', '_POST', '_REQUEST' ) as $sp_wt_src ) {
	if ( isset( $GLOBALS[ $sp_wt_src ]['wintype'] ) && $GLOBALS[ $sp_wt_src ]['wintype'] !== 'pop' ) {
		$GLOBALS[ $sp_wt_src ]['wintype'] = '';
	}
}
if ( function_exists( 'sp_apply_error_policy' ) ) { sp_apply_error_policy(); }


if ((isset($use_shibboleth) && $use_shibboleth) == TRUE) {
	
	isCool($_SERVER['mail'],"", true);
	
} else {
	
	$db = new Querier;
	// start our session
	if ( session_status() !== PHP_SESSION_ACTIVE ) { session_start(); }
	
}

//Initialise CSRFGuard library
//csrfProtector::init();



//print_r($_SESSION);
//added in order to redirect to proper page if cannot connect to database. Only check if $tryDB variable doesn't exists and says no

    /*
if( !isset($tryDB) || $tryDB != 'no')
{
	try {
		@$dbc = new DBConnector($uname, $pword, $dbName_SPlus, $hname);
	} catch (Exception $e) {
		$lstrURL = getControlURL();

		if ( strstr( $e->getMessage() , 'Could not choose database.' ) )
		{
			header("location:{$lstrURL}includes/configErrorPage.php?error=database");
		}else
		{
			header("location:{$lstrURL}includes/configErrorPage.php?error=connection");
		}
		exit();
	}
}
*/
//added in order to redirect to proper page if SubjectsPlus is not installed. Only check if $installCheck variable doesn't exists and says no
if( !isset( $installCheck ) || $installCheck != 'no' )
{
	$isInstalled = isInstalled();

	if( !$isInstalled )
	{
		$lstrURL = getControlURL();

		header( "location:{$lstrURL}installer/" );
		exit;
	}
}

//added in order to redirect to proper page if SubjectsPlus is not updated to 2.0. Only check if $updateCheck variable doesn't exists and says no
if( !isset( $updateCheck ) || $updateCheck != 'no' )
{
	$isUpdated = isUpdated();

	if( !$isUpdated )
	{
		$lstrURL = getControlURL();

		header("location:{$lstrURL}update.php");
		exit;
	}
}

//added in order to redirect to proper page if user has not logged in. Only check if $sessionCheck variable doesn't exists and says no
if( !isset($sessionCheck) || $sessionCheck != 'no' )
{
	$sessionCheck = checkSession();

	if ($sessionCheck == "failure" ) {

		// set a cookie for the page they wanted to visit
		session_regenerate_id( true );
        if(!isset($_SESSION))
        {
		if ( session_status() !== PHP_SESSION_ACTIVE ) { session_start(); }
        }
		$_SESSION['desired_page'] = sp_safe_local_redirect( $_SERVER["REQUEST_URI"] ?? '', '' ); // no //host open redirects
		// Send to login page for pword
		$login_page = $CpanelPath . "login.php";
		header("location:$login_page");
		exit();
	}

	// Now, check if they have access to the records or admin modules.
	if( !isset($subcat) ) $subcat = '';

	if ($subcat == "admin" && ( ! isset( $_SESSION["admin"] ) || $_SESSION["admin"] != 1 ) ) { // no notice for non-admins
		header("location:$CpanelPath");
		exit; // the page used to keep running (and processing POSTs) after the redirect
	}

	if ($subcat == "guides" && ( ! isset( $_SESSION["records"] ) || $_SESSION["records"] != 1 ) ) {
		header("location:$CpanelPath");
		exit; // the page used to keep running (and processing POSTs) after the redirect
	}

	if ($subcat == "records" && ( ! isset( $_SESSION["records"] ) || $_SESSION["records"] != 1 ) ) {
		header("location:$CpanelPath");
		exit; // the page used to keep running (and processing POSTs) after the redirect
	}

	if ($subcat == "records" && isset($_SESSION["NOFUN"])) {
		header("location:$CpanelPath");
		exit; // the page used to keep running (and processing POSTs) after the redirect
	}

	// refuse cross-site form posts to staff pages (CSRF); same-origin pages are unaffected
	sp_require_same_origin();

	// audit trail: every form sent to an Admin page (field names only, never values) (4.7.1)
	if ( $subcat == "admin" && ( $_SERVER['REQUEST_METHOD'] ?? '' ) === 'POST' ) {
		\SubjectsPlus\Control\AuditLog::add( 'admin.form', 'page', basename( (string) ( $_SERVER['SCRIPT_NAME'] ?? '' ) ),
			'Form sent to ' . basename( (string) ( $_SERVER['SCRIPT_NAME'] ?? '' ) ) . ( isset( $_REQUEST['action'] ) && is_string( $_REQUEST['action'] ) ? ' (' . substr( $_REQUEST['action'], 0, 40 ) . ')' : '' ),
			array( 'tab' => isset( $_GET['tab'] ) && is_string( $_GET['tab'] ) ? substr( $_GET['tab'], 0, 40 ) : null,
			       'fields' => array_slice( array_keys( $_POST ), 0, 40 ) ) );
	}

	if (isset($header) && $header == "noshow") {
		// used when we want authentication, but don't need to show the header stuff
		return;
		exit;
	}
}

// a translation or two. . .
$required_text = _("Required");
$rusure = _("Are you sure?");
$textyes = _("Yes");
$textno = _("No");

header("Content-Type: text/html; charset=utf-8");

// You may need to comment out the next two lines if you don't have mbstring enabled in PHP
mb_language('uni');
mb_internal_encoding('UTF-8');
?>
<!DOCTYPE html>
<html>
  <head>
    <title><?php print sp_h( html_entity_decode( strip_tags( (string) ( $page_title ?? '' ) ), ENT_QUOTES, 'UTF-8' ) ); ?></title>
    <meta charset="utf-8">
    <link rel="stylesheet" href="<?php echo getControlURL(); ?>includes/css.php<?php echo sp_asset_v(); ?>" type="text/css" media="all" />
    <script src="<?php echo getControlURL(); ?>includes/js_vendor.php<?php echo sp_asset_v(); ?>" type="text/javascript"></script>
    
    <?php
    // this is for custom css, set by user and stored in database


    if (isset($_SESSION['css'])) {
      print "<link id=\"css_choice\" href=\"" . sp_h( $AssetPath . "css/theme/" . preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $_SESSION['css'] ) . ".css" ) . "\" rel=\"stylesheet\" type=\"text/css\" />";
    }
    ?>

  </head>
  <body id="controlpage">

    <?php
    // for those times when you  need the CSS and jQuery, but no header . . .
    if (isset($no_header) && $no_header == "yes") {

      return;
    }
    ?>


<!-- ///////////////////////////////////////////
   // Structure for Guide Backend (Header) - PV
   ////////////////////////////////////////////-->

	<header id="header">
	    <div class="pure-g">
	        <div class="pure-u-1">	    		

	        <?php
	        // Our Nav is built here:
	        $b_box = new BuildNav();
	        $b_box->displayNav();
	        ?>
	    		
			</div>
		</div>
	</header>
	<?php
	// tell admins while installer files are still on the server (they refuse to run once installed,
	// but are best removed)
	if ( ! empty( $_SESSION['admin'] ) ) {
		$sp_left = array();
		// the wizard switches itself off after installation, so only files from older versions and a
		// leftover install code are reported
		foreach ( array( 'control/setup-config.php', 'control/install.php', 'control/includes/install-code.php' ) as $sp_f ) {
			if ( file_exists( dirname( __DIR__, 2 ) . '/' . $sp_f ) ) {
				$sp_left[] = $sp_f;
			}
		}
		if ( $sp_left ) {
			print '<div role="alert" class="sp-installer-left" style="background:#fff4e5;border-left:5px solid #e07b00;margin:8px 16px;padding:10px 14px;font-size:15px">'
				. ( $sp_left === array( 'control/includes/install-code.php' )
					? _( 'The one-time install code is still on the server (the installer could not delete it because the folder is read-only). Remove it:' )
					: _( 'Files from an earlier installation are still on the server. Remove them:' ) )
				. ' <code>rm ' . sp_h( implode( ' ', $sp_left ) ) . '</code></div>';
		}
	}

	// tell admins when database updates for this version have not been applied yet
	// (the new admin pages and record boxes stay hidden or incomplete until they are).
	if ( ! empty( $_SESSION['admin'] ) && ( ! isset( $page_title ) || ! in_array( $page_title, array( 'Database Updates', 'Upgrade' ), true ) ) ) {
		if ( ! isset( $_SESSION['sp_dbu_pending'] ) || $_SESSION['sp_dbu_checked'] < time() - 600 ) {
			require_once __DIR__ . '/../../tools/migrations.php';
			$sp_dbu_conn = ( new Querier )->getConnection();
			$sp_dbu_res  = $sp_dbu_conn->query( 'SELECT id FROM jgu_schema_migration' );
			$sp_dbu_done = $sp_dbu_res ? $sp_dbu_res->fetchAll( PDO::FETCH_COLUMN ) : array();
			$_SESSION['sp_dbu_pending'] = count( array_diff( array_keys( sp_migrations() ), $sp_dbu_done ) );
			$_SESSION['sp_dbu_checked'] = time();
		}
		if ( $_SESSION['sp_dbu_pending'] > 0 ) {
			print '<div role="alert" style="background:#fff4e5;border-left:5px solid #e07b00;margin:8px 16px;padding:10px 14px;font-size:15px">'
				. sprintf( _( '%d database update(s) for this version of SubjectsPlus have not been applied, so some features (Record Settings, Workflow, Off-campus Access) are missing or incomplete.' ), (int) $_SESSION['sp_dbu_pending'] )
				. ' <a href="' . $CpanelPath . 'admin/upgrade.php"><strong>' . _( 'Finish the upgrade now' ) . '</strong></a></div>';
		}
	}
	?>

      <?php
      // This is used in control/records to link to the public site -- you probably don't need to change


      if (isset($_COOKIE["sub_shortform"])) {
        if ($mod_rewrite == 1) {
          $path_to_subject = $PublicPath . $_COOKIE["sub_shortform"];
        } else {
          $path_to_subject = $PublicPath . "guide.php?subject=" . $_COOKIE["sub_shortform"];
        }
      }

      // This is for the guides page, where everything isn't wrapped in a maincontent div
      if (isset($tertiary_nav) && $tertiary_nav == "yes") {

      } else {
       print "<div id=\"maincontent\">";


      }
