<?php
/**
 * Build / verify the front-end bundles.
 *
 * SubjectsPlus 4.x bundles its CSS and JavaScript at request time with Assetic
 * (control/includes/css.php and control/includes/js_vendor.php), caching the result in
 * assets/cache/. This script runs both bundles from the command line so a release is only
 * published when every referenced file exists and the bundles compile, and reports their size.
 *
 *   php build/build-assets.php            # exit code 0 = OK
 */
if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}
$root  = dirname( __DIR__ );
$tmp   = sys_get_temp_dir() . '/sp-assets-' . getmypid();
$fails = 0;

foreach ( array( 'css.php' => 20000, 'js_vendor.php' => 100000 ) as $bundle => $minBytes ) {
	$cmd = escapeshellarg( PHP_BINARY ) . ' -d display_errors=stderr -d error_reporting=' . ( E_ALL & ~E_DEPRECATED ) . ' ' . escapeshellarg( "$root/control/includes/$bundle" ) . ' 2>&1';
	$out = shell_exec( 'cd ' . escapeshellarg( "$root/control/includes" ) . ' && ' . $cmd );
	$len = strlen( (string) $out );
	$bad = preg_match( '/^PHP (Fatal error|Warning|Parse error)/m', (string) $out );
	printf( "%-14s %8d bytes  %s\n", $bundle, $len, ( $len >= $minBytes && ! $bad ) ? 'OK' : 'FAILED' );
	if ( $len < $minBytes || $bad ) {
		$fails ++;
		fwrite( STDERR, substr( (string) $out, 0, 2000 ) . "\n" );
	}
}

// every JavaScript file the pages load directly must exist
$refs = array();
foreach ( array( 'control/includes/functions.php', 'control/setup-config.php', 'control/guides/guide.php', 'subjects/includes/header.php' ) as $f ) {
	preg_match_all( '#(?:\$AssetPath|\$AssetURL)[^"\']*["\']\s*\.\s*["\']([A-Za-z0-9_./-]+\.(?:js|css))#', file_get_contents( "$root/$f" ), $m1 );
	preg_match_all( '#\$Asset(?:Path|URL)\s*\??>?\s*(?:;\s*\?>)?([A-Za-z0-9_./-]+\.(?:js|css))#', file_get_contents( "$root/$f" ), $m2 );
	foreach ( array_merge( $m1[1], $m2[1] ) as $r ) {
		$refs[ ltrim( $r, '/' ) ] = $f;
	}
}
foreach ( $refs as $r => $from ) {
	if ( ! is_file( "$root/assets/$r" ) ) {
		echo "missing asset: assets/$r (referenced in $from)\n";
		$fails ++;
	}
}
printf( "%d asset references checked\n", count( $refs ) );

// clear what the CLI run cached, so the release ships an empty cache
foreach ( glob( "$root/assets/cache/*" ) as $f ) {
	if ( is_file( $f ) && ! in_array( basename( $f ), array( '.cache', '.gitkeep', '.htaccess' ), true ) ) {
		@unlink( $f );
	}
}
exit( $fails ? 1 : 0 );
