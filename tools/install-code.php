<?php
/**
 * Create the one-time install code that the installer wizard asks for, so that only someone with
 * access to the server can install SubjectsPlus.
 *
 *   php tools/install-code.php            # create (or show) the code
 *   php tools/install-code.php --new      # replace it with a new code
 *   php tools/install-code.php --remove   # delete it
 *
 * Run it as the web-server user, or make sure the web server can read
 * control/includes/install-code.php. The code is deleted automatically after installation.
 * Without shell access: create control/includes/install-code.php yourself containing
 *   <?php return 'a-phrase-of-at-least-12-characters';
 */
if ( PHP_SAPI !== 'cli' ) {
	http_response_code( 404 );
	exit;
}
require_once dirname( __DIR__ ) . '/lib/SubjectsPlus/Control/Install/Environment.php';

use SubjectsPlus\Control\Install\Environment;

$args = array_slice( $argv, 1 );

if ( Environment::isInstalled() && ! in_array( '--remove', $args, true ) ) {
	fwrite( STDERR, "SubjectsPlus is already installed (control/includes/config.php exists); no install code is needed.\n" );
	exit( 1 );
}
if ( in_array( '--remove', $args, true ) ) {
	Environment::removeCode();
	echo "Install code removed.\n";
	exit( 0 );
}

$code = in_array( '--new', $args, true ) ? '' : Environment::expectedCode();
if ( $code === '' ) {
	$code = Environment::newCode();
	if ( ! Environment::writeCode( $code ) ) {
		fwrite( STDERR, "Could not write " . Environment::codePath() . " (permissions?).\n" );
		exit( 1 );
	}
	@chmod( Environment::codePath(), 0640 );
}

echo "\n  SubjectsPlus install code:  $code\n\n";
echo "  Open https://<your-site>/control/installer/ and enter this code.\n";
echo "  Stored in " . Environment::codePath() . " (removed after installation).\n";
echo "  If the web server runs as another user: chown www-data " . Environment::codePath() . "\n\n";
