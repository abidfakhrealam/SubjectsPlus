<?php
namespace SubjectsPlus\API;

use SubjectsPlus\Control\Schema;

/**
 * Per-client REST API keys (Admin → API Clients). Only a SHA-256 hash of each key is stored; the key itself
 * is shown once when it is created. The single shared $api_key from config.php keeps working ("legacy key").
 */
class ApiClients {

	const SERVICES = array( 'database', 'guides', 'staff', 'faq', 'talkback', 'lti' );

	public static function ready() {
		return Schema::hasTable( 'api_client' );
	}

	public static function hash( $key ) {
		return hash( 'sha256', (string) $key );
	}

	/** Creates a client; returns [client_id, key]. */
	public static function create( $name, $rateLimit = 0, array $services = array(), $staffId = null ) {
		$key = 'sp_' . bin2hex( random_bytes( 24 ) );
		$services = array_values( array_intersect( $services, self::SERVICES ) );
		$st  = Schema::conn()->prepare( 'INSERT INTO api_client (name, key_prefix, key_hash, rate_limit, services, active, created_by, created_at) VALUES (?, ?, ?, ?, ?, 1, ?, NOW())' );
		$st->execute( array( mb_substr( trim( strip_tags( (string) $name ) ), 0, 150 ), substr( $key, 0, 10 ), self::hash( $key ), max( 0, (int) $rateLimit ), implode( ',', $services ), $staffId ? (int) $staffId : null ) );

		return array( (int) Schema::conn()->lastInsertId(), $key );
	}

	public static function all() {
		if ( ! self::ready() ) {
			return array();
		}
		$r = Schema::conn()->query( 'SELECT * FROM api_client ORDER BY active DESC, name' );

		return $r ? $r->fetchAll( \PDO::FETCH_ASSOC ) : array();
	}

	public static function find( $key ) {
		if ( ! self::ready() || ! is_string( $key ) || strlen( $key ) < 20 ) {
			return null;
		}
		$st = Schema::conn()->prepare( 'SELECT * FROM api_client WHERE key_hash = ? AND active = 1' );
		$st->execute( array( self::hash( $key ) ) );
		$c = $st->fetch( \PDO::FETCH_ASSOC );

		return $c ?: null;
	}

	public static function touch( $clientId ) {
		if ( $clientId > 0 && self::ready() ) {
			Schema::conn()->prepare( 'UPDATE api_client SET last_used_at = NOW() WHERE client_id = ?' )->execute( array( (int) $clientId ) );
		}
	}

	public static function logUsage( $clientId, $service, $status ) {
		if ( ! Schema::hasTable( 'api_usage' ) ) {
			return;
		}
		try {
			Schema::conn()->prepare( 'INSERT INTO api_usage (day, client_id, service, status, hits) VALUES (CURDATE(), ?, ?, ?, 1) ON DUPLICATE KEY UPDATE hits = hits + 1' )
			      ->execute( array( (int) $clientId, substr( preg_replace( '/[^a-z]/', '', strtolower( (string) $service ) ), 0, 30 ) ?: '-', (int) $status ) );
		} catch ( \Throwable $e ) {
			error_log( 'SubjectsPlus API usage not recorded: ' . $e->getMessage() );
		}
	}

	/** Requests per client and service in the last $days days. */
	public static function usage( $days = 30 ) {
		if ( ! Schema::hasTable( 'api_usage' ) ) {
			return array();
		}
		$st = Schema::conn()->prepare( 'SELECT u.client_id, MAX(COALESCE(c.name, \'\')) AS name, u.service, SUM(CASE WHEN u.status < 400 THEN u.hits ELSE 0 END) AS ok,
				SUM(CASE WHEN u.status = 429 THEN u.hits ELSE 0 END) AS limited, SUM(CASE WHEN u.status >= 400 AND u.status <> 429 THEN u.hits ELSE 0 END) AS errors
			FROM api_usage u LEFT JOIN api_client c ON c.client_id = u.client_id
			WHERE u.day >= CURDATE() - INTERVAL ? DAY GROUP BY u.client_id, u.service ORDER BY ok DESC' );
		$st->execute( array( max( 1, (int) $days ) ) );

		return $st->fetchAll( \PDO::FETCH_ASSOC );
	}
}
