<?php
/**
 * apply the workflow date rules (trial ends, renewals, reminders) — run once a day.
 *
 *   Linux cron:        15 6 * * *  php /var/www/html/sp/tools/workflow-daily.php
 *   Windows (WAMP):    Task Scheduler → daily → C:\wamp64\bin\php\php8.x\php.exe C:\wamp64\www\sp\tools\workflow-daily.php
 *
 *   php tools/workflow-daily.php --date=2026-12-01   # test what would happen on another day
 */

if ( PHP_SAPI !== 'cli' ) {
	http_response_code( 403 );
	exit( "Run this script from the command line.\n" );
}

$root = dirname( __DIR__ );
chdir( $root . '/control/includes' ); // config.php and functions.php expect to be included from here
require $root . '/control/includes/config.php';
require $root . '/control/includes/functions.php';
require_once $root . '/control/includes/autoloader.php';

use SubjectsPlus\Control\RecordSettings;
use SubjectsPlus\Control\Workflow;

if ( ! RecordSettings::ready() ) {
	fwrite( STDERR, "Workflow tables not found. Apply the database updates first (Admin → Database Updates, or php tools/migrate.php).\n" );
	exit( 1 );
}

$date = null;
foreach ( $argv as $a ) {
	if ( preg_match( '/^--date=(\d{4}-\d{2}-\d{2})$/', $a, $m ) ) {
		$date = $m[1];
	}
}

$log = Workflow::runRules( $date );
echo '[' . date( 'Y-m-d H:i' ) . '] ' . ( $log ? count( $log ) . " action(s):\n  " . implode( "\n  ", $log ) : 'nothing due' ) . "\n";

// guide review reminders (weekly email to owners of public guides that are overdue)
$rlog = \SubjectsPlus\Control\Guide\GuideReviews::sendReminders( $date );
echo '[' . date( 'Y-m-d H:i' ) . '] guide reviews: ' . ( $rlog ? implode( "\n  ", $rlog ) : 'no reminders due' ) . "\n";

// 4.7.1: scheduled link check (a batch per day), audit-log retention, rate-limit clean-up, heartbeat for System Health
if ( \SubjectsPlus\Control\ScheduledLinkCheck::ready() ) {
	try {
		\SubjectsPlus\Control\ScheduledLinkCheck::collect();
		$ls = \SubjectsPlus\Control\ScheduledLinkCheck::run( (int) ( $link_check_batch ?? 300 ), (int) ( $link_check_interval_days ?? 7 ), (int) ( $link_check_timeout ?? 10 ) );
		echo '[' . date( 'Y-m-d H:i' ) . "] link check: {$ls['checked']} checked, {$ls['broken']} failed\n";
	} catch ( \Throwable $e ) {
		echo '[' . date( 'Y-m-d H:i' ) . '] link check failed: ' . $e->getMessage() . "\n";
	}
}
$pruned = \SubjectsPlus\Control\AuditLog::prune( (int) ( $sp_audit_retention_days ?? 730 ) );
if ( $pruned ) {
	echo '[' . date( 'Y-m-d H:i' ) . "] audit log: $pruned old entries removed\n";
}
if ( \SubjectsPlus\Control\RateLimiter::ready() ) {
	\SubjectsPlus\Control\Schema::conn()->exec( 'DELETE FROM rate_limit WHERE window_start < ' . ( time() - 86400 ) );
}
\SubjectsPlus\Control\SystemState::set( 'cron.daily', array( 'at' => date( 'c' ), 'php' => PHP_VERSION ) );
