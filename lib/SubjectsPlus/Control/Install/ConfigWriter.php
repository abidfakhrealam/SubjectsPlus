<?php
namespace SubjectsPlus\Control\Install;

/**
 * Builds control/includes/config.php from config-default.php.
 *
 * Every value is written with var_export(), so passwords containing " $ \ or quotes stay intact and
 * cannot inject PHP (the 4.x writer pasted values between double quotes, where "$" and "\" are special).
 */
class ConfigWriter {

	/**
	 * @param array $values variable name => value (string, int, bool or a simple array); only variables that
	 *                      already exist as single-line declarations in config-default.php are replaced
	 *
	 * @return string PHP source
	 */
	public static function build( array $values ) {
		$template = file_get_contents( Environment::root() . '/control/includes/config-default.php' );
		$lines    = preg_split( '/(\r\n|\n)/', $template );
		$done     = array();
		foreach ( $lines as $i => $line ) {
			if ( ! preg_match( '/^\$([A-Za-z_][A-Za-z0-9_]*)\s*=\s*.*;\s*(\/\/.*)?$/', $line, $m ) ) {
				continue;
			}
			$name = $m[1];
			if ( ! array_key_exists( $name, $values ) || isset( $done[ $name ] ) ) {
				continue;
			}
			// a declaration continued on the next lines (array( … ) over several lines) is left alone
			if ( substr_count( $line, '(' ) !== substr_count( $line, ')' ) ) {
				continue;
			}
			$lines[ $i ]   = '$' . $name . ' = ' . self::literal( $values[ $name ] ) . ';';
			$done[ $name ] = true;
		}
		$missing = array_diff( array_keys( $values ), array_keys( $done ) );
		$src     = implode( "\n", $lines );
		if ( $missing ) {
			// settings without a line in the template are added before the closing tag
			$extra = "\n// Added by the installer\n";
			foreach ( $missing as $name ) {
				$extra .= '$' . $name . ' = ' . self::literal( $values[ $name ] ) . ";\n";
			}
			$pos = strrpos( $src, '?>' );
			$src = $pos === false ? $src . $extra : substr( $src, 0, $pos ) . $extra . "\n" . substr( $src, $pos );
		}

		return "<?php\n// Written by the SubjectsPlus installer on " . gmdate( 'Y-m-d H:i' ) . " UTC.\n?>\n" . $src;
	}

	private static function literal( $v ) {
		if ( is_bool( $v ) ) {
			return $v ? 'TRUE' : 'FALSE';
		}
		if ( is_array( $v ) ) {
			return str_replace( array( "\n", '  ' ), array( ' ', '' ), var_export( $v, true ) );
		}

		return var_export( is_int( $v ) ? $v : (string) $v, true );
	}

	/** Write the file; returns true or an error message. */
	public static function write( $src ) {
		$path = Environment::configPath();
		if ( ! is_writable( dirname( $path ) ) && ! ( is_file( $path ) && is_writable( $path ) ) ) {
			return 'control/includes is not writable';
		}
		if ( @file_put_contents( $path, $src, LOCK_EX ) === false ) {
			return 'could not write ' . $path;
		}
		@chmod( $path, 0640 );

		return true;
	}

	/** Does a config.php uploaded by hand match what the installer generated (same database settings)? */
	public static function uploadedMatches( array $values ) {
		$path = Environment::configPath();
		if ( ! is_file( $path ) ) {
			return false;
		}
		// read the values without running the file in this request's scope
		$check = static function ( $file ) {
			include $file;

			return array( $hname ?? null, $dbName_SPlus ?? null, $uname ?? null, $salt ?? null );
		};
		ob_start();
		$got = $check( $path );
		ob_end_clean();

		return $got === array( $values['hname'], $values['dbName_SPlus'], $values['uname'], $values['salt'] );
	}
}
