<?php
/**
 * @file audit_log.php
 * @brief Admin → Audit Log: who changed what, and when (sign-ins, permissions, settings, records, guides, bulk edits, API keys).
 * @since 4.7.1
 */

use SubjectsPlus\Control\AuditLog;
use SubjectsPlus\Control\Schema;

$subsubcat  = "";
$subcat     = "admin";
$page_title = "Audit Log";

if ( isset( $_GET['export'] ) ) {
	$header = "noshow";
}
include( "../includes/header.php" );

$f = array();
foreach ( array( 'staff', 'action', 'object_type', 'object_id', 'q', 'from', 'to' ) as $k ) {
	$f[ $k ] = isset( $_GET[ $k ] ) && is_string( $_GET[ $k ] ) ? trim( mb_substr( $_GET[ $k ], 0, 120 ) ) : '';
}
$page    = max( 1, (int) ( $_GET['page'] ?? 1 ) );
$perPage = 50;

if ( isset( $_GET['export'] ) ) {
	list( $rows ) = AuditLog::search( $f, 50000, 0 );
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="audit-log-' . date( 'Y-m-d' ) . '.csv"' );
	header( 'Cache-Control: no-store' );
	$out = fopen( 'php://output', 'w' );
	fwrite( $out, "\xEF\xBB\xBF" );
	fputcsv( $out, array( 'Time', 'Staff ID', 'Staff', 'IP', 'Action', 'Object type', 'Object ID', 'Summary', 'Details' ) );
	foreach ( $rows as $r ) {
		// spreadsheet formula injection: prefix cells that start with = + - @
		$cells = array( $r['created_at'], $r['staff_id'], $r['staff_email'], $r['ip'], $r['action'], $r['object_type'], $r['object_id'], $r['summary'], $r['details'] );
		fputcsv( $out, array_map( function ( $c ) { $c = (string) $c; return preg_match( '/^[=+\-@\t\r]/', $c ) ? "'" . $c : $c; }, $cells ) );
	}
	fclose( $out );
	exit;
}

if ( ! AuditLog::ready() ) {
	makePluslet( _( "Audit Log" ), '<p>' . _( "Apply the database updates (Admin → Database Updates) to start recording the audit log." ) . '</p>', "no_overflow" );
	include( "../includes/footer.php" );
	exit;
}

list( $rows, $total ) = AuditLog::search( $f, $perPage, ( $page - 1 ) * $perPage );
$pages  = max( 1, (int) ceil( $total / $perPage ) );
$staff  = Schema::conn()->query( "SELECT staff_id, CONCAT(lname, ', ', fname, ' <', email, '>') FROM staff ORDER BY lname, fname" )->fetchAll( PDO::FETCH_KEY_PAIR );
$types  = Schema::conn()->query( "SELECT DISTINCT object_type FROM audit_log WHERE object_type <> '' ORDER BY object_type" )->fetchAll( PDO::FETCH_COLUMN );
$prefix = array( '' => _( "Any" ), 'auth.' => _( "Sign-in / sign-out" ), 'user.' => _( "Users & permissions" ), 'settings.' => _( "Settings" ),
                 'admin.' => _( "Admin forms" ), 'record' => _( "Records" ), 'guide' => _( "Guides" ), 'api.' => _( "API clients" ), 'system.' => _( "System / upgrade" ) );
$sel    = function ( $name, array $opts, $cur, $label ) {
	$h = '<label for="al-' . $name . '">' . sp_h( $label ) . '</label><select id="al-' . $name . '" name="' . $name . '" class="pure-input-1">';
	foreach ( $opts as $k => $v ) {
		$h .= '<option value="' . sp_h( $k ) . '"' . ( (string) $k === (string) $cur ? ' selected' : '' ) . '>' . sp_h( $v ) . '</option>';
	}

	return $h . '</select>';
};
$qs = function ( array $over ) use ( $f ) {
	return 'audit_log.php?' . http_build_query( array_filter( array_merge( $f, $over ), function ( $v ) { return $v !== '' && $v !== null; } ) );
};

