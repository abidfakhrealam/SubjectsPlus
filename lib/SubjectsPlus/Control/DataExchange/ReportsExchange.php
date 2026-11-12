<?php
namespace SubjectsPlus\Control\DataExchange;

use SubjectsPlus\Control\Querier;
use SubjectsPlus\Control\RecordSettings;

/**
 * Usage reports for accreditation / annual statistics returns and library committees, as an Excel workbook.
 *
 * Source: the `stats` table (guide page views, tab clicks, link clicks; and since 4.6.0, clicks on
 * database links anywhere on the public site). Clicks are matched to e-resource records by URL —
 * also through off-campus access links — so older guide link clicks count as well.
 */
class ReportsExchange {

	private static function conn() {
		return ( new Querier )->getConnection();
	}

	private static function txt( $s ) {
		return html_entity_decode( (string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	/** List of 'YYYY-MM' from $from to $to inclusive. */
	public static function months( $from, $to ) {
		$out = array();
		$d   = new \DateTime( $from . '-01' );
		$end = new \DateTime( $to . '-01' );
		while ( $d <= $end && count( $out ) < 120 ) {
			$out[] = $d->format( 'Y-m' );
			$d->modify( '+1 month' );
		}

		return $out;
	}

	/** First month (1–12) of the academic year: $sp_academic_year_start_month in config.php (default 7 = July). */
	public static function academicYearStartMonth() {
		global $sp_academic_year_start_month;
		$m = isset( $sp_academic_year_start_month ) ? (int) $sp_academic_year_start_month : 7;

		return ( $m >= 1 && $m <= 12 ) ? $m : 7;
	}

	/** Academic year containing today, e.g. ['2026-07', '2027-06'] when it starts in July. */
	public static function academicYear( $offset = 0 ) {
		$start = self::academicYearStartMonth();
		$y     = (int) date( 'Y' ) - ( (int) date( 'n' ) < $start ? 1 : 0 ) + $offset;
		$from  = new \DateTime( sprintf( '%04d-%02d-01', $y, $start ) );
		$to    = ( clone $from )->modify( '+11 months' );

		return array( $from->format( 'Y-m' ), $to->format( 'Y-m' ) );
	}

	/* ---------------------------------------------------- URL → record matching */

	private static $urlMap = null;

	/** Maps a clicked address (direct or through EZproxy / OpenAthens / RemoteXs …) to a record id. */
	public static function titleIdForUrl( $url ) {
		if ( self::$urlMap === null ) {
			self::$urlMap = array( 'exact' => array(), 'host' => array() );
			$st           = self::conn()->query( "SELECT lt.title_id, l.location FROM location l JOIN location_title lt ON lt.location_id = l.location_id WHERE l.location <> ''" );
			foreach ( $st ? $st->fetchAll( \PDO::FETCH_ASSOC ) : array() as $r ) {
				$u = RecordsExchange::normUrl( self::txt( $r['location'] ) );
				if ( $u === '' ) {
					continue;
				}
				self::$urlMap['exact'][ $u ] = (int) $r['title_id'];
				$host                         = strtok( $u, '/?#' );
				self::$urlMap['host'][ $host ][ (int) $r['title_id'] ] = true;
			}
		}
		$url = self::txt( trim( (string) $url ) );
		if ( $url === '' ) {
			return null;
		}
		// links in guide boxes go through SubjectsPlus' own record.php?id=N
		if ( preg_match( '#record\.php\?id=(\d+)#', $url, $m ) ) {
			return (int) $m[1];
		}
		$candidates = array( $url );
		// the real address inside an off-campus access link
		if ( preg_match( '#[?&](?:url|qurl|target|dest|destination|redirect|u)=([^&]+)#i', $url, $m ) ) {
			$candidates[] = rawurldecode( $m[1] );
		} elseif ( preg_match( '#/login\?url=(.+)$#i', $url, $m ) ) {
			$candidates[] = $m[1];
		}
		foreach ( array_reverse( $candidates ) as $cand ) {
			$u = RecordsExchange::normUrl( $cand );
			if ( isset( self::$urlMap['exact'][ $u ] ) ) {
				return self::$urlMap['exact'][ $u ];
			}
		}
		foreach ( array_reverse( $candidates ) as $cand ) {
			$host = strtok( RecordsExchange::normUrl( $cand ), '/?#' );
			if ( isset( self::$urlMap['host'][ $host ] ) && count( self::$urlMap['host'][ $host ] ) === 1 ) {
				return (int) key( self::$urlMap['host'][ $host ] );
			}
		}

		return null;
	}

	/** Fills stats.title_id for clicks recorded before records were matched (run before reports). */
	public static function matchPendingClicks() {
		$c = self::conn();
		if ( ! self::hasTitleColumn() ) {
			return;
		}
		$st = $c->query( "SELECT DISTINCT link_url FROM stats WHERE title_id IS NULL AND link_url IS NOT NULL AND link_url <> '' AND event_type IN ('link','database') LIMIT 20000" );
		$up = $c->prepare( "UPDATE stats SET title_id = ? WHERE title_id IS NULL AND link_url = ? AND event_type IN ('link','database')" );
		foreach ( $st ? $st->fetchAll( \PDO::FETCH_COLUMN ) : array() as $u ) {
			$id = self::titleIdForUrl( $u );
			$up->execute( array( $id ?: 0, $u ) ); // 0 = checked, not a database
		}
	}

	private static function hasTitleColumn() {
		$r = self::conn()->query( "SHOW COLUMNS FROM stats LIKE 'title_id'" );

		return $r && $r->fetch();
	}

	/* ---------------------------------------------------- the workbook */

	public static function workbook( $from, $to ) {
		$c      = self::conn();
		$months = self::months( $from, $to );
		$start  = strtotime( $months[0] . '-01 00:00:00' );
		$end    = strtotime( end( $months ) . '-01 00:00:00 +1 month' );
		self::matchPendingClicks();
		$hasId  = self::hasTitleColumn();
		$mexpr  = "DATE_FORMAT(FROM_UNIXTIME(date), '%Y-%m')";

		// Summary
		$sum = array();
		$st  = $c->prepare( "SELECT $mexpr AS m,
				SUM(event_type = 'view') AS views,
				SUM(event_type = 'tab_click') AS tabs,
				SUM(event_type = 'link') AS links,
				" . ( $hasId ? "SUM(event_type IN ('link','database') AND title_id > 0)" : "0" ) . " AS dbs,
				COUNT(DISTINCT remote_address) AS visitors
			FROM stats WHERE date >= ? AND date < ? GROUP BY m" );
		$st->execute( array( $start, $end ) );
		foreach ( $st->fetchAll( \PDO::FETCH_ASSOC ) as $r ) {
			$sum[ $r['m'] ] = $r;
		}
		$summary = array( array( 'Month', 'Guide page views', 'Guide tab clicks', 'Links clicked in guides', 'Database (e-resource) clicks', 'Different visitors (approx.)' ) );
		$tot     = array( 0, 0, 0, 0 );
		foreach ( $months as $m ) {
			$r         = $sum[ $m ] ?? array( 'views' => 0, 'tabs' => 0, 'links' => 0, 'dbs' => 0, 'visitors' => 0 );
			$summary[] = array( self::monthLabel( $m ), (int) $r['views'], (int) $r['tabs'], (int) $r['links'], (int) $r['dbs'], (int) $r['visitors'] );
			$tot[0]   += (int) $r['views'];
			$tot[1]   += (int) $r['tabs'];
			$tot[2]   += (int) $r['links'];
			$tot[3]   += (int) $r['dbs'];
		}
		$summary[] = array( 'Total', $tot[0], $tot[1], $tot[2], $tot[3], '' );
		$summary[] = array();
		$counts    = array(
			'E-resource records (public status)' => (int) $c->query( "SELECT COUNT(DISTINCT lt.title_id) FROM location l JOIN location_title lt ON lt.location_id = l.location_id WHERE l.record_status IN (" . RecordSettings::publicStatusSql() . ")" )->fetchColumn(),
			'Research guides (public)'           => (int) $c->query( "SELECT COUNT(*) FROM subject WHERE active = 1" )->fetchColumn(),
			'FAQs'                               => (int) $c->query( "SELECT COUNT(*) FROM faq" )->fetchColumn(),
			'Staff (active)'                     => (int) $c->query( "SELECT COUNT(*) FROM staff WHERE active = 1 AND (user_type_id = 1 OR user_type_id IS NULL)" )->fetchColumn(),
		);
		foreach ( $counts as $k => $v ) {
			$summary[] = array( $k, $v );
		}
		$summary[] = array();
		$summary[] = array( 'Period: ' . self::monthLabel( $months[0] ) . ' to ' . self::monthLabel( end( $months ) ) . '. Generated ' . date( 'Y-m-d H:i' ) . ' from SubjectsPlus.' );
		$summary[] = array( 'Database clicks are counted when someone clicks a database link on the A–Z list, a guide or the home page (recorded from SubjectsPlus 4.6.0; earlier versions only recorded clicks inside guides).' );

		// Databases × month
		$clicks = array();
		if ( $hasId ) {
			$st = $c->prepare( "SELECT title_id, $mexpr AS m, COUNT(*) AS n FROM stats WHERE date >= ? AND date < ? AND title_id > 0 AND event_type IN ('link','database') GROUP BY title_id, m" );
			$st->execute( array( $start, $end ) );
			foreach ( $st->fetchAll( \PDO::FETCH_ASSOC ) as $r ) {
				$clicks[ (int) $r['title_id'] ][ $r['m'] ] = (int) $r['n'];
			}
		}
		$formats = $restr = array();
		foreach ( RecordSettings::formats( true ) as $f ) {
			$formats[ (int) $f[0] ] = $f[1];
		}
		foreach ( RecordSettings::restrictions( true ) as $r ) {
			$restr[ (int) $r[0] ] = $r[1];
		}
		$public = RecordSettings::publicStatusNames();
		$db     = array( array_merge( array( 'Database / e-resource', 'Record ID', 'Access', 'Format', 'Status' ), array_map( array( __CLASS__, 'monthLabel' ), $months ), array( 'Total' ) ) );
		$recs   = $c->query( "SELECT t.title_id, t.title, MIN(l.access_restrictions) AS ar, MIN(l.format) AS fmt, MIN(l.record_status) AS st FROM title t
			JOIN location_title lt ON lt.title_id = t.title_id JOIN location l ON l.location_id = lt.location_id GROUP BY t.title_id, t.title ORDER BY t.title" )->fetchAll( \PDO::FETCH_ASSOC );
		$list   = array();
		foreach ( $recs as $r ) {
			$id = (int) $r['title_id'];
			if ( ! isset( $clicks[ $id ] ) && ! in_array( (string) $r['st'], $public, true ) ) {
				continue; // not public and never clicked: leave out
			}
			$row = array( self::txt( $r['title'] ), $id, $restr[ (int) $r['ar'] ] ?? '', $formats[ (int) $r['fmt'] ] ?? '', (string) $r['st'] );
			$t   = 0;
			foreach ( $months as $m ) {
				$n     = $clicks[ $id ][ $m ] ?? 0;
				$row[] = $n;
				$t    += $n;
			}
			$row[]  = $t;
			$list[] = $row;
		}
		usort( $list, function ( $a, $b ) { return end( $b ) <=> end( $a ) ?: strcasecmp( $a[0], $b[0] ); } );
		$db = array_merge( $db, $list );

		// Guides × month (page views)
		$views = array();
		$st    = $c->prepare( "SELECT subject_short_form AS s, $mexpr AS m, COUNT(*) AS n FROM stats WHERE date >= ? AND date < ? AND event_type = 'view' GROUP BY s, m" );
		$st->execute( array( $start, $end ) );
		foreach ( $st->fetchAll( \PDO::FETCH_ASSOC ) as $r ) {
			$views[ (string) $r['s'] ][ $r['m'] ] = (int) $r['n'];
		}
		$owners = array();
		foreach ( $c->query( "SELECT ss.subject_id, CONCAT(s.fname, ' ', s.lname) AS n FROM staff_subject ss JOIN staff s ON s.staff_id = ss.staff_id" )->fetchAll( \PDO::FETCH_ASSOC ) as $r ) {
			$owners[ (int) $r['subject_id'] ][] = trim( self::txt( $r['n'] ) );
		}
		$guides = array( array_merge( array( 'Guide', 'Short form', 'Owners' ), array_map( array( __CLASS__, 'monthLabel' ), $months ), array( 'Total' ) ) );
		$glist  = array();
		foreach ( $c->query( "SELECT subject_id, subject, shortform FROM subject ORDER BY subject" )->fetchAll( \PDO::FETCH_ASSOC ) as $g ) {
			$row = array( self::txt( $g['subject'] ), $g['shortform'], implode( ', ', $owners[ (int) $g['subject_id'] ] ?? array() ) );
			$t   = 0;
			foreach ( $months as $m ) {
				$n     = $views[ $g['shortform'] ][ $m ] ?? 0;
				$row[] = $n;
				$t    += $n;
			}
			$row[]   = $t;
			$glist[] = $row;
		}
		usort( $glist, function ( $a, $b ) { return end( $b ) <=> end( $a ) ?: strcasecmp( $a[0], $b[0] ); } );
		$guides = array_merge( $guides, $glist );

		// Tabs and top links in the period
		$tabs = array( array( 'Guide', 'Tab', 'Clicks' ) );
		$st   = $c->prepare( "SELECT subject_short_form, tab_name, COUNT(*) AS n FROM stats WHERE date >= ? AND date < ? AND event_type = 'tab_click' AND tab_name <> '' GROUP BY subject_short_form, tab_name ORDER BY n DESC LIMIT 2000" );
		$st->execute( array( $start, $end ) );
		foreach ( $st->fetchAll( \PDO::FETCH_NUM ) as $r ) {
			$tabs[] = array( (string) $r[0], self::txt( $r[1] ), (int) $r[2] );
		}
		$links = array( array( 'Link text', 'Address', 'Where (guide)', 'Box', 'Clicks' ) );
		$st    = $c->prepare( "SELECT MAX(link_title), link_url, subject_short_form, MAX(in_pluslet), COUNT(*) AS n FROM stats WHERE date >= ? AND date < ? AND event_type IN ('link','database') AND link_url <> '' GROUP BY link_url, subject_short_form ORDER BY n DESC LIMIT 2000" );
		$st->execute( array( $start, $end ) );
		foreach ( $st->fetchAll( \PDO::FETCH_NUM ) as $r ) {
			$links[] = array( trim( self::txt( $r[0] ) ), self::txt( $r[1] ), (string) $r[2] !== '' ? (string) $r[2] : '(A–Z list / home page)', self::txt( $r[3] ), (int) $r[4] );
		}

		return array(
			array( 'name' => 'Summary', 'rows' => $summary ),
			array( 'name' => 'Database clicks', 'rows' => $db ),
			array( 'name' => 'Guide views', 'rows' => $guides ),
			array( 'name' => 'Guide tabs', 'rows' => $tabs ),
			array( 'name' => 'Top links', 'rows' => $links ),
			array( 'name' => 'E-resources list', 'rows' => self::inventory() ),
		);
	}

	/** List of public e-resources (inventory for accreditation returns), with the custom fields. */
	private static function inventory() {
		$rows   = RecordsExchange::exportRows();
		$header = array_shift( $rows );
		$keep   = array();
		foreach ( $header as $i => $h ) {
			if ( ! in_array( $h, array( 'Location ID', 'Prefix', 'Internal notes', 'Last modified', 'Show in A-Z', 'Display note', 'Help guide', 'Call number', 'Tags' ), true ) ) {
				$keep[] = $i;
			}
		}
		$statusCol = array_search( 'Status', $header, true );
		$public    = RecordSettings::publicStatusNames();
		$out       = array( array_values( array_intersect_key( $header, array_flip( $keep ) ) ) );
		foreach ( $rows as $r ) {
			if ( $statusCol !== false && ! in_array( (string) $r[ $statusCol ], $public, true ) ) {
				continue;
			}
			$line = array();
			foreach ( $keep as $i ) {
				$line[] = $r[ $i ];
			}
			$out[]   = $line;
		}
		// descriptions are HTML in SubjectsPlus; plain text reads better in a report
		$d = array_search( 'Description', $out[0], true );
		if ( $d !== false ) {
			for ( $i = 1, $n = count( $out ); $i < $n; $i++ ) {
				$out[ $i ][ $d ] = trim( html_entity_decode( strip_tags( (string) $out[ $i ][ $d ] ), ENT_QUOTES, 'UTF-8' ) );
			}
		}

		return $out;
	}

	public static function monthLabel( $m ) {
		return date( 'M Y', strtotime( $m . '-01' ) );
	}
}
