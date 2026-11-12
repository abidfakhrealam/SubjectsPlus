<?php
namespace SubjectsPlus\Control\Install;

/**
 * Facts about this server and installation that the installer wizard needs:
 * paths, the install code, the detected site address and whether SubjectsPlus is already installed.
 */
class Environment {

	/** SubjectsPlus root folder (no trailing slash). */
	public static function root() {
		return dirname( __DIR__, 4 );
	}

	public static function configPath() {
		return self::root() . '/control/includes/config.php';
	}

	public static function lockPath() {
		return self::root() . '/control/includes/.installed';
	}

	/** Installed = a real config.php exists (the installer then refuses to run), or the lock file. */
	public static function isInstalled() {
		$c = self::configPath();

		return is_file( self::lockPath() ) || ( is_file( $c ) && filesize( $c ) > 10 );
	}

	/** The wizard after installation: a plain 404 that runs nothing else, then exit. */
	public static function sendDisabled() {
		http_response_code( 404 );
		header( 'Cache-Control: no-store' );
		header( 'X-Robots-Tag: noindex' );
		echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
		     . '<title>Not found</title><body style="font:16px/1.5 system-ui,sans-serif;max-width:40em;margin:3em auto;padding:0 1em">'
		     . '<h1>Not found</h1><p>SubjectsPlus is already installed, so the installer is switched off.</p>'
		     . '<p><a href="../../subjects/">Public site</a> · <a href="../login.php">Staff sign-in</a></p></body></html>';
		exit;
	}

	public static function writeLock( array $info ) {
		$info['installed_at'] = gmdate( 'c' );

		return @file_put_contents( self::lockPath(), json_encode( $info, JSON_PRETTY_PRINT ) . "\n", LOCK_EX ) !== false;
	}

	/* ------------------------------------------------------------- install code */

	/**
	 * File that holds the one-time install code (created by `php tools/install-code.php`).
	 * It is a PHP file that only returns the code, so even a web server that ignores the shipped
	 * protection rules shows nothing if someone requests it.
	 */
	public static function codePath() {
		return self::root() . '/control/includes/install-code.php';
	}

	public static function writeCode( $code ) {
		$php = "<?php\n// SubjectsPlus one-time install code. Deleted automatically after installation.\nreturn " . var_export( (string) $code, true ) . ";\n";

		return @file_put_contents( self::codePath(), $php, LOCK_EX ) !== false;
	}

	/** The expected code: environment variable SP_INSTALL_CODE (containers / CI) or the code file. */
	public static function expectedCode() {
		$env = getenv( 'SP_INSTALL_CODE' );
		if ( is_string( $env ) && strlen( trim( $env ) ) >= 12 ) {
			return trim( $env );
		}
		if ( is_file( self::codePath() ) ) {
			$c = include self::codePath();
			$c = is_string( $c ) ? trim( $c ) : '';

			return strlen( $c ) >= 12 ? $c : '';
		}

		return '';
	}

	public static function codeMatches( $given ) {
		$expected = self::expectedCode();

		return $expected !== '' && is_string( $given ) && hash_equals( $expected, trim( $given ) );
	}

	/** Random, readable code: 4 groups of 4 (no 0/O/1/l). */
	public static function newCode() {
		$chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
		$out   = array();
		for ( $g = 0; $g < 4; $g ++ ) {
			$s = '';
			for ( $i = 0; $i < 4; $i ++ ) {
				$s .= $chars[ random_int( 0, strlen( $chars ) - 1 ) ];
			}
			$out[] = $s;
		}

		return implode( '-', $out );
	}

	public static function removeCode() {
		if ( is_file( self::codePath() ) ) {
			@unlink( self::codePath() );
		}
	}

	/* ------------------------------------------------------------- site address */

	/** Is the request behind a proxy we can trust for X-Forwarded-* (the proxy itself is on a private/loopback address)? */
	public static function fromTrustedProxy() {
		$ip = (string) ( $_SERVER['REMOTE_ADDR'] ?? '' );

		return $ip !== '' && ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
	}

	/** Did the browser use HTTPS (directly, or to a TLS-terminating proxy in front of this server)? */
	public static function isHttps() {
		if ( ! empty( $_SERVER['HTTPS'] ) && strtolower( (string) $_SERVER['HTTPS'] ) !== 'off' ) {
			return true;
		}
		if ( self::fromTrustedProxy() ) {
			$proto = strtolower( trim( explode( ',', (string) ( $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '' ) )[0] ) );
			if ( $proto === 'https' || strtolower( (string) ( $_SERVER['HTTP_X_FORWARDED_SSL'] ?? '' ) ) === 'on' ) {
				return true;
			}
		}

		return ( $_SERVER['SERVER_PORT'] ?? '' ) == 443;
	}

	/** The host (and port) the visitor used. */
	public static function host() {
		$host = '';
		if ( self::fromTrustedProxy() && ! empty( $_SERVER['HTTP_X_FORWARDED_HOST'] ) ) {
			$host = trim( explode( ',', (string) $_SERVER['HTTP_X_FORWARDED_HOST'] )[0] );
		}
		if ( $host === '' ) {
			$host = (string) ( $_SERVER['HTTP_HOST'] ?? ( $_SERVER['SERVER_NAME'] ?? 'localhost' ) );
		}

		// host name / IPv4 / [IPv6], optional port: nothing else
		return preg_match( '/^(\[[0-9a-f:.]+\]|[a-z0-9.-]+)(:\d{1,5})?$/i', $host ) ? strtolower( $host ) : 'localhost';
	}

	/** e.g. https://library.edu/sp/ — the folder that contains control/ and subjects/. */
	public static function detectBaseUrl() {
		$script = (string) ( $_SERVER['SCRIPT_NAME'] ?? '/control/installer/index.php' );
		$pos    = strpos( $script, '/control/' );
		$path   = $pos === false ? '/' : substr( $script, 0, $pos + 1 );

		return ( self::isHttps() ? 'https' : 'http' ) . '://' . self::host() . $path;
	}

	/** Normalise and validate a site address typed by the administrator; '' when invalid. */
	public static function cleanBaseUrl( $url ) {
		$url = trim( (string) $url );
		$p   = parse_url( $url );
		if ( ! $p || empty( $p['scheme'] ) || empty( $p['host'] ) || ! in_array( strtolower( $p['scheme'] ), array( 'http', 'https' ), true )
		     || isset( $p['query'] ) || isset( $p['fragment'] ) || isset( $p['user'] ) ) {
			return '';
		}
		$path = isset( $p['path'] ) ? $p['path'] : '/';
		if ( ! preg_match( '#^/[A-Za-z0-9._~/-]*$#', $path ) ) {
			return '';
		}

		return strtolower( $p['scheme'] ) . '://' . strtolower( $p['host'] ) . ( isset( $p['port'] ) ? ':' . (int) $p['port'] : '' ) . rtrim( $path, '/' ) . '/';
	}
}
