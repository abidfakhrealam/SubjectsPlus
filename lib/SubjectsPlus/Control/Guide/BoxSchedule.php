<?php
namespace SubjectsPlus\Control\Guide;

use SubjectsPlus\Control\Querier;

/**
 * optional dates between which a guide box is shown on the public page (exam-time notices,
 * semester content, temporary outages …). Staff always see the box in the editor, with a label.
 */
class BoxSchedule {

	private static $ready = null;
	private static $cache = array();

	private static function conn() {
		return ( new Querier )->getConnection();
	}

	public static function ready() {
		if ( self::$ready === null ) {
			$r           = self::conn()->query( "SHOW TABLES LIKE 'pluslet_schedule'" );
			self::$ready = (bool) ( $r && $r->fetch() );
		}

		return self::$ready;
	}

	/** Loads the schedules of these boxes (one query per section). */
	public static function preload( array $ids ) {
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( ! $ids || ! self::ready() ) {
			return;
		}
		foreach ( $ids as $id ) {
			self::$cache[ $id ] = self::$cache[ $id ] ?? null;
		}
		$st = self::conn()->query( "SELECT pluslet_id, show_from, hide_after FROM pluslet_schedule WHERE pluslet_id IN (" . implode( ',', $ids ) . ")" );
		foreach ( $st ? $st->fetchAll( \PDO::FETCH_ASSOC ) : array() as $r ) {
			self::$cache[ (int) $r['pluslet_id'] ] = $r;
		}
	}

	public static function get( $id ) {
		$id = (int) $id;
		if ( ! array_key_exists( $id, self::$cache ) ) {
			self::preload( array( $id ) );
		}

		return self::$cache[ $id ] ?? null;
	}

	/** Is the box shown to the public today? */
	public static function visible( $id, $today = null ) {
		$s = self::get( $id );
		if ( ! $s ) {
			return true;
		}
		$today = $today ?: date( 'Y-m-d' );
		if ( ! empty( $s['show_from'] ) && $today < $s['show_from'] ) {
			return false;
		}
		if ( ! empty( $s['hide_after'] ) && $today > $s['hide_after'] ) {
			return false;
		}

		return true;
	}

	/** Short text for the editor, e.g. "Shown 1 Nov – 30 Nov 2026 (hidden now)"; '' when not scheduled. */
	public static function label( $id ) {
		$s = self::get( $id );
		if ( ! $s || ( empty( $s['show_from'] ) && empty( $s['hide_after'] ) ) ) {
			return '';
		}
		$f = function ( $d ) {
			return date( 'j M Y', strtotime( $d ) );
		};
		if ( ! empty( $s['show_from'] ) && ! empty( $s['hide_after'] ) ) {
			$t = sprintf( _( "Shown %1\$s – %2\$s" ), $f( $s['show_from'] ), $f( $s['hide_after'] ) );
		} elseif ( ! empty( $s['show_from'] ) ) {
			$t = sprintf( _( "Shown from %s" ), $f( $s['show_from'] ) );
		} else {
			$t = sprintf( _( "Shown until %s" ), $f( $s['hide_after'] ) );
		}

		return $t . ( self::visible( $id ) ? '' : ' — ' . _( "hidden on the public page now" ) );
	}

	/** @return true|string */
	public static function save( $id, $from, $until, $staffId ) {
		if ( ! self::ready() ) {
			return _( "Database updates for this version have not been applied (Admin → Database Updates)." );
		}
		$id = (int) $id;
		foreach ( array( &$from, &$until ) as &$d ) {
			$d = trim( (string) $d );
			if ( $d !== '' && ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) || ! strtotime( $d ) ) ) {
				return _( "Please enter dates as YYYY-MM-DD." );
			}
			$d = $d === '' ? null : $d;
		}
		unset( $d );
		if ( $from && $until && $until < $from ) {
			return _( "The end date is before the start date." );
		}
		$c = self::conn();
		if ( ! $from && ! $until ) {
			$c->prepare( "DELETE FROM pluslet_schedule WHERE pluslet_id = ?" )->execute( array( $id ) );
		} else {
			$c->prepare( "INSERT INTO pluslet_schedule (pluslet_id, show_from, hide_after, updated_by, updated_at) VALUES (?,?,?,?,NOW())
				ON DUPLICATE KEY UPDATE show_from = VALUES(show_from), hide_after = VALUES(hide_after), updated_by = VALUES(updated_by), updated_at = NOW()" )
			  ->execute( array( $id, $from, $until, (int) $staffId ?: null ) );
		}
		unset( self::$cache[ $id ] );

		return true;
	}

	/** May the signed-in staff member edit this box (owner of a guide that contains it, or admin / all guides)? */
	public static function canEdit( $id ) {
		if ( ! empty( $_SESSION['admin'] ) || ! empty( $_SESSION['allguides'] ) ) {
			return true;
		}
		$st = self::conn()->prepare( "SELECT COUNT(*) FROM pluslet_section ps JOIN section s ON s.section_id = ps.section_id JOIN tab t ON t.tab_id = s.tab_id
			JOIN staff_subject ss ON ss.subject_id = t.subject_id WHERE ps.pluslet_id = ? AND ss.staff_id = ?" );
		$st->execute( array( (int) $id, (int) ( $_SESSION['staff_id'] ?? 0 ) ) );

		return (int) $st->fetchColumn() > 0;
	}
}
