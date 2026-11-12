<?php
namespace SubjectsPlus\Control;

/**
 * Staff user types (Staff, Machine, Student, … — table user_type), managed in Admin → Record Settings → User types.
 * Types marked "public directory" are listed on the public staff pages, the staff API and OAI
 * (SubjectsPlus 4.6 and earlier: user type 1 only).
 */
class UserTypes {

	/** user type 1 ("Staff") is used by the code itself and cannot be deleted */
	const BUILT_IN = 1;

	private static $public = null;

	public static function ready() {
		return Schema::hasColumn( 'user_type', 'public_directory' );
	}

	public static function reset() {
		self::$public = null;
	}

	public static function all() {
		$sql = self::ready()
			? 'SELECT user_type_id, user_type, description, public_directory, sort_order FROM user_type ORDER BY sort_order, user_type'
			: 'SELECT user_type_id, user_type, \'\' AS description, (user_type_id = 1) AS public_directory, user_type_id AS sort_order FROM user_type ORDER BY user_type_id';
		$r   = Schema::conn()->query( $sql );

		return $r ? $r->fetchAll( \PDO::FETCH_ASSOC ) : array();
	}

	/** ids listed in the public directory */
	public static function publicIds() {
		if ( self::$public === null ) {
			self::$public = array( 1 );
			if ( self::ready() ) {
				$r = Schema::conn()->query( 'SELECT user_type_id FROM user_type WHERE public_directory = 1' );
				if ( $r ) {
					self::$public = array_map( 'intval', $r->fetchAll( \PDO::FETCH_COLUMN ) ) ?: array( 0 );
				}
			}
		}

		return self::$public;
	}

	/** SQL list for "user_type_id IN (…)" — integers only, safe to paste. */
	public static function publicSql() {
		return implode( ',', self::publicIds() );
	}

	public static function staffCounts() {
		$r = Schema::conn()->query( 'SELECT user_type_id, COUNT(*) FROM staff GROUP BY user_type_id' );

		return $r ? $r->fetchAll( \PDO::FETCH_KEY_PAIR ) : array();
	}
}
