<?php
namespace SubjectsPlus\Control\Upgrade;

/**
 * Database backup as a gzip-compressed SQL file, streamed to the browser (nothing is stored on the server).
 * Pure PHP (no mysqldump needed), so it works on shared hosting and Windows. Restore with:
 *   gunzip < subjectsplus-db-….sql.gz | mysql -u USER -p DATABASE
 */
class Backup {

	public static function filename() {
		return 'subjectsplus-db-' . date( 'Y-m-d-His' ) . '.sql.gz';
	}

	/** Stream the dump. Call before any other output. */
	public static function stream( \PDO $pdo, $dbName, array $skipData = array() ) {
		@set_time_limit( 0 );
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		header( 'Content-Type: application/gzip' );
		header( 'Content-Disposition: attachment; filename="' . self::filename() . '"' );
		header( 'Cache-Control: no-store' );
		header( 'X-Content-Type-Options: nosniff' );
		$z   = deflate_init( ZLIB_ENCODING_GZIP, array( 'level' => 6 ) );
		$out = function ( $s, $last = false ) use ( $z ) {
			echo deflate_add( $z, $s, $last ? ZLIB_FINISH : ZLIB_NO_FLUSH );
		};
		self::dump( $pdo, $dbName, $out, $skipData );
		$out( '', true );
		flush();
	}

	/**
	 * Write the SQL through $out($string). Tables in $skipData are saved without their rows (e.g. stats).
	 * Use a connection with PDO::MYSQL_ATTR_USE_BUFFERED_QUERY = false for big tables. Returns the number of tables.
	 */
	public static function dump( \PDO $pdo, $dbName, callable $out, array $skipData = array() ) {
		$pdo->setAttribute( \PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION );
		$out( "-- SubjectsPlus " . ( $GLOBALS['sp_version'] ?? '' ) . " database backup\n-- Database: " . str_replace( "\n", ' ', (string) $dbName ) . "\n-- Created: " . date( 'c' )
		      . "\n-- Server: " . $pdo->query( 'SELECT VERSION()' )->fetchAll( \PDO::FETCH_COLUMN )[0] . "\n\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\nSET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\nSET time_zone = '+00:00';\n\n" );
		$pdo->exec( "SET time_zone = '+00:00'" );
		$tables = $pdo->query( "SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'" )->fetchAll( \PDO::FETCH_COLUMN );
		foreach ( $tables as $t ) {
			$q      = '`' . str_replace( '`', '``', $t ) . '`';
			$create = $pdo->query( "SHOW CREATE TABLE $q" )->fetchAll( \PDO::FETCH_NUM )[0]; // fetchAll: works on unbuffered connections
			$out( "--\n-- Table $t\n--\nDROP TABLE IF EXISTS $q;\n" . $create[1] . ";\n\n" );
			if ( in_array( $t, $skipData, true ) ) {
				$out( "-- (rows not included)\n\n" );
				continue;
			}
			$cols = null;
			$rows = array();
			$size = 0;
			$st   = $pdo->query( "SELECT * FROM $q" );
			while ( ( $r = $st->fetch( \PDO::FETCH_ASSOC ) ) !== false ) {
				if ( $cols === null ) {
					$cols = '(' . implode( ', ', array_map( function ( $c ) { return '`' . str_replace( '`', '``', $c ) . '`'; }, array_keys( $r ) ) ) . ')';
				}
				$vals = array();
				foreach ( $r as $v ) {
					$vals[] = $v === null ? 'NULL' : $pdo->quote( (string) $v );
				}
				$row    = '(' . implode( ',', $vals ) . ')';
				$rows[] = $row;
				$size  += strlen( $row );
				if ( count( $rows ) >= 200 || $size > 1048576 ) {
					$out( "INSERT INTO $q $cols VALUES\n" . implode( ",\n", $rows ) . ";\n" );
					$rows = array();
					$size = 0;
				}
			}
			$st->closeCursor();
			if ( $rows ) {
				$out( "INSERT INTO $q $cols VALUES\n" . implode( ",\n", $rows ) . ";\n" );
			}
			$out( "\n" );
		}
		$out( "SET FOREIGN_KEY_CHECKS = 1;\n-- end of backup\n" );

		return count( $tables );
	}
}
