<?php
namespace SubjectsPlus\Control;

/**
 * Admin audit log: who changed what and when (table audit_log).
 *
 *   AuditLog::add('user.permissions', 'staff', 12, 'Permissions changed for jo@library.edu', ['from' => …, 'to' => …]);
 *
 * Writing never fails the page: if the table is missing (database update not applied) or the insert
 * fails, the entry is only written to the PHP error log.
 */
class AuditLog {

	/** Values that must never be stored, whatever the caller passes in $details. */
	const SECRET_KEYS = array( 'password', 'pass', 'pword', 'csrf', 'token', 'key', 'api_key', 'secret', 'salt' );

	public static function ready() {
		return Schema::hasTable( 'audit_log' );
	}

	public static function add( $action, $objectType = '', $objectId = '', $summary = '', array $details = array() ) {
		$details = self::scrub( $details );
		$staffId = isset( $_SESSION['staff_id'] ) ? (int) $_SESSION['staff_id'] : null;
		$email   = isset( $_SESSION['email'] ) ? (string) $_SESSION['email'] : ( PHP_SAPI === 'cli' ? 'cli' : '' );
		$ip      = PHP_SAPI === 'cli' ? 'cli' : (string) ( $_SERVER['REMOTE_ADDR'] ?? '' );
		$row     = array( substr( (string) $action, 0, 60 ), substr( (string) $objectType, 0, 40 ), substr( (string) $objectId, 0, 64 ),
		                  mb_substr( (string) $summary, 0, 500 ) );
		if ( ! self::ready() ) {
			error_log( 'SubjectsPlus audit: ' . implode( ' | ', $row ) . ' by ' . $email );

			return false;
		}
		try {
			$st = Schema::conn()->prepare( 'INSERT INTO audit_log (created_at, staff_id, staff_email, ip, action, object_type, object_id, summary, details)
				VALUES (NOW(), ?, ?, ?, ?, ?, ?, ?, ?)' );

			return $st && $st->execute( array( $staffId ?: null, substr( $email, 0, 255 ), substr( $ip, 0, 45 ), $row[0], $row[1], $row[2], $row[3],
			                                   $details ? json_encode( $details, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR ) : null ) );
		} catch ( \Throwable $e ) {
			error_log( 'SubjectsPlus audit (not saved): ' . implode( ' | ', $row ) . ' — ' . $e->getMessage() );

			return false;
		}
	}

	private static function scrub( array $a ) {
		foreach ( $a as $k => $v ) {
			if ( is_string( $k ) && in_array( strtolower( $k ), self::SECRET_KEYS, true ) ) {
				$a[ $k ] = '***';
			} elseif ( is_array( $v ) ) {
				$a[ $k ] = self::scrub( $v );
			} elseif ( is_string( $v ) && strlen( $v ) > 2000 ) {
				$a[ $k ] = substr( $v, 0, 2000 ) . '…';
			}
		}

		return $a;
	}

	/**
	 * Search the log. $f: staff (id), action (prefix), object_type, object_id, q (text), from, to (Y-m-d).
	 * Returns [rows, total].
	 */
	public static function search( array $f, $limit = 100, $offset = 0 ) {
		if ( ! self::ready() ) {
			return array( array(), 0 );
		}
		$w = array();
		$p = array();
		if ( ! empty( $f['staff'] ) ) {
			$w[] = 'staff_id = ?';
			$p[] = (int) $f['staff'];
		}
		if ( ! empty( $f['action'] ) ) {
			$w[] = 'action LIKE ?';
			$p[] = addcslashes( (string) $f['action'], '%_\\' ) . '%';
		}
		if ( ! empty( $f['object_type'] ) ) {
			$w[] = 'object_type = ?';
			$p[] = (string) $f['object_type'];
		}
		if ( isset( $f['object_id'] ) && $f['object_id'] !== '' ) {
			$w[] = 'object_id = ?';
			$p[] = (string) $f['object_id'];
		}
		if ( ! empty( $f['q'] ) ) {
			$w[] = '(summary LIKE ? OR staff_email LIKE ?)';
			$q   = '%' . addcslashes( (string) $f['q'], '%_\\' ) . '%';
			$p[] = $q;
			$p[] = $q;
		}
		foreach ( array( 'from' => '>=', 'to' => '<' ) as $k => $op ) {
			if ( ! empty( $f[ $k ] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $f[ $k ] ) ) {
				$w[] = "created_at $op ?";
				$p[] = $k === 'to' ? date( 'Y-m-d', strtotime( $f[ $k ] . ' +1 day' ) ) : $f[ $k ];
			}
		}
		$where = $w ? 'WHERE ' . implode( ' AND ', $w ) : '';
		$c     = Schema::conn();
		$st    = $c->prepare( "SELECT COUNT(*) FROM audit_log $where" );
		$st->execute( $p );
		$total = (int) $st->fetchColumn();
		$st    = $c->prepare( "SELECT * FROM audit_log $where ORDER BY audit_id DESC LIMIT " . max( 1, (int) $limit ) . ' OFFSET ' . max( 0, (int) $offset ) );
		$st->execute( $p );

		return array( $st->fetchAll( \PDO::FETCH_ASSOC ), $total );
	}

	/** Delete entries older than $days (0 = keep forever). Returns the number removed. */
	public static function prune( $days ) {
		$days = (int) $days;
		if ( $days <= 0 || ! self::ready() ) {
			return 0;
		}

		return (int) Schema::conn()->exec( 'DELETE FROM audit_log WHERE created_at < NOW() - INTERVAL ' . $days . ' DAY' );
	}
}
