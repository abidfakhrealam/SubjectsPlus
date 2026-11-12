<?php
/**
 * @file upgrade.php
 * @brief Admin → Upgrade: after copying a new SubjectsPlus version over the old files, this page
 *        1. shows what changed and what is pending, 2. checks the server, 3. downloads a database backup,
 *        4. applies the database updates and records the new version.
 *        (Admin → Database Updates still works; this is the guided version.)
 * @since 4.7.1
 */

use SubjectsPlus\Control\AuditLog;
use SubjectsPlus\Control\Install\Requirements;
use SubjectsPlus\Control\SystemState;
use SubjectsPlus\Control\Upgrade\Backup;

$subsubcat  = "";
$subcat     = "admin";
$page_title = "Upgrade";

$sp_is_backup = $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['backup'] );
if ( $sp_is_backup ) {
	$header = "noshow"; // the backup is a download
}
include( "../includes/header.php" );
require_once __DIR__ . '/../../tools/migrations.php';

global $hname, $uname, $pword, $dbName_SPlus, $db_port, $db_cert_path;

if ( empty( $_SESSION['sp_up_csrf'] ) ) {
	$_SESSION['sp_up_csrf'] = bin2hex( random_bytes( 32 ) );
}
$csrf  = $_SESSION['sp_up_csrf'];
$okTok = isset( $_POST['csrf'] ) && is_string( $_POST['csrf'] ) && hash_equals( $csrf, $_POST['csrf'] );

$connect = function ( $unbuffered = false ) use ( $hname, $uname, $pword, $dbName_SPlus, $db_port, $db_cert_path ) {
	$pdo = sp_migrate_connect( $hname, $uname, $pword, $dbName_SPlus, isset( $db_port ) ? $db_port : '', isset( $db_cert_path ) ? $db_cert_path : '' );
	if ( $unbuffered ) {
		$pdo->setAttribute( PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false );
	}

	return $pdo;
};

// ------------------------------------------------------------------ 3. backup (download)
if ( $sp_is_backup ) {
	if ( ! $okTok ) {
		http_response_code( 400 );
		exit( 'The form expired. Go back and reload the page.' );
	}
	$_SESSION['sp_up_backup'] = time();
	SystemState::set( 'upgrade.last_backup', array( 'at' => date( 'c' ), 'by' => $_SESSION['email'] ?? '' ) );
	AuditLog::add( 'system.backup', 'database', (string) $dbName_SPlus, 'Database backup downloaded' );
	session_write_close();
	Backup::stream( $connect( true ), $dbName_SPlus, ! empty( $_POST['skip_stats'] ) ? array( 'stats', 'login_attempt', 'rate_limit' ) : array() );
	exit;
}

$version   = sp_version();
$recorded  = SystemState::get( 'version.installed' );
$recorded  = is_array( $recorded ) ? (string) ( $recorded['version'] ?? '' ) : '';
if ( $recorded === '' && is_file( dirname( __DIR__ ) . '/includes/.installed' ) ) {
	$lock     = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/includes/.installed' ), true );
	$recorded = is_array( $lock ) ? (string) ( $lock['version'] ?? '' ) : '';
}
$log   = array();
$error = '';

// ------------------------------------------------------------------ 4. apply
if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['apply'] ) ) {
	if ( ! $okTok ) {
		$error = _( "The form expired. Please try again." );
	} elseif ( empty( $_SESSION['sp_up_backup'] ) && empty( $_POST['have_backup'] ) ) {
		$error = _( "Download a backup first, or confirm that you have a recent backup of the database." );
	} else {
		@set_time_limit( 600 );
		try {
			$pdo   = $connect();
			$n     = sp_migrate_apply( $pdo, function ( $line ) use ( &$log ) { $log[] = $line; } );
			$log[] = $n ? sprintf( _( "%d database update(s) applied." ), $n ) : _( "The database was already up to date." );
			\SubjectsPlus\Control\Schema::reset(); // tables created just now (sp_state, audit_log …) exist from here on
			// compiled bundles and the HTML cleaner's cache are rebuilt by the new version on the next request
			$cleared = 0;
			foreach ( glob( dirname( __DIR__, 2 ) . '/assets/cache/htmlpurifier/*/*' ) ?: array() as $f ) {
				$cleared += is_file( $f ) && @unlink( $f ) ? 1 : 0;
			}
			if ( $cleared ) {
				$log[] = sprintf( _( "%d cached file(s) cleared." ), $cleared );
			}
			SystemState::set( 'version.installed', array( 'version' => $version, 'from' => $recorded, 'at' => date( 'c' ), 'by' => $_SESSION['email'] ?? '' ) );
			AuditLog::add( 'system.upgrade', 'version', $version, sprintf( 'Upgraded %s → %s (%d database update(s))', $recorded !== '' ? $recorded : '?', $version, $n ), array( 'log' => $log ) );
			$recorded = $version;
			unset( $_SESSION['sp_dbu_pending'], $_SESSION['sp_up_backup'] );
		} catch ( \Throwable $e ) {
			$error = _( "An update failed; the steps before it were kept. Restore the backup if the site does not work, or fix the cause (details below) and click Apply again." );
			$log[] = $e->getMessage();
		}
	}
}

