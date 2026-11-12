<?php
namespace SubjectsPlus\Control;

/**
 * Vendor / Publisher of an e-resource record (column title.vendor, added in 4.7.1).
 * Shown on the record form, in the public A–Z list (with a "By vendor" browse), the REST API,
 * Excel/CSV import-export and the staff record search.
 */
class RecordVendor {

	public static function ready() {
		return Schema::hasColumn( 'title', 'vendor' );
	}

	public static function clean( $v ) {
		$v = trim( preg_replace( '/\s+/u', ' ', strip_tags( (string) $v ) ) );

		return mb_substr( $v, 0, 255 );
	}

	public static function get( $titleId ) {
		if ( ! self::ready() || ! (int) $titleId ) {
			return '';
		}
		$st = Schema::conn()->prepare( 'SELECT vendor FROM title WHERE title_id = ?' );
		$st->execute( array( (int) $titleId ) );

		return (string) $st->fetchColumn();
	}

	public static function save( $titleId, $vendor ) {
		if ( ! self::ready() || ! (int) $titleId ) {
			return false;
		}
		$v  = self::clean( $vendor );
		$st = Schema::conn()->prepare( 'UPDATE title SET vendor = ? WHERE title_id = ?' );

		return $st->execute( array( $v === '' ? null : $v, (int) $titleId ) );
	}

	/** title_id => vendor for a list of records (one query). */
	public static function forTitles( array $ids ) {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( ! $ids || ! self::ready() ) {
			return array();
		}
		$st = Schema::conn()->prepare( 'SELECT title_id, vendor FROM title WHERE vendor IS NOT NULL AND vendor <> \'\' AND title_id IN (' . implode( ',', array_fill( 0, count( $ids ), '?' ) ) . ')' );
		$st->execute( $ids );

		return $st->fetchAll( \PDO::FETCH_KEY_PAIR );
	}

	/** Vendors with the number of publicly listed records: [ ['vendor' => …, 'n' => …], … ] */
	public static function publicVendors() {
		if ( ! self::ready() ) {
			return array();
		}
		$sql = "SELECT t.vendor, COUNT(DISTINCT t.title_id) AS n
			FROM title t JOIN location_title lt ON lt.title_id = t.title_id JOIN location l ON l.location_id = lt.location_id
			WHERE t.vendor IS NOT NULL AND t.vendor <> '' AND l.eres_display = 'Y'"
		       . ( RecordSettings::ready() ? ' AND l.record_status IN (' . RecordSettings::publicStatusSql() . ')' : '' ) . "
			GROUP BY t.vendor ORDER BY t.vendor";
		$r   = Schema::conn()->query( $sql );

		return $r ? $r->fetchAll( \PDO::FETCH_ASSOC ) : array();
	}

	/** All vendor names in use (datalist on the record form, bulk actions). */
	public static function allVendors() {
		if ( ! self::ready() ) {
			return array();
		}
		$r = Schema::conn()->query( "SELECT DISTINCT vendor FROM title WHERE vendor IS NOT NULL AND vendor <> '' ORDER BY vendor" );

		return $r ? $r->fetchAll( \PDO::FETCH_COLUMN ) : array();
	}

	/** Line shown under a database in the public A–Z list. */
	public static function publicHtml( $vendor ) {
		$vendor = (string) $vendor;
		if ( $vendor === '' ) {
			return '';
		}

		return '<div class="db-vendor"><span class="db-vendor-label">' . htmlspecialchars( sp__( 'Vendor / Publisher:' ), ENT_QUOTES, 'UTF-8' ) . '</span> '
		       . '<a href="databases.php?letter=byvendor&amp;vendor=' . rawurlencode( $vendor ) . '">' . htmlspecialchars( $vendor, ENT_QUOTES, 'UTF-8' ) . '</a></div>';
	}
}
