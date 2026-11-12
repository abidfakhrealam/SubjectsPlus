<?php
namespace SubjectsPlus\Control\DataExchange;

use SubjectsPlus\Control\Querier;

/**
 * usage of one guide (page views, tabs, boxes, links, databases) for the editor's
 * Analytics panel and its Excel download.
 */
class GuideUsage {

	private static function txt( $s ) {
		return html_entity_decode( (string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	public static function data( $subjectId, $days ) {
		$c  = ( new Querier )->getConnection();
		$st = $c->prepare( "SELECT shortform, subject FROM subject WHERE subject_id = ?" );
		$st->execute( array( (int) $subjectId ) );
		$g = $st->fetch( \PDO::FETCH_ASSOC );
		if ( ! $g ) {
			return null;
		}
		$sf    = $g['shortform'];
		$days  = (int) $days;
		$since = $days > 0 ? time() - $days * 86400 : 0;
		ReportsExchange::matchPendingClicks();
		$q = function ( $sql, array $p = array() ) use ( $c, $sf, $since ) {
			$st = $c->prepare( $sql );
			$st->execute( array_merge( array( $sf, $since ), $p ) );

			return $st->fetchAll( \PDO::FETCH_NUM );
		};
		$out = array( 'guide' => self::txt( $g['subject'] ), 'days' => $days );
		$out['views'] = (int) $q( "SELECT COUNT(*) FROM stats WHERE subject_short_form = ? AND date >= ? AND event_type = 'view'" )[0][0];
		$out['months'] = array();
		foreach ( $q( "SELECT DATE_FORMAT(FROM_UNIXTIME(date), '%Y-%m') m, COUNT(*) FROM stats WHERE subject_short_form = ? AND date >= ? AND event_type = 'view' GROUP BY m ORDER BY m DESC LIMIT 12" ) as $r ) {
			$out['months'][] = array( 'month' => date( 'M Y', strtotime( $r[0] . '-01' ) ), 'views' => (int) $r[1] );
		}
		$out['tabs'] = array();
		foreach ( $q( "SELECT tab_name, COUNT(*) n FROM stats WHERE subject_short_form = ? AND date >= ? AND event_type = 'tab_click' AND tab_name <> '' GROUP BY tab_name ORDER BY n DESC LIMIT 50" ) as $r ) {
			$out['tabs'][] = array( 'tab' => self::txt( $r[0] ), 'clicks' => (int) $r[1] );
		}
		// boxes: clicks on links inside each box
		$titles = array();
		foreach ( $c->query( "SELECT p.pluslet_id, p.title FROM pluslet p JOIN pluslet_section ps ON ps.pluslet_id = p.pluslet_id JOIN section s ON s.section_id = ps.section_id JOIN tab t ON t.tab_id = s.tab_id WHERE t.subject_id = " . (int) $subjectId )->fetchAll( \PDO::FETCH_NUM ) as $r ) {
			$titles[ 'pluslet-' . $r[0] ] = self::txt( $r[1] );
		}
		$out['boxes'] = array();
		foreach ( $q( "SELECT in_pluslet, COUNT(*) n FROM stats WHERE subject_short_form = ? AND date >= ? AND event_type = 'link' AND in_pluslet LIKE 'pluslet-%' GROUP BY in_pluslet ORDER BY n DESC LIMIT 50" ) as $r ) {
			$out['boxes'][] = array( 'box' => $titles[ $r[0] ] ?? ( _( "Removed box" ) . ' (' . $r[0] . ')' ), 'clicks' => (int) $r[1] );
		}
		$out['links'] = array();
		foreach ( $q( "SELECT MAX(link_title), link_url, COUNT(*) n FROM stats WHERE subject_short_form = ? AND date >= ? AND event_type = 'link' AND link_url <> '' GROUP BY link_url ORDER BY n DESC LIMIT 25" ) as $r ) {
			$out['links'][] = array( 'text' => trim( self::txt( $r[0] ) ), 'url' => self::txt( $r[1] ), 'clicks' => (int) $r[2] );
		}
		$out['databases'] = array();
		$has = $c->query( "SHOW COLUMNS FROM stats LIKE 'title_id'" );
		if ( $has && $has->fetch() ) {
			foreach ( $q( "SELECT t.title, COUNT(*) n FROM stats s JOIN title t ON t.title_id = s.title_id WHERE s.subject_short_form = ? AND s.date >= ? AND s.title_id > 0 GROUP BY t.title_id, t.title ORDER BY n DESC LIMIT 25" ) as $r ) {
				$out['databases'][] = array( 'database' => self::txt( $r[0] ), 'clicks' => (int) $r[1] );
			}
		}

		return $out;
	}

	public static function sheets( array $d ) {
		$period = $d['days'] > 0 ? sprintf( 'Last %d days', $d['days'] ) : 'All time';
		$sheets = array(
			array( 'name' => 'Summary', 'rows' => array_merge(
				array( array( 'Guide', $d['guide'] ), array( 'Period', $period ), array( 'Page views', $d['views'] ), array(), array( 'Month', 'Page views' ) ),
				array_map( function ( $m ) { return array( $m['month'], $m['views'] ); }, $d['months'] ) ) ),
		);
		foreach ( array( 'tabs' => array( 'Tabs', 'Tab', 'tab' ), 'boxes' => array( 'Boxes', 'Box', 'box' ), 'databases' => array( 'Databases', 'Database', 'database' ) ) as $k => $v ) {
			$rows = array( array( $v[1], 'Clicks' ) );
			foreach ( $d[ $k ] as $r ) {
				$rows[] = array( $r[ $v[2] ], $r['clicks'] );
			}
			$sheets[] = array( 'name' => $v[0], 'rows' => $rows );
		}
		$rows = array( array( 'Link text', 'Address', 'Clicks' ) );
		foreach ( $d['links'] as $r ) {
			$rows[] = array( $r['text'], $r['url'], $r['clicks'] );
		}
		$sheets[] = array( 'name' => 'Links', 'rows' => $rows );

		return $sheets;
	}
}