// ------------------------------------------------------------------ page
$status = array();
try {
	$status = sp_migrate_status( $connect() );
} catch ( \Throwable $e ) {
	$error = $error ?: _( "Could not read the database: " ) . $e->getMessage();
}
$pending = array_filter( $status, function ( $s ) { return $s['applied_at'] === null; } );
$checks  = Requirements::run( (string) ( $BaseURL ?? '' ) );
$fails   = array_filter( $checks, function ( $c ) { return $c[2] === 'fail' && $c[1] !== 'control/includes writable'; } );
$backup  = SystemState::get( 'upgrade.last_backup' );

// "what's new" from CHANGELOG.md (shipped in the release)
$notes = '';
$cl    = dirname( __DIR__, 2 ) . '/CHANGELOG.md';
if ( is_file( $cl ) && preg_match( '/^## \[' . preg_quote( $version, '/' ) . '\][^\n]*\n(.*?)(?=^## \[)/ms', (string) file_get_contents( $cl ), $m ) ) {
	$lines = array_slice( preg_split( '/\R/', trim( $m[1] ) ), 0, 60 );
	$notes = '<details><summary>' . sprintf( _( "What is new in %s" ), sp_h( $version ) ) . '</summary><div class="up-notes">';
	foreach ( $lines as $l ) {
		$l = sp_h( $l );
		$l = preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', $l );
		$l = preg_replace( '/`([^`]+)`/', '<code>$1</code>', $l );
		$notes .= preg_match( '/^###\s+(.*)$/', $l, $h ) ? '<h4>' . $h[1] . '</h4>' : ( preg_match( '/^\s*-\s+(.*)$/', $l, $li ) ? '<p class="up-li">• ' . $li[1] . '</p>' : ( trim( $l ) !== '' ? '<p>' . $l . '</p>' : '' ) );
	}
	$notes .= '</div></details>';
}

$step = function ( $n, $title, $state, $body ) {
	return '<section class="up-step up-' . $state . '" aria-labelledby="up-s' . $n . '"><h3 id="up-s' . $n . '"><span class="up-n">' . $n . '</span> ' . sp_h( $title ) . '</h3>' . $body . '</section>';
};

$h = '';
if ( $error !== '' ) {
	$h .= '<div class="feedback" role="alert" style="background:#fdecea">' . sp_h( $error ) . '</div>';
}
if ( $log ) {
	$h .= '<div class="feedback" role="status"><strong>' . _( "Result" ) . '</strong><pre class="up-log">' . sp_h( implode( "\n", $log ) ) . '</pre></div>';
}

$h .= $step( 1, _( "Versions" ), $pending || ( $recorded !== '' && $recorded !== $version ) ? 'todo' : 'done',
	'<table class="pure-table up-table"><tr><th scope="row">' . _( "Files on the server" ) . '</th><td>SubjectsPlus ' . sp_h( $version ) . '</td></tr>'
	. '<tr><th scope="row">' . _( "Last completed upgrade / installation" ) . '</th><td>' . ( $recorded !== '' ? sp_h( $recorded ) : _( "not recorded (sites installed before 4.7.1)" ) ) . '</td></tr>'
	. '<tr><th scope="row">' . _( "Database updates waiting" ) . '</th><td>' . count( $pending ) . '</td></tr></table>'
	. ( $pending ? '<ul class="up-list">' . implode( '', array_map( function ( $id, $s ) { return '<li><code>' . sp_h( $id ) . '</code> ' . sp_h( $s['description'] ) . '</li>'; }, array_keys( $pending ), $pending ) ) . '</ul>' : '' )
	. $notes
	. '<p class="up-help">' . _( "To upgrade: back up the files and database, copy the new version over the old files (keep control/includes/config.php and the assets/users, assets/records, assets/images/guide_thumbs folders), then come back to this page." ) . ' ' . _( "Details: INSTALL.md, section 7, in the release package." ) . '</p>' );

