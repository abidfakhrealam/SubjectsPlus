<?php
namespace SubjectsPlus\Control;

/** Small key/value store (table sp_state): scheduled-task heartbeats, last link check, upgrade state. */
class SystemState {

	public static function ready() {
		return Schema::hasTable( 'sp_state' );
	}

	public static function get( $name, $default = null ) {
		if ( ! self::ready() ) {
			return $default;
		}
		$st = Schema::conn()->prepare( 'SELECT value FROM sp_state WHERE name = ?' );
		if ( ! $st || ! $st->execute( array( (string) $name ) ) ) {
			return $default;
		}
		$v = $st->fetchColumn();
		if ( $v === false || $v === null ) {
			return $default;
		}
		$d = json_decode( $v, true );

		return json_last_error() === JSON_ERROR_NONE ? $d : $default;
	}

	/** When was this value last written (Y-m-d H:i:s) or null. */
	public static function updatedAt( $name ) {
		if ( ! self::ready() ) {
			return null;
		}
		$st = Schema::conn()->prepare( 'SELECT updated_at FROM sp_state WHERE name = ?' );

		return $st && $st->execute( array( (string) $name ) ) ? ( $st->fetchColumn() ?: null ) : null;
	}

	public static function set( $name, $value ) {
		if ( ! self::ready() ) {
			return false;
		}
		$st = Schema::conn()->prepare( 'INSERT INTO sp_state (name, value, updated_at) VALUES (?, ?, NOW())
			ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = VALUES(updated_at)' );

		return $st && $st->execute( array( (string) $name, json_encode( $value ) ) );
	}
}
