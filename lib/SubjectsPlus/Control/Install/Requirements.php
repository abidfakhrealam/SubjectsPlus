<?php
namespace SubjectsPlus\Control\Install;

/**
 * Pre-install checks. Each check: array( group, label, status ok|warn|fail|info, detail, fix ).
 * "fail" blocks installation; "warn" is shown but does not block.
 */
class Requirements {

	const PHP_MIN = '8.1.0';

	/** @return array list of checks */
	public static function run( $baseUrl = '' ) {
		$c = array();

		// ---- PHP
		$ok  = version_compare( PHP_VERSION, self::PHP_MIN, '>=' );
		$c[] = array( 'PHP', 'PHP version', $ok ? 'ok' : 'fail', PHP_VERSION, $ok ? '' : 'Install PHP 8.1, 8.2, 8.3 or 8.4.' );

		$required = array( 'pdo_mysql' => 'database access', 'mbstring' => 'multi-byte text', 'openssl' => 'encryption', 'json' => 'data exchange',
		                   'curl' => 'outbound requests (feeds, book covers, sign-in)', 'gd' => 'image resizing', 'dom' => 'HTML cleaning', 'simplexml' => 'feeds and imports',
		                   'fileinfo' => 'file type checks', 'session' => 'sign-in' );
		foreach ( $required as $ext => $why ) {
			$has  = extension_loaded( $ext );
			$crit = in_array( $ext, array( 'pdo_mysql', 'mbstring', 'openssl', 'json', 'dom', 'session' ), true );
			$c[]  = array( 'PHP', "Extension $ext", $has ? 'ok' : ( $crit ? 'fail' : 'warn' ), $why,
				$has ? '' : "Enable php-$ext (Debian/Ubuntu: apt install php" . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . "-" . ( $ext === 'pdo_mysql' ? 'mysql' : $ext ) . ", WampServer: PHP → PHP extensions)." );
		}
		foreach ( array( 'intl' => 'better sorting and dates', 'zip' => 'Excel import and export' ) as $ext => $why ) {
			$has = extension_loaded( $ext );
			$c[] = array( 'PHP', "Extension $ext (optional)", $has ? 'ok' : 'info', $why, $has ? '' : "Optional: enable php-$ext." );
		}

		$upload = self::bytes( ini_get( 'upload_max_filesize' ) );
		$c[]    = array( 'PHP', 'upload_max_filesize', $upload >= 8 * 1048576 ? 'ok' : 'warn', (string) ini_get( 'upload_max_filesize' ),
			$upload >= 8 * 1048576 ? '' : 'Set upload_max_filesize = 20M and post_max_size = 24M in php.ini.' );
		$mem = self::bytes( ini_get( 'memory_limit' ) );
		$c[] = array( 'PHP', 'memory_limit', ( $mem < 0 || $mem >= 128 * 1048576 ) ? 'ok' : 'warn', (string) ini_get( 'memory_limit' ),
			( $mem < 0 || $mem >= 128 * 1048576 ) ? '' : 'Set memory_limit = 256M in php.ini.' );
		$de  = filter_var( ini_get( 'display_errors' ), FILTER_VALIDATE_BOOLEAN );
		$c[] = array( 'PHP', 'display_errors', $de ? 'warn' : 'ok', $de ? 'On' : 'Off',
			$de ? 'Set display_errors = Off on a live server (errors reveal server paths).' : '' );

		// ---- folders
		$root = Environment::root();
		foreach ( array( 'assets/cache' => 'CSS/JS bundles', 'assets/users' => 'staff photos and uploads', 'assets/records' => 'files attached to records',
		                 'assets/images/guide_thumbs' => 'guide thumbnails' ) as $dir => $why ) {
			$w   = is_dir( "$root/$dir" ) && is_writable( "$root/$dir" );
			$c[] = array( 'Folders', "$dir writable", $w ? 'ok' : 'fail', $why,
				$w ? '' : "chown -R www-data:www-data $dir   (or give the web-server user write access)" );
		}
		$inc = is_writable( "$root/control/includes" );
		$c[] = array( 'Folders', 'control/includes writable', $inc ? 'warn' : 'ok',
			$inc ? 'The installer will save config.php here. Make the folder read-only afterwards.' : 'Read-only (recommended). You will download config.php and upload it yourself.',
			$inc ? 'After installing: chown -R root:root control/includes' : '' );

		// ---- web server
		$https = Environment::isHttps();
		$c[]   = array( 'Web server', 'HTTPS', $https ? 'ok' : 'warn', $https ? 'yes' : 'This page was opened over plain HTTP.',
			$https ? '' : 'Use HTTPS on a live site (single sign-on requires it); you can still install now.' );
		if ( $baseUrl !== '' ) {
			$prot = self::probe( $baseUrl . 'control/includes/config-default.php' );
			$c[]  = array( 'Web server', 'Configuration files are protected',
				$prot === null ? 'warn' : ( $prot ? 'ok' : 'fail' ),
				$prot === null ? 'Could not test (the server cannot reach its own address).' : ( $prot ? 'control/includes/ answers 403/404' : 'control/includes/config-default.php can be downloaded' ),
				$prot ? '' : 'Apache: allow the shipped .htaccess files (AllowOverride All, a2enmod rewrite headers). Nginx: use docs/server/nginx-subjectsplus.conf.' );
			$lib = self::probe( $baseUrl . 'lib/SubjectsPlus/Control/Querier.php' );
			if ( $lib !== null ) {
				$c[] = array( 'Web server', 'Code folders are protected', $lib ? 'ok' : 'fail', $lib ? 'lib/ answers 403/404' : 'lib/ can be browsed',
					$lib ? '' : 'Same fix as above.' );
			}
		}

		return $c;
	}

	public static function hasFailures( array $checks ) {
		foreach ( $checks as $ch ) {
			if ( $ch[2] === 'fail' ) {
				return true;
			}
		}

		return false;
	}

	/** true = protected (403/404), false = served (200), null = could not test. */
	private static function probe( $url ) {
		if ( ! function_exists( 'curl_init' ) ) {
			return null;
		}
		$ch = curl_init( $url );
		curl_setopt_array( $ch, array( CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 4, CURLOPT_CONNECTTIMEOUT => 3,
		                               CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0 ) );
		// only ever this server's own address (the URL is built from the detected / typed site address)
		curl_exec( $ch );
		$code = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
		curl_close( $ch );
		if ( $code === 0 ) {
			return null;
		}

		return $code === 403 || $code === 404 || $code === 401;
	}

	private static function bytes( $v ) {
		$v = trim( (string) $v );
		if ( $v === '' ) {
			return 0;
		}
		if ( $v === '-1' ) {
			return - 1;
		}
		$n = (float) $v;
		switch ( strtolower( substr( $v, - 1 ) ) ) {
			case 'g':
				$n *= 1024;
			case 'm':
				$n *= 1024;
			case 'k':
				$n *= 1024;
		}

		return (int) $n;
	}
}
