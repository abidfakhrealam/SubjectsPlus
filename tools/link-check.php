<?php
/**
 * Scheduled link checker: collects the links of public guides and A–Z records and checks those that are due.
 * Results: Guides → Guide Health → Broken links. The daily task (tools/workflow-daily.php) runs it too.
 *
 *   php tools/link-check.php                 # check the next batch ($link_check_batch, default 300)
 *   php tools/link-check.php --batch=1000 -v # bigger batch, print every result
 *   php tools/link-check.php --collect-only  # just refresh the list of links
 */

if ( PHP_SAPI !== 'cli' ) {
	http_response_code( 403 );
	exit( "Run this script from the command line.\n" );
}

$root = dirname( __DIR__ );
chdir( $root . '/control/includes' );
require $root . '/control/includes/config.php';
require $root . '/control/includes/functions.php';
require_once $root . '/control/includes/autoloader.php';

use SubjectsPlus\Control\ScheduledLinkCheck;

if ( ! ScheduledLinkCheck::ready() ) {
	fwrite( STDERR, "Link checker tables not found. Apply the database updates first (Admin → Database Updates, or php tools/migrate.php).\n" );
	exit( 1 );
}
$batch   = (int) ( $link_check_batch ?? 300 );
$verbose = false;
$collect = false;
foreach ( array_slice( $argv, 1 ) as $a ) {
	if ( preg_match( '/^--batch=(\d+)$/', $a, $m ) ) {
		$batch = (int) $m[1];
	} elseif ( $a === '-v' || $a === '--verbose' ) {
		$verbose = true;
	} elseif ( $a === '--collect-only' ) {
		$collect = true;
	}
}
$n = ScheduledLinkCheck::collect();
echo '[' . date( 'Y-m-d H:i' ) . "] $n links in public guides and records\n";
if ( $collect ) {
	exit( 0 );
}
$s = ScheduledLinkCheck::run( $batch, (int) ( $link_check_interval_days ?? 7 ), (int) ( $link_check_timeout ?? 10 ),
	$verbose ? function ( $l ) { echo '  ' . $l . "\n"; } : null );
echo '[' . date( 'Y-m-d H:i' ) . "] checked {$s['checked']}: {$s['ok']} ok, {$s['broken']} failed, {$s['other']} restricted / skipped\n";