$h .= $step( 2, _( "Check the server" ), $fails ? 'todo' : 'done',
	( $fails ? '<ul class="up-list">' . implode( '', array_map( function ( $c ) { return '<li><strong>' . sp_h( $c[1] ) . '</strong>: ' . sp_h( $c[3] ) . ( $c[4] !== '' ? ' — ' . sp_h( $c[4] ) : '' ) . '</li>'; }, $fails ) ) . '</ul>'
		: '<p>' . _( "PHP version, extensions, folder permissions and web-server protection are fine." ) . '</p>' )
	. '<p><a href="health.php">' . _( "Full System Health report" ) . '</a></p>' );

$h .= $step( 3, _( "Back up the database" ), ! empty( $_SESSION['sp_up_backup'] ) ? 'done' : 'todo',
	'<p>' . _( "Download a compressed SQL copy of the whole database (nothing is stored on the server). Restore it with: gunzip < file.sql.gz | mysql -u USER -p DATABASE" ) . '</p>'
	. ( $backup ? '<p class="up-help">' . sprintf( _( "Last backup from this page: %s by %s." ), sp_h( substr( (string) $backup['at'], 0, 16 ) ), sp_h( (string) $backup['by'] ) ) . '</p>' : '' )
	. '<form method="post" action="upgrade.php" class="pure-form"><input type="hidden" name="csrf" value="' . sp_h( $csrf ) . '">'
	. '<label><input type="checkbox" name="skip_stats" value="1"> ' . _( "Leave out the usage statistics rows (smaller file)" ) . '</label><br>'
	. '<button type="submit" name="backup" value="1" class="pure-button">' . _( "Download database backup" ) . '</button></form>' );

$h .= $step( 4, _( "Apply the database updates" ), $pending ? 'todo' : 'done',
	'<form method="post" action="upgrade.php" class="pure-form"><input type="hidden" name="csrf" value="' . sp_h( $csrf ) . '">'
	. ( empty( $_SESSION['sp_up_backup'] ) ? '<label><input type="checkbox" name="have_backup" value="1"> ' . _( "I have a recent backup of the database" ) . '</label><br>' : '' )
	. '<button type="submit" name="apply" value="1" class="pure-button pure-button-primary"' . ( $fails ? ' data-confirm="' . sp_h( _( "Some server checks failed. Apply the updates anyway?" ) ) . '"' : '' ) . '>'
	. ( $pending ? sprintf( _( "Apply %d update(s) and finish" ), count( $pending ) ) : _( "Finish the upgrade (record the new version)" ) ) . '</button></form>' );

print '<div class="pure-g"><div class="pure-u-1">';
makePluslet( sprintf( _( "Upgrade to SubjectsPlus %s" ), sp_h( $version ) ), $h, "no_overflow" );
print '</div></div>';
?>
<style>
.up-step{border:1px solid #d6dbe4;border-left:6px solid #aab4c4;border-radius:6px;padding:.6em 1em;margin:0 0 1em}
.up-step.up-done{border-left-color:#2e7d32}.up-step.up-todo{border-left-color:#e07b00}
.up-step h3{margin:.2em 0 .5em}.up-n{display:inline-block;width:1.6em;height:1.6em;line-height:1.6em;text-align:center;border-radius:50%;background:#1d3557;color:#fff;font-size:.85em}
.up-done .up-n{background:#2e7d32}.up-table th{text-align:left;font-weight:600}.up-help{color:#555;font-size:.92em}
.up-log{white-space:pre-wrap;max-height:22em;overflow:auto;font-size:.85em}.up-notes{max-height:28em;overflow:auto;border:1px solid #eee;padding:.5em 1em}.up-li{margin:.2em 0}
</style>
<script src="<?php echo sp_h( $AssetPath . 'js/admin/confirm.js?v=' . rawurlencode( sp_version() ) ); ?>" defer></script>
<?php
include( "../includes/footer.php" );
