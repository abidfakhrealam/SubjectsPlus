<?php
/**
 * SubjectsPlus database migrations — command line.
 *
 *     php tools/migrate.php            # apply pending migrations
 *     php tools/migrate.php --status   # list applied / pending migrations
 *
 * On WampServer (Windows):  C:\wamp64\bin\php\php8.4.x\php.exe tools\migrate.php
 * Admins can also apply them in the browser: Admin → Database Updates.
 * Back up the database before running.
 */

if ( PHP_SAPI !== 'cli' ) {
	http_response_code( 403 );
	exit( "Run this script from the command line.\n" );
}

$root = dirname( __DIR__ );
$configFile = $root . '/control/includes/config.php';
if ( ! is_readable( $configFile ) ) {
	fwrite( STDERR, "Cannot read $configFile. Install SubjectsPlus first.\n" );
	exit( 1 );
}

// config.php only defines variables; load it in this scope.
require $configFile;
require __DIR__ . '/migrations.php';

$pdo = sp_migrate_connect( $hname, $uname, $pword, $dbName_SPlus, isset( $db_port ) ? $db_port : '', isset( $db_cert_path ) ? $db_cert_path : '' );

if ( in_array( '--status', $argv, true ) ) {
	foreach ( sp_migrate_status( $pdo ) as $id => $s ) {
		printf( "%-45s %s  %s\n", $id, $s['applied_at'] !== null ? 'applied ' . $s['applied_at'] : 'PENDING', $s['description'] );
	}
	exit( 0 );
}

try {
	$count = sp_migrate_apply( $pdo, function ( $line ) { echo $line, "\n"; } );
} catch ( Exception $e ) {
	fwrite( STDERR, "Stopped. Fix the problem above and run this again; finished steps are kept.\n" );
	exit( 1 );
}
echo $count ? "$count migration(s) applied.\n" : "Database is up to date.\n";
