<?php
namespace SubjectsPlus\Control;

/**
 * Cached "does this table / column exist?" checks, so features added by a database update stay
 * switched off (instead of failing) until Admin → Database Updates has been run.
 */
class Schema {

	private static $cache = array();

	public static function conn() {
		return ( new Querier )->getConnection();
	}

	public static function reset() {
		self::$cache = array();
	}

	public static function hasTable( $table ) {
		$k = 't:' . $table;
		if ( ! isset( self::$cache[ $k ] ) ) {
			try {
				$st                = self::conn()->query( 'SHOW TABLES LIKE ' . self::conn()->quote( $table ) );
				self::$cache[ $k ] = $st && $st->fetch() ? true : false;
			} catch ( \Throwable $e ) {
				self::$cache[ $k ] = false;
			}
		}

		return self::$cache[ $k ];
	}

	public static function hasColumn( $table, $column ) {
		$k = 'c:' . $table . '.' . $column;
		if ( ! isset( self::$cache[ $k ] ) ) {
			try {
				$table             = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $table );
				$st                = self::conn()->query( "SHOW COLUMNS FROM `$table` LIKE " . self::conn()->quote( $column ) );
				self::$cache[ $k ] = $st && $st->fetch() ? true : false;
			} catch ( \Throwable $e ) {
				self::$cache[ $k ] = false;
			}
		}

		return self::$cache[ $k ];
	}
}
