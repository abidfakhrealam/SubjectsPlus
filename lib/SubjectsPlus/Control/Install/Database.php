<?php
namespace SubjectsPlus\Control\Install;

use PDO;

/**
 * Database steps of the installer: test a connection, read the server version and SQL mode,
 * and (optionally) create the SubjectsPlus database and its own database user with an
 * administrator account that is used once and never stored.
 */
class Database {

	public static function validName( $name ) {
		return is_string( $name ) && preg_match( '/^[A-Za-z0-9_]{1,64}$/', $name );
	}

	public static function validUser( $user ) {
		return is_string( $user ) && preg_match( '/^[A-Za-z0-9_.-]{1,32}$/', $user );
	}

	/** @throws \PDOException */
	public static function connect( $host, $port, $user, $pass, $dbname = '' ) {
		$dsn = 'mysql:host=' . $host . ';port=' . ( (int) $port ?: 3306 ) . ';charset=utf8mb4' . ( $dbname !== '' ? ';dbname=' . $dbname : '' );

		return new PDO( $dsn, (string) $user, (string) $pass, array(
			PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_TIMEOUT            => 5,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		) );
	}

	/** Friendly description of the server, plus warnings. */
	public static function serverInfo( PDO $pdo ) {
		$version = (string) $pdo->query( 'SELECT VERSION()' )->fetchColumn();
		$mode    = (string) $pdo->query( 'SELECT @@SESSION.sql_mode' )->fetchColumn();
		$maria   = stripos( $version, 'mariadb' ) !== false;
		preg_match( '/^(\d+)\.(\d+)/', $version, $m );
		$num  = isset( $m[1] ) ? (int) $m[1] * 100 + (int) $m[2] : 0;
		$warn = array();
		if ( $maria && $num < 1004 ) {
			$warn[] = 'MariaDB ' . $m[1] . '.' . $m[2] . ' is older than 10.4; use 10.11 or later.';
		}
		if ( ! $maria && $num < 800 ) {
			$warn[] = 'MySQL older than 8.0 is not supported.';
		}

		return array( 'product' => $maria ? 'MariaDB' : 'MySQL', 'version' => $version, 'sql_mode' => $mode, 'warnings' => $warn );
	}

	/** Number of SubjectsPlus tables already in the database (0 = empty / new). */
	public static function existingTables( PDO $pdo ) {
		$st = $pdo->query( "SHOW TABLES LIKE 'staff'" );

		return $st ? count( $st->fetchAll() ) : 0;
	}

	/** Hosts a new database user must be allowed to connect from, given the host SubjectsPlus connects to. */
	public static function userHostsFor( $dbHost ) {
		$h = strtolower( trim( (string) $dbHost ) );
		if ( $h === 'localhost' || $h === '127.0.0.1' || $h === '::1' ) {
			return array( 'localhost', '127.0.0.1', '::1' );
		}

		return array( '%' );
	}

	/**
	 * Create the database (utf8mb4) and a user with rights on it only.
	 * $admin = array(user, pass) of an account allowed to CREATE DATABASE / CREATE USER / GRANT.
	 *
	 * @return array log lines
	 * @throws \RuntimeException on failure (message is safe to show)
	 */
	public static function createDatabaseAndUser( $host, $port, array $admin, $dbname, $user, $pass ) {
		if ( ! self::validName( $dbname ) || ! self::validUser( $user ) ) {
			throw new \RuntimeException( 'Database and user names may only contain letters, digits and _ (user names also . and -).' );
		}
		if ( strlen( (string) $pass ) < 12 ) {
			throw new \RuntimeException( 'Choose a database password of at least 12 characters (or use the generated one).' );
		}
		try {
			$pdo = self::connect( $host, $port, $admin[0], $admin[1] );
		} catch ( \PDOException $e ) {
			throw new \RuntimeException( 'Could not sign in to the database server with the administrator account: ' . self::shortError( $e ) );
		}
		$log = array();
		try {
			$pdo->exec( 'CREATE DATABASE IF NOT EXISTS `' . $dbname . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci' );
			$log[] = "Database `$dbname` is ready (utf8mb4).";
			foreach ( self::userHostsFor( $host ) as $uh ) {
				$account = $pdo->quote( $user ) . '@' . $pdo->quote( $uh );
				$exists  = $pdo->prepare( 'SELECT COUNT(*) FROM mysql.user WHERE User = ? AND Host = ?' );
				$known   = false;
				try {
					$exists->execute( array( $user, $uh ) );
					$known = (int) $exists->fetchColumn() > 0;
				} catch ( \PDOException $e ) {
					// no SELECT on mysql.user: fall through to CREATE USER IF NOT EXISTS
				}
				if ( ! $known ) {
					$pdo->exec( 'CREATE USER IF NOT EXISTS ' . $account . ' IDENTIFIED BY ' . $pdo->quote( (string) $pass ) );
					$log[] = "Database user $user@$uh created.";
				} else {
					$log[] = "Database user $user@$uh already exists (password not changed).";
				}
				$pdo->exec( 'GRANT ALL PRIVILEGES ON `' . $dbname . '`.* TO ' . $account );
			}
			$pdo->exec( 'FLUSH PRIVILEGES' );
			$log[] = "User $user has all privileges on `$dbname` only.";
		} catch ( \PDOException $e ) {
			throw new \RuntimeException( 'The database server refused: ' . self::shortError( $e ) );
		}

		// prove that the new account works before going on
		try {
			self::connect( $host, $port, $user, $pass, $dbname );
		} catch ( \PDOException $e ) {
			throw new \RuntimeException( "The account $user exists but cannot sign in with this password (it may have existed before with another password): " . self::shortError( $e ) );
		}

		return $log;
	}

	public static function randomPassword( $len = 24 ) {
		$chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789-_';
		$s     = '';
		for ( $i = 0; $i < $len; $i ++ ) {
			$s .= $chars[ random_int( 0, strlen( $chars ) - 1 ) ];
		}

		return $s;
	}

	/** Error text without the DSN or password. */
	public static function shortError( \Throwable $e ) {
		$msg = $e->getMessage();
		$msg = preg_replace( '/^SQLSTATE\[[^\]]+\]\s*(\[\d+\])?\s*/', '', $msg );

		return mb_substr( $msg, 0, 300 );
	}
}
