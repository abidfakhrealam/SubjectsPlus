<?php
/**
 * @file database_updates.php
 * @brief apply pending database updates (migrations) from the browser, for sites where
 *        running "php tools/migrate.php" on the server is inconvenient (e.g. WampServer).
 */

$subsubcat  = "";
$subcat     = "admin";
$page_title = "Database Updates";

include( "../includes/header.php" );
require_once( __DIR__ . "/../../tools/migrations.php" );

global $hname, $uname, $pword, $dbName_SPlus, $db_port, $db_cert_path;

function sp_dbu_h( $s ) {
	return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
}

if ( empty( $_SESSION['sp_dbu_csrf'] ) ) {
	$_SESSION['sp_dbu_csrf'] = bin2hex( random_bytes( 32 ) );
}
$csrf = $_SESSION['sp_dbu_csrf'];

$log   = array();
$error = '';
try {
	$pdo = sp_migrate_connect( $hname, $uname, $pword, $dbName_SPlus, isset( $db_port ) ? $db_port : '', isset( $db_cert_path ) ? $db_cert_path : '' );
	if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['apply'] ) ) {
		if ( ! isset( $_POST['csrf'] ) || ! hash_equals( $csrf, (string) $_POST['csrf'] ) ) {
			$error = _( "The form expired. Please try again." );
		} else {
			try {
				$n     = sp_migrate_apply( $pdo, function ( $line ) use ( &$log ) { $log[] = $line; } );
				$log[] = $n ? sprintf( _( "%d update(s) applied." ), $n ) : _( "The database is up to date." );
			} catch ( Exception $e ) {
				$error = _( "An update failed; the steps before it were kept. Details are below. Back up the database, fix the cause and click Apply again." );
			}
		}
	}
	$status = sp_migrate_status( $pdo );
	unset( $_SESSION['sp_dbu_pending'] ); // refresh the notice in the header
} catch ( Exception $e ) {
	$status = array();
	$error  = _( "Could not connect to the database with the settings in config.php." );
	error_log( 'SubjectsPlus database updates: ' . $e->getMessage() );
}

$pending = 0;
$rows    = '';
foreach ( $status as $id => $s ) {
	if ( $s['applied_at'] === null ) {
		$pending++;
	}
	$rows .= '<tr><td>' . sp_dbu_h( $s['description'] ) . '<br><small style="color:#777">' . sp_dbu_h( $id ) . '</small></td><td>'
		. ( $s['applied_at'] === null ? '<strong style="color:#a33">' . _( "Pending" ) . '</strong>' : _( "Applied" ) . ' ' . sp_dbu_h( $s['applied_at'] ) )
		. '</td></tr>';
}

$body = '';
if ( $error !== '' ) {
	$body .= '<p class="sp-dbu-error" role="alert">' . sp_dbu_h( $error ) . '</p>';
}
if ( $log ) {
	$body .= '<pre class="sp-dbu-log">' . sp_dbu_h( implode( "\n", $log ) ) . '</pre>';
}
$body .= '<p>' . _( "New features in this version (Record Settings, Workflow, Off-campus Access, login protection) need these changes to the database. Each one runs once; it is safe to click Apply again if one stopped half-way." ) . '</p>';
$body .= '<p><strong>' . _( "Back up the database first" ) . '</strong> ' . _( "(phpMyAdmin → Export, or mysqldump)." ) . '</p>';
$body .= '<table class="pure-table pure-table-horizontal" style="width:100%;margin:10px 0"><thead><tr><th>' . _( "Update" ) . '</th><th>' . _( "Status" ) . '</th></tr></thead><tbody>' . $rows . '</tbody></table>';
if ( $pending ) {
	$body .= '<form method="post"><input type="hidden" name="csrf" value="' . sp_dbu_h( $csrf ) . '">'
		. '<button type="submit" name="apply" value="1" class="pure-button pure-button-primary">'
		. sprintf( _( "Apply %d pending update(s)" ), $pending ) . '</button></form>';
} elseif ( $status ) {
	$body .= '<p><strong>' . _( "The database is up to date." ) . '</strong></p>';
}
$body .= '<p style="color:#666;font-size:.9em">' . _( "Command-line alternative:" ) . ' <code>php tools/migrate.php</code></p>';

print '<div class="pure-g"><div class="pure-u-1 pure-u-lg-2-3">';
makePluslet( _( "Database Updates" ), $body, "no_overflow" );
print '</div></div>';
?>
<style>.sp-dbu-error{background:#fdecea;border-left:4px solid #c62828;padding:8px 12px}.sp-dbu-log{background:#f6f6f6;padding:8px;white-space:pre-wrap;font-size:.85em}</style>
<?php
include( "../includes/footer.php" );
