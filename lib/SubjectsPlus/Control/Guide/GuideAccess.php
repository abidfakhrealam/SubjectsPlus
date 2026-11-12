<?php
namespace SubjectsPlus\Control\Guide;

use SubjectsPlus\Control\Schema;

/**
 * Per-guide permissions. A guide can be changed by:
 *   - administrators and staff with the "allguides" permission (any guide);
 *   - the guide's owners / co-editors (Guide metadata → staff, table staff_subject).
 * Every editor helper script checks this, not only the guide page (4.7.0 and earlier only checked the page).
 */
class GuideAccess {

	private static $cache = array();

	public static function isGuideAdmin() {
		return ! empty( $_SESSION['admin'] ) || ! empty( $_SESSION['allguides'] );
	}

	public static function canEdit( $subjectId ) {
		$subjectId = (int) $subjectId;
		if ( self::isGuideAdmin() ) {
			return true;
		}
		if ( $subjectId <= 0 || empty( $_SESSION['staff_id'] ) ) {
			return false;
		}
		if ( ! isset( self::$cache[ $subjectId ] ) ) {
			$st = Schema::conn()->prepare( 'SELECT COUNT(*) FROM staff_subject WHERE subject_id = ? AND staff_id = ?' );
			$st->execute( array( $subjectId, (int) $_SESSION['staff_id'] ) );
			self::$cache[ $subjectId ] = (int) $st->fetchColumn() > 0;
		}

		return self::$cache[ $subjectId ];
	}

	private static function one( $sql, $id ) {
		$st = Schema::conn()->prepare( $sql );
		if ( ! $st || ! $st->execute( array( (int) $id ) ) ) {
			return array();
		}

		return array_map( 'intval', $st->fetchAll( \PDO::FETCH_COLUMN ) );
	}

	public static function guidesForTab( $tabId ) {
		return self::one( 'SELECT subject_id FROM tab WHERE tab_id = ?', $tabId );
	}

	public static function guidesForSection( $sectionId ) {
		return self::one( 'SELECT t.subject_id FROM section s JOIN tab t ON t.tab_id = s.tab_id WHERE s.section_id = ?', $sectionId );
	}

	/** Guides a box appears on (a box that is not placed on any guide yet returns []). */
	public static function guidesForPluslet( $plusletId ) {
		return self::one( 'SELECT DISTINCT t.subject_id FROM pluslet_section ps JOIN section s ON s.section_id = ps.section_id
			JOIN tab t ON t.tab_id = s.tab_id WHERE ps.pluslet_id = ?', $plusletId );
	}

	public static function guidesForRank( $rankId ) {
		return self::one( 'SELECT subject_id FROM `rank` WHERE rank_id = ?', $rankId );
	}

	/** True when the person can edit every guide in the list (an empty list = nothing to protect). */
	public static function canEditAll( array $subjectIds ) {
		foreach ( $subjectIds as $s ) {
			if ( ! self::canEdit( $s ) ) {
				return false;
			}
		}

		return true;
	}

	/** Stop the request with 403 unless the person may edit all of these guides. */
	public static function requireAll( array $subjectIds, $json = false ) {
		if ( self::canEditAll( $subjectIds ) ) {
			return;
		}
		$msg = _( "You do not have permission to edit this guide. Ask one of its editors or an administrator to add you as a co-editor." );
		http_response_code( 403 );
		if ( $json ) {
			header( 'Content-Type: application/json' );
			echo json_encode( array( 'ok' => false, 'message' => $msg ) );
		} else {
			echo htmlspecialchars( $msg, ENT_QUOTES, 'UTF-8' );
		}
		exit;
	}

	/** Like requireAll(), but an empty list (unknown tab / section / list item) is refused too: 403, or 404 for guide admins. */
	public static function requireFound( array $subjectIds, $json = false ) {
		if ( ! $subjectIds ) {
			if ( ! self::isGuideAdmin() ) {
				self::requireAll( array( 0 ), $json );
			}
			http_response_code( 404 );
			if ( $json ) {
				header( 'Content-Type: application/json' );
				echo json_encode( array( 'ok' => false, 'message' => 'Not found' ) );
			} else {
				echo 'Not found';
			}
			exit;
		}
		self::requireAll( $subjectIds, $json );
	}

	public static function requireGuide( $subjectId, $json = false ) {
		self::requireAll( array( (int) $subjectId ), $json );
	}
}