$form = '<form method="get" action="audit_log.php" class="pure-form pure-form-stacked" role="search"><div class="pure-g">
	<div class="pure-u-1 pure-u-md-1-4">' . $sel( 'staff', array( '' => _( "Anyone" ) ) + $staff, $f['staff'], _( "Staff member" ) ) . '</div>
	<div class="pure-u-1 pure-u-md-1-6">' . $sel( 'action', $prefix, $f['action'], _( "Kind of change" ) ) . '</div>
	<div class="pure-u-1 pure-u-md-1-6">' . $sel( 'object_type', array( '' => _( "Any" ) ) + array_combine( $types, $types ), $f['object_type'], _( "Object" ) ) . '</div>
	<div class="pure-u-1 pure-u-md-1-6"><label for="al-q">' . _( "Text" ) . '</label><input type="search" id="al-q" name="q" value="' . sp_h( $f['q'] ) . '" class="pure-input-1"></div>
	<div class="pure-u-1-2 pure-u-md-1-8"><label for="al-from">' . _( "From" ) . '</label><input type="date" id="al-from" name="from" value="' . sp_h( $f['from'] ) . '" class="pure-input-1"></div>
	<div class="pure-u-1-2 pure-u-md-1-8"><label for="al-to">' . _( "To" ) . '</label><input type="date" id="al-to" name="to" value="' . sp_h( $f['to'] ) . '" class="pure-input-1"></div>
	</div><p><button type="submit" class="pure-button pure-button-primary">' . _( "Filter" ) . '</button> <a class="pure-button" href="audit_log.php">' . _( "Clear" ) . '</a>
	<a class="pure-button" href="' . sp_h( $qs( array( 'export' => 1, 'page' => null ) ) ) . '">' . _( "Download CSV" ) . '</a></p></form>';

$t = '<p>' . sprintf( _( "%d entries." ), $total ) . ' ' . sprintf( _( "Entries older than %d days are removed by the daily task (\$sp_audit_retention_days in config.php; 0 keeps them forever)." ), (int) ( $sp_audit_retention_days ?? 730 ) ) . '</p>
	<div style="overflow-x:auto"><table class="pure-table pure-table-horizontal audit-table"><thead><tr><th scope="col">' . _( "Time" ) . '</th><th scope="col">' . _( "Staff" ) . '</th><th scope="col">' . _( "Action" ) . '</th><th scope="col">' . _( "Summary" ) . '</th><th scope="col">' . _( "IP" ) . '</th></tr></thead><tbody>';
foreach ( $rows as $r ) {
	$details = '';
	if ( $r['details'] ) {
		$d       = json_decode( $r['details'], true );
		$details = '<details><summary>' . _( "details" ) . '</summary><pre>' . sp_h( json_encode( $d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) . '</pre></details>';
	}
	$obj = $r['object_type'] !== '' ? ' <small class="smallgrey">' . sp_h( $r['object_type'] . ( $r['object_id'] !== '' ? ' #' . $r['object_id'] : '' ) ) . '</small>' : '';
	$t  .= '<tr><td><time datetime="' . sp_h( $r['created_at'] ) . '">' . sp_h( $r['created_at'] ) . '</time></td><td>' . sp_h( $r['staff_email'] ) . '</td><td><code>' . sp_h( $r['action'] ) . '</code></td>
		<td>' . sp_h( $r['summary'] ) . $obj . $details . '</td><td>' . sp_h( $r['ip'] ) . '</td></tr>';
}
if ( ! $rows ) {
	$t .= '<tr><td colspan="5">' . _( "Nothing recorded for these filters." ) . '</td></tr>';
}
$t .= '</tbody></table></div>';
if ( $pages > 1 ) {
	$t .= '<nav aria-label="' . sp_h( _( "Pages" ) ) . '" style="margin:.8em 0">';
	for ( $i = max( 1, $page - 5 ); $i <= min( $pages, $page + 5 ); $i++ ) {
		$t .= $i === $page ? ' <strong aria-current="page">' . $i . '</strong>' : ' <a href="' . sp_h( $qs( array( 'page' => $i ) ) ) . '">' . $i . '</a>';
	}
	$t .= ' … ' . sprintf( _( "%d pages" ), $pages ) . '</nav>';
}

print '<div class="pure-g"><div class="pure-u-1">';
makePluslet( _( "Filter" ), $form, "no_overflow" );
makePluslet( _( "Audit Log" ), $t, "no_overflow" );
print '</div></div><style>.audit-table td{vertical-align:top}.audit-table pre{white-space:pre-wrap;max-width:60em;font-size:.85em}</style>';
include( "../includes/footer.php" );
