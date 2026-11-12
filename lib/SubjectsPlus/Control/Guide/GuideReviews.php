<?php
namespace SubjectsPlus\Control\Guide;

use SubjectsPlus\Control\Querier;
use SubjectsPlus\Control\Workflow;

/**
 * regular review of research guides. Each guide is due for review a number of months after
 * it was last reviewed (or, if never reviewed, after its last change). Owners get an email when a
 * public guide is due (at most once a week) until someone marks it as reviewed.
 * Months: per guide, else $sp_guide_review_months in config.php (default 6; 0 switches reviews off).
 */
class GuideReviews {

	private static function conn() {
		return ( new Querier )->getConnection();
	}

	public static function ready() {
		$r = self::conn()->query( "SHOW TABLES LIKE 'guide_review'" );

		return (bool) ( $r && $r->fetch() );
	}

	public static function defaultMonths() {
		global $sp_guide_review_months;

		return isset( $sp_guide_review_months ) ? max( 0, (int) $sp_guide_review_months ) : 6;
	}

	private static function txt( $s ) {
		return html_entity_decode( (string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	/** One row per guide with owners, last review, due date and state ('overdue', 'soon', 'ok', 'off'). */
	public static function all( $today = null ) {
		$today  = $today ?: date( 'Y-m-d' );
		$c      = self::conn();
		$owners = array();
		foreach ( $c->query( "SELECT ss.subject_id, CONCAT(s.fname, ' ', s.lname) AS n, s.email FROM staff_subject ss JOIN staff s ON s.staff_id = ss.staff_id WHERE s.active = 1" )->fetchAll( \PDO::FETCH_ASSOC ) as $r ) {
			$owners[ (int) $r['subject_id'] ][] = array( 'name' => trim( self::txt( $r['n'] ) ), 'email' => $r['email'] );
		}
		$rev = array();
		if ( self::ready() ) {
			foreach ( $c->query( "SELECT r.*, CONCAT(s.fname, ' ', s.lname) AS by_name FROM guide_review r LEFT JOIN staff s ON s.staff_id = r.reviewed_by" )->fetchAll( \PDO::FETCH_ASSOC ) as $r ) {
				$rev[ (int) $r['subject_id'] ] = $r;
			}
		}
		$out = array();
		foreach ( $c->query( "SELECT subject_id, subject, shortform, active, last_modified FROM subject ORDER BY subject" )->fetchAll( \PDO::FETCH_ASSOC ) as $g ) {
			$id     = (int) $g['subject_id'];
			$r      = $rev[ $id ] ?? array();
			$months = isset( $r['interval_months'] ) && $r['interval_months'] !== null ? (int) $r['interval_months'] : self::defaultMonths();
			$base   = ! empty( $r['last_reviewed'] ) ? $r['last_reviewed'] : substr( (string) $g['last_modified'], 0, 10 );
			$due    = $months > 0 && $base ? date( 'Y-m-d', strtotime( $base . " +$months months" ) ) : null;
			$state  = $due === null ? 'off' : ( $due <= $today ? 'overdue' : ( $due <= date( 'Y-m-d', strtotime( $today . ' +30 days' ) ) ? 'soon' : 'ok' ) );
			$out[ $id ] = array(
				'subject_id' => $id, 'guide' => self::txt( $g['subject'] ), 'shortform' => $g['shortform'], 'active' => (int) $g['active'],
				'owners' => $owners[ $id ] ?? array(), 'months' => $months, 'own_months' => isset( $r['interval_months'] ) ? $r['interval_months'] : null,
				'last_reviewed' => $r['last_reviewed'] ?? null, 'reviewed_by' => isset( $r['by_name'] ) ? trim( self::txt( $r['by_name'] ) ) : '',
				'last_changed' => substr( (string) $g['last_modified'], 0, 10 ), 'due' => $due, 'state' => $state, 'last_reminded' => $r['last_reminded'] ?? null,
			);
		}

		return $out;
	}

	public static function one( $subjectId ) {
		$all = self::all();

		return $all[ (int) $subjectId ] ?? null;
	}

	public static function markReviewed( $subjectId, $staffId, $date = null ) {
		self::conn()->prepare( "INSERT INTO guide_review (subject_id, last_reviewed, reviewed_by, last_reminded) VALUES (?, ?, ?, NULL)
			ON DUPLICATE KEY UPDATE last_reviewed = VALUES(last_reviewed), reviewed_by = VALUES(reviewed_by), last_reminded = NULL" )
		    ->execute( array( (int) $subjectId, $date ?: date( 'Y-m-d' ), (int) $staffId ?: null ) );
	}

	/** null = library default */
	public static function setMonths( $subjectId, $months ) {
		$m = ( $months === '' || $months === null ) ? null : max( 0, min( 60, (int) $months ) );
		self::conn()->prepare( "INSERT INTO guide_review (subject_id, interval_months) VALUES (?, ?) ON DUPLICATE KEY UPDATE interval_months = VALUES(interval_months)" )
		    ->execute( array( (int) $subjectId, $m ) );
	}

	/** Emails owners of public guides that are due. Returns log lines. */
	public static function sendReminders( $today = null, $force = false ) {
		global $CpanelPath, $resource_name;
		if ( ! self::ready() ) {
			return array( 'guide_review table missing — apply the database updates' );
		}
		$today = $today ?: date( 'Y-m-d' );
		$log   = array();
		foreach ( self::all( $today ) as $g ) {
			if ( $g['state'] !== 'overdue' || $g['active'] !== 1 || ! $g['owners'] ) {
				continue;
			}
			if ( ! $force && $g['last_reminded'] && $g['last_reminded'] > date( 'Y-m-d', strtotime( $today . ' -7 days' ) ) ) {
				continue;
			}
			$link = rtrim( (string) $CpanelPath, '/' ) . '/guides/metadata.php?subject_id=' . $g['subject_id'];
			$html = '<p>' . sprintf( _( "The research guide <strong>%s</strong> is due for its regular review (every %d months; last reviewed: %s)." ),
					htmlspecialchars( $g['guide'] ), $g['months'], $g['last_reviewed'] ? date( 'j M Y', strtotime( $g['last_reviewed'] ) ) : _( "never" ) ) . '</p>'
				. '<p>' . _( "Please check that links work, information is current and contacts are right. Then open the guide settings and click <em>Mark as reviewed</em>:" ) . '</p>'
				. '<p><a href="' . htmlspecialchars( $link ) . '">' . htmlspecialchars( $link ) . '</a></p>'
				. '<p style="color:#666">' . _( "You will get this reminder once a week until the guide is marked as reviewed." ) . '</p>';
			$ok = Workflow::sendMail( array_column( $g['owners'], 'email' ), sprintf( _( "Guide review due: %s" ), $g['guide'] ), $html );
			if ( $ok ) {
				self::conn()->prepare( "INSERT INTO guide_review (subject_id, last_reminded) VALUES (?, ?) ON DUPLICATE KEY UPDATE last_reminded = VALUES(last_reminded)" )
				    ->execute( array( $g['subject_id'], $today ) );
			}
			$log[] = ( $ok ? 'reminder sent' : 'reminder NOT sent (mail error)' ) . ': ' . $g['guide'] . ' → ' . implode( ', ', array_column( $g['owners'], 'email' ) );
		}

		return $log;
	}

	/** "Last reviewed" line for the public guide page ('' when never reviewed or reviews are off). */
	public static function publicLine( $subjectId ) {
		global $sp_guide_show_reviewed;
		if ( isset( $sp_guide_show_reviewed ) && ! $sp_guide_show_reviewed ) {
			return '';
		}
		if ( ! self::ready() ) {
			return '';
		}
		$st = self::conn()->prepare( "SELECT last_reviewed FROM guide_review WHERE subject_id = ?" );
		$st->execute( array( (int) $subjectId ) );
		$d = $st->fetchColumn();

		return $d ? '<p class="sp-reviewed">' . sp__( "Last reviewed:" ) . ' ' . htmlspecialchars( date( 'j M Y', strtotime( $d ) ) ) . '</p>' : '';
	}
}
