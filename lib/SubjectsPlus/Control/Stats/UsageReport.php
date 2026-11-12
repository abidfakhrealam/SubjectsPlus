<?php
namespace SubjectsPlus\Control\Stats;

use SubjectsPlus\Control\Schema;
use SubjectsPlus\Control\RecordVendor;

/**
 * Admin → Usage Statistics: guide views, tab / link / database clicks for a date range (table stats, filled by
 * subjects/track.php). Every query aggregates all non-grouped columns (ONLY_FULL_GROUP_BY on MySQL 8 / MariaDB).
 */
class UsageReport {

	private $from;
	private $to;

	/** @param string $from Y-m-d (inclusive) @param string $to Y-m-d (inclusive) */
	public function __construct( $from, $to ) {
		$this->from = strtotime( $from . ' 00:00:00' );
		$this->to   = strtotime( $to . ' 23:59:59' );
	}

	private function q( $sql, array $p = array() ) {
		$st = Schema::conn()->prepare( $sql );
		if ( ! $st || ! $st->execute( array_merge( array( $this->from, $this->to ), $p ) ) ) {
			return array();
		}

		return $st->fetchAll( \PDO::FETCH_ASSOC );
	}

	public function totals() {
		$out = array( 'view' => 0, 'tab_click' => 0, 'link' => 0, 'database' => 0 );
		foreach ( $this->q( "SELECT event_type, COUNT(*) AS n FROM stats WHERE date BETWEEN ? AND ? GROUP BY event_type" ) as $r ) {
			$out[ (string) $r['event_type'] ] = (int) $r['n'];
		}
		$v                = $this->q( "SELECT COUNT(DISTINCT subject_short_form) AS g FROM stats WHERE date BETWEEN ? AND ? AND event_type = 'view' AND subject_short_form <> ''" );
		$out['guides']    = (int) ( $v[0]['g'] ?? 0 );

		return $out;
	}

	/** day => [views, clicks] for every day in the range */
	public function daily() {
		$days = array();
		for ( $t = $this->from; $t <= $this->to; $t += 86400 ) {
			$days[ date( 'Y-m-d', $t ) ] = array( 0, 0 );
		}
		foreach ( $this->q( "SELECT DATE(FROM_UNIXTIME(date)) AS d, SUM(event_type = 'view') AS v, SUM(event_type <> 'view') AS c
			FROM stats WHERE date BETWEEN ? AND ? GROUP BY DATE(FROM_UNIXTIME(date))" ) as $r ) {
			if ( isset( $days[ $r['d'] ] ) ) {
				$days[ $r['d'] ] = array( (int) $r['v'], (int) $r['c'] );
			}
		}

		return $days;
	}

	public function topGuides( $limit = 25 ) {
		return $this->q( "SELECT s.subject_short_form AS shortform, MAX(COALESCE(sub.subject, s.page_title)) AS guide, MAX(sub.subject_id) AS subject_id,
				SUM(s.event_type = 'view') AS views, SUM(s.event_type = 'tab_click') AS tabs, SUM(s.event_type IN ('link','database')) AS clicks
			FROM stats s LEFT JOIN subject sub ON sub.shortform = s.subject_short_form
			WHERE s.date BETWEEN ? AND ? AND s.subject_short_form <> '' AND s.subject_short_form IS NOT NULL
			GROUP BY s.subject_short_form ORDER BY views DESC, clicks DESC LIMIT " . (int) $limit );
	}

	public function topDatabases( $limit = 25 ) {
		$vendor = RecordVendor::ready() ? 'MAX(t.vendor)' : "''";

		return $this->q( "SELECT s.title_id, MAX(t.title) AS title, $vendor AS vendor, COUNT(*) AS clicks, COUNT(DISTINCT s.subject_short_form) AS guides
			FROM stats s JOIN title t ON t.title_id = s.title_id
			WHERE s.date BETWEEN ? AND ? AND s.event_type IN ('link','database') AND s.title_id > 0
			GROUP BY s.title_id ORDER BY clicks DESC LIMIT " . (int) $limit );
	}

	public function byVendor() {
		if ( ! RecordVendor::ready() ) {
			return array();
		}

		return $this->q( "SELECT COALESCE(NULLIF(t.vendor, ''), '') AS vendor, COUNT(*) AS clicks, COUNT(DISTINCT s.title_id) AS records
			FROM stats s JOIN title t ON t.title_id = s.title_id
			WHERE s.date BETWEEN ? AND ? AND s.event_type IN ('link','database') AND s.title_id > 0
			GROUP BY COALESCE(NULLIF(t.vendor, ''), '') ORDER BY clicks DESC" );
	}

	public function topLinks( $limit = 25 ) {
		return $this->q( "SELECT link_url, MAX(link_title) AS link_title, COUNT(*) AS clicks, COUNT(DISTINCT subject_short_form) AS guides
			FROM stats WHERE date BETWEEN ? AND ? AND event_type = 'link' AND link_url <> '' AND link_url NOT LIKE '#%' AND (title_id IS NULL OR title_id = 0)
			GROUP BY link_url ORDER BY clicks DESC LIMIT " . (int) $limit );
	}

	public function topTabs( $limit = 25 ) {
		return $this->q( "SELECT subject_short_form AS shortform, tab_name, COUNT(*) AS clicks
			FROM stats WHERE date BETWEEN ? AND ? AND event_type = 'tab_click' AND subject_short_form <> ''
			GROUP BY subject_short_form, tab_name ORDER BY clicks DESC LIMIT " . (int) $limit );
	}
}
