<?php
namespace SubjectsPlus\Control;

/**
 * Scheduled link checker (4.7.1). Collects the links of public guides and A–Z records, checks a batch of them
 * on every run (tools/link-check.php or the daily task) and keeps the results in link_check / link_check_source.
 * Results appear in Guides → Guide Health → Broken links.
 *
 * A link is reported as broken only after it failed twice in a row (a site that is briefly down is not flagged).
 * Addresses on private or internal networks are not requested (same rules as every other server-side fetch).
 */
class ScheduledLinkCheck {

	public static function ready() {
		return Schema::hasTable( 'link_check' ) && Schema::hasTable( 'link_check_source' );
	}

	public static function hash( $url ) {
		return sha1( (string) $url );
	}

	/** Rebuild the list of links and where they are used. Returns the number of distinct links. */
	public static function collect() {
		$c     = Schema::conn();
		$links = array(); // hash => [url, [[type, id, detail], …]]
		$add   = function ( $url, $type, $id, $detail ) use ( &$links ) {
			$url = html_entity_decode( trim( (string) $url ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			if ( ! preg_match( '#^https?://[^\s<>"\']+$#i', $url ) || strlen( $url ) > 2000 ) {
				return;
			}
			$h = sha1( $url );
			if ( ! isset( $links[ $h ] ) ) {
				$links[ $h ] = array( $url, array() );
			}
			$links[ $h ][1][ $type . ':' . $id ] = array( $type, (int) $id, mb_substr( (string) $detail, 0, 255 ) );
		};

		// A–Z records (the address stored on the record; the off-campus prefix is added when the link is shown)
		$r = $c->query( "SELECT lt.title_id, l.location, l.helpguide FROM location l JOIN location_title lt ON lt.location_id = l.location_id" );
		foreach ( $r ? $r->fetchAll( \PDO::FETCH_ASSOC ) : array() as $row ) {
			$add( $row['location'], 'record', $row['title_id'], 'URL' );
			$add( $row['helpguide'], 'record', $row['title_id'], 'Help guide' );
		}

		// boxes on public guides
		$r = $c->query( "SELECT t.subject_id, p.title, p.body FROM pluslet p JOIN pluslet_section ps ON ps.pluslet_id = p.pluslet_id
			JOIN section s ON s.section_id = ps.section_id JOIN tab t ON t.tab_id = s.tab_id JOIN subject sub ON sub.subject_id = t.subject_id
			WHERE sub.active = 1 AND p.body LIKE '%http%'" );
		foreach ( $r ? $r->fetchAll( \PDO::FETCH_ASSOC ) : array() as $row ) {
			if ( preg_match_all( '#\bhref\s*=\s*["\'](https?://[^"\']+)["\']#i', (string) $row['body'], $m ) ) {
				foreach ( $m[1] as $u ) {
					$add( $u, 'guide', $row['subject_id'], strip_tags( (string) $row['title'] ) );
				}
			}
		}

		$c->beginTransaction();
		try {
			$c->exec( 'DELETE FROM link_check_source' );
			$ins = $c->prepare( 'INSERT IGNORE INTO link_check (url_hash, url, result) VALUES (?, ?, \'pending\')' );
			$src = $c->prepare( 'INSERT IGNORE INTO link_check_source (url_hash, source_type, source_id, detail) VALUES (?, ?, ?, ?)' );
			foreach ( $links as $h => $l ) {
				$ins->execute( array( $h, $l[0] ) );
				foreach ( $l[1] as $s ) {
					$src->execute( array( $h, $s[0], $s[1], $s[2] ) );
				}
			}
			// links no longer used anywhere
			$c->exec( 'DELETE lc FROM link_check lc LEFT JOIN link_check_source s ON s.url_hash = lc.url_hash WHERE s.url_hash IS NULL' );
			$c->commit();
		} catch ( \Throwable $e ) {
			$c->rollBack();
			throw $e;
		}

		return count( $links );
	}

	/**
	 * Request one address. Returns [result, status, message, final_url]:
	 * result ok | redirect | restricted (401/403/429: often a login or bot block, not a broken link) | broken | error | skipped
	 */
	public static function probe( $url, $timeout = 10 ) {
		if ( ! function_exists( 'curl_init' ) ) {
			return array( 'skipped', 0, 'PHP curl extension missing', null );
		}
		$start = $url;
		for ( $hop = 0; $hop < 6; $hop++ ) {
			$r = sp_resolve_public_url( $url );
			if ( ! $r ) {
				return array( 'skipped', 0, 'not a public address (private network, unknown host or not http/https)', null );
			}
			list( $host, $port, $ip ) = $r;
			$got = 0;
			$ch  = curl_init();
			curl_setopt_array( $ch, array(
				CURLOPT_URL            => $url,
				CURLOPT_FOLLOWLOCATION => false,
				CURLOPT_CONNECTTIMEOUT => min( 8, (int) $timeout ),
				CURLOPT_TIMEOUT        => (int) $timeout,
				CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; SubjectsPlus-LinkCheck/' . ( $GLOBALS['sp_version'] ?? '4' ) . ')',
				CURLOPT_HTTPHEADER     => array( 'Accept: text/html,application/xhtml+xml,*/*;q=0.8' ),
				CURLOPT_RESOLVE        => array( $host . ':' . $port . ':' . ( strpos( $ip, ':' ) !== false ? '[' . $ip . ']' : $ip ) ),
				CURLOPT_WRITEFUNCTION  => function ( $ch, $chunk ) use ( &$got ) {
					$got += strlen( $chunk );

					return $got > 65536 ? 0 : strlen( $chunk ); // the status is all we need
				},
			) );
			if ( defined( 'CURLOPT_PROTOCOLS_STR' ) ) {
				curl_setopt( $ch, CURLOPT_PROTOCOLS_STR, 'http,https' );
			}
			curl_exec( $ch );
			$code = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
			$loc  = (string) curl_getinfo( $ch, CURLINFO_REDIRECT_URL );
			$err  = curl_errno( $ch );
			$msg  = curl_error( $ch );
			curl_close( $ch );
			if ( $code >= 300 && $code < 400 && $loc !== '' ) {
				$url = $loc;
				continue;
			}
			if ( $code === 0 ) {
				// CURLE_WRITE_ERROR (23) = we stopped reading on purpose
				return $err === 23 ? array( 'ok', 200, '', $url !== $start ? $url : null ) : array( 'error', 0, mb_substr( $msg ?: 'no answer', 0, 255 ), null );
			}
			if ( $code < 300 ) {
				return array( $url !== $start ? 'redirect' : 'ok', $code, '', $url !== $start ? $url : null );
			}
			if ( in_array( $code, array( 401, 403, 405, 429, 999 ), true ) ) {
				return array( 'restricted', $code, 'the site refused the checker (login, IP-based access or bot protection)', null );
			}
			if ( $code === 404 || $code === 410 ) {
				return array( 'broken', $code, $code === 404 ? 'not found' : 'gone', null );
			}

			return array( 'error', $code, 'server error', null );
		}

		return array( 'error', 0, 'too many redirects', null );
	}

	/** Check up to $batch links that are due. Returns a summary array. */
	public static function run( $batch = 300, $intervalDays = 7, $timeout = 10, ?callable $log = null ) {
		$c   = Schema::conn();
		$sum = array( 'checked' => 0, 'ok' => 0, 'broken' => 0, 'other' => 0 );
		$st  = $c->prepare( 'SELECT url_hash, url, result, fail_count FROM link_check WHERE ignored = 0 AND (checked_at IS NULL OR checked_at < NOW() - INTERVAL ? DAY
			OR (result IN (\'broken\', \'error\') AND fail_count = 1)) ORDER BY checked_at IS NOT NULL, checked_at LIMIT ' . max( 1, (int) $batch ) );
		$st->execute( array( max( 1, (int) $intervalDays ) ) );
		$up    = $c->prepare( 'UPDATE link_check SET status = ?, result = ?, message = ?, final_url = ?, fail_count = ?,
			first_failed_at = IF(? = 1, COALESCE(first_failed_at, NOW()), NULL), checked_at = NOW() WHERE url_hash = ?' );
		$hosts = array();
		foreach ( $st->fetchAll( \PDO::FETCH_ASSOC ) as $row ) {
			// be polite: at most one request per second to the same host
			$h = strtolower( (string) parse_url( $row['url'], PHP_URL_HOST ) );
			if ( isset( $hosts[ $h ] ) && microtime( true ) - $hosts[ $h ] < 1 ) {
				usleep( (int) ( ( 1 - ( microtime( true ) - $hosts[ $h ] ) ) * 1000000 ) );
			}
			list( $result, $code, $msg, $final ) = self::probe( $row['url'], $timeout );
			$hosts[ $h ] = microtime( true );
			$failed      = in_array( $result, array( 'broken', 'error' ), true );
			$fails       = $failed ? (int) $row['fail_count'] + 1 : 0;
			$up->execute( array( $code, $result, $msg, $final, $fails, $failed ? 1 : 0, $row['url_hash'] ) );
			$sum['checked']++;
			$sum[ $result === 'ok' || $result === 'redirect' ? 'ok' : ( $failed ? 'broken' : 'other' ) ]++;
			if ( $log ) {
				$log( sprintf( '%-10s %3d %s', $result, $code, $row['url'] ) );
			}
		}
		SystemState::set( 'linkcheck.last_run', $sum + array( 'at' => date( 'c' ) ) );

		return $sum;
	}

	/**
	 * Problems to show staff: links that failed at least twice (broken / error), optionally only for guides one
	 * person edits. Returns rows with url, result, status, message, checked_at, source_type, source_id, detail, name.
	 */
	public static function problems( $type = null, ?array $guideIds = null, $includeRestricted = false ) {
		if ( ! self::ready() ) {
			return array();
		}
		$w = array( 'lc.ignored = 0', $includeRestricted ? "(lc.fail_count >= 2 OR lc.result = 'restricted')" : 'lc.fail_count >= 2' );
		$p = array();
		if ( $type ) {
			$w[] = 's.source_type = ?';
			$p[] = $type;
		}
		if ( $guideIds !== null ) {
			if ( ! $guideIds ) {
				return array();
			}
			$w[] = "s.source_type = 'guide' AND s.source_id IN (" . implode( ',', array_map( 'intval', $guideIds ) ) . ')';
		}
		$st = Schema::conn()->prepare( "SELECT lc.url_hash, lc.url, lc.result, lc.status, lc.message, lc.checked_at, lc.first_failed_at, lc.final_url,
				s.source_type, s.source_id, s.detail, COALESCE(sub.subject, t.title) AS name
			FROM link_check lc JOIN link_check_source s ON s.url_hash = lc.url_hash
			LEFT JOIN subject sub ON s.source_type = 'guide' AND sub.subject_id = s.source_id
			LEFT JOIN title t ON s.source_type = 'record' AND t.title_id = s.source_id
			WHERE " . implode( ' AND ', $w ) . ' ORDER BY s.source_type, name, lc.url' );
		$st->execute( $p );

		return $st->fetchAll( \PDO::FETCH_ASSOC );
	}

	public static function counts() {
		if ( ! self::ready() ) {
			return array();
		}
		$r = Schema::conn()->query( "SELECT result, COUNT(*) FROM link_check WHERE ignored = 0 GROUP BY result" );

		return $r ? $r->fetchAll( \PDO::FETCH_KEY_PAIR ) : array();
	}

	public static function ignore( $hash, $on = true ) {
		Schema::conn()->prepare( 'UPDATE link_check SET ignored = ? WHERE url_hash = ?' )->execute( array( $on ? 1 : 0, (string) $hash ) );
	}

	public static function recheckSoon( $hash ) {
		Schema::conn()->prepare( 'UPDATE link_check SET checked_at = NULL WHERE url_hash = ?' )->execute( array( (string) $hash ) );
	}
}
