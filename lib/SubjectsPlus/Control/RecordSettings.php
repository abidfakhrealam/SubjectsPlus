<?php

namespace SubjectsPlus\Control;

/**
 * admin-managed values for the record page — formats, access restrictions, statuses and
 * custom fields (Admin > Record Settings). Everything falls back to SubjectsPlus 4.6 behaviour
 * if tools/migrate.php has not been run yet.
 */
#[\AllowDynamicProperties]
class RecordSettings {

	/** How an access restriction behaves for links. */
	const BEHAVIOURS = array(
		'direct'  => 'Open access — link directly',
		'proxy'   => 'Licensed — use off-campus access service',
		'campus'  => 'On campus only — use off-campus access service',
		'noproxy' => 'Licensed, but link directly (IP/SSO handled by vendor)',
	);

	/** Workflow stages a status can belong to (used by presets, dashboards and rules). */
	const STAGES = array(
		'request'  => 'Request / evaluation',
		'draft'    => 'Draft',
		'review'   => 'In review',
		'trial'    => 'Trial',
		'active'   => 'Active / published',
		'renewal'  => 'Renewal',
		'inactive' => 'Inactive / cancelled',
		'expired'  => 'Expired',
		'rejected' => 'Rejected',
	);

	const FIELD_TYPES = array(
		'text'      => 'Text (one line)',
		'textarea'  => 'Long text',
		'select'    => 'Dropdown (pick one)',
		'checkboxes'=> 'Checkboxes (pick several)',
		'date'      => 'Date',
		'number'    => 'Number',
		'money'     => 'Money',
		'url'       => 'Link (web address)',
		'file'      => 'File upload',
	);

	private static $cache = array();

	public static function reset() {
		self::$cache = array();
	}

	private static function conn() {
		$db = new Querier;

		return $db->getConnection();
	}

	private static function tableExists( $table ) {
		if ( ! isset( self::$cache[ 'table:' . $table ] ) ) {
			try {
				$st                                 = self::conn()->query( "SHOW TABLES LIKE " . self::conn()->quote( $table ) );
				self::$cache[ 'table:' . $table ] = $st && $st->fetch() ? true : false;
			} catch ( \Throwable $e ) {
				self::$cache[ 'table:' . $table ] = false;
			}
		}

		return self::$cache[ 'table:' . $table ];
	}

	private static function columnExists( $table, $column ) {
		$k = "col:$table.$column";
		if ( ! isset( self::$cache[ $k ] ) ) {
			try {
				$st                 = self::conn()->query( "SHOW COLUMNS FROM `$table` LIKE " . self::conn()->quote( $column ) );
				self::$cache[ $k ] = $st && $st->fetch() ? true : false;
			} catch ( \Throwable $e ) {
				self::$cache[ $k ] = false;
			}
		}

		return self::$cache[ $k ];
	}

	/** Has tools/migrate.php created the workflow tables? */
	public static function ready() {
		return self::tableExists( 'record_status_option' ) && self::columnExists( 'restrictions', 'behaviour' );
	}

	// ---------------- Formats ----------------

	/** @return array of [format_id, format] for dropdowns (active only unless $all) */
	public static function formats( $all = false, $include = null ) {
		$k = 'formats' . ( $all ? 'all' : '' );
		if ( ! isset( self::$cache[ $k ] ) ) {
			$hasCols = self::columnExists( 'format', 'active' );
			$sql     = $hasCols
				? "SELECT format_id, format, sort_order, active FROM format " . ( $all ? '' : 'WHERE active = 1 ' ) . "ORDER BY sort_order, format"
				: "SELECT format_id, format FROM format ORDER BY format_id";
			self::$cache[ $k ] = self::conn()->query( $sql )->fetchAll( \PDO::FETCH_BOTH );
		}

		return self::withCurrent( self::$cache[ $k ], $include, 'format', 'format_id', 'format' );
	}

	// ---------------- Restrictions ----------------

	public static function restrictions( $all = false, $include = null ) {
		$k = 'restr' . ( $all ? 'all' : '' );
		if ( ! isset( self::$cache[ $k ] ) ) {
			$hasCols = self::columnExists( 'restrictions', 'behaviour' );
			$sql     = $hasCols
				? "SELECT restrictions_id, restrictions, behaviour, sort_order, active FROM restrictions " . ( $all ? '' : 'WHERE active = 1 ' ) . "ORDER BY sort_order, restrictions"
				: "SELECT restrictions_id, restrictions FROM restrictions ORDER BY restrictions_id";
			self::$cache[ $k ] = self::conn()->query( $sql )->fetchAll( \PDO::FETCH_BOTH );
		}

		return self::withCurrent( self::$cache[ $k ], $include, 'restrictions', 'restrictions_id', 'restrictions' );
	}

	/** 'direct' | 'proxy' | 'campus' | 'noproxy' for a restrictions_id */
	public static function behaviour( $restrictionId ) {
		$id = (int) $restrictionId;
		if ( ! isset( self::$cache['behaviour'] ) ) {
			self::$cache['behaviour'] = array();
			try {
				if ( self::columnExists( 'restrictions', 'behaviour' ) ) {
					foreach ( self::conn()->query( "SELECT restrictions_id, behaviour FROM restrictions" )->fetchAll( \PDO::FETCH_ASSOC ) as $r ) {
						self::$cache['behaviour'][ (int) $r['restrictions_id'] ] = $r['behaviour'];
					}
				}
			} catch ( \Throwable $e ) {
				// fall through to built-in meanings
			}
		}
		if ( isset( self::$cache['behaviour'][ $id ] ) ) {
			return self::$cache['behaviour'][ $id ];
		}
		$builtin = array( 1 => 'direct', 2 => 'proxy', 3 => 'campus', 4 => 'noproxy' );

		return $builtin[ $id ] ?? 'direct';
	}

	/** Should links for this restriction go through the off-campus access service? */
	public static function usesProxy( $restrictionId ) {
		return in_array( self::behaviour( $restrictionId ), array( 'proxy', 'campus' ), true );
	}

	/** Should the "restricted" lock icon be shown? */
	public static function isRestricted( $restrictionId ) {
		return self::behaviour( $restrictionId ) !== 'direct';
	}

	// ---------------- Statuses ----------------

	/** @return array of status rows (assoc) keyed by name */
	public static function statuses( $all = false ) {
		$k = 'statuses' . ( $all ? 'all' : '' );
		if ( ! isset( self::$cache[ $k ] ) ) {
			$rows = array();
			if ( self::tableExists( 'record_status_option' ) ) {
				$st = self::conn()->query( "SELECT * FROM record_status_option " . ( $all ? '' : 'WHERE active = 1 ' ) . "ORDER BY sort_order, name" );
				foreach ( $st->fetchAll( \PDO::FETCH_ASSOC ) as $r ) {
					$rows[ $r['name'] ] = $r;
				}
			} else {
				global $record_status;
				$i = 0;
				foreach ( (array) ( $record_status ?? array( 'Active', 'Inactive', 'Trial', 'Rejected' ) ) as $name ) {
					$rows[ $name ] = array( 'status_id' => ++$i, 'name' => $name, 'stage' => strtolower( $name ) === 'trial' ? 'trial' : 'active',
					                        'is_public' => in_array( $name, array( 'Active', 'Trial' ), true ) ? 1 : 0, 'is_initial' => $name === 'Active' ? 1 : 0,
					                        'color' => '#777777', 'description' => '', 'sort_order' => $i, 'active' => 1 );
				}
			}
			self::$cache[ $k ] = $rows;
		}

		return self::$cache[ $k ];
	}

	public static function status( $name ) {
		$all = self::statuses( true );

		return $all[ $name ] ?? null;
	}

	public static function statusById( $id ) {
		foreach ( self::statuses( true ) as $s ) {
			if ( (int) $s['status_id'] === (int) $id ) {
				return $s;
			}
		}

		return null;
	}

	/** Status given to new records. */
	public static function initialStatus() {
		foreach ( self::statuses() as $s ) {
			if ( (int) $s['is_initial'] === 1 ) {
				return $s['name'];
			}
		}
		$all = self::statuses();

		return isset( $all['Active'] ) ? 'Active' : (string) array_key_first( $all );
	}

	/** Names of statuses whose records are shown to the public. */
	public static function publicStatusNames() {
		$out = array();
		foreach ( self::statuses( true ) as $s ) {
			if ( (int) $s['is_public'] === 1 ) {
				$out[] = $s['name'];
			}
		}

		return $out ?: array( 'Active' );
	}

	/** SQL list for "record_status IN (...)", already quoted. */
	public static function publicStatusSql() {
		$c = self::conn();

		return implode( ',', array_map( array( $c, 'quote' ), self::publicStatusNames() ) );
	}

	/** SQL list of statuses in a stage (e.g. 'trial'), already quoted. */
	public static function stageStatusSql( $stage ) {
		$c     = self::conn();
		$names = array();
		foreach ( self::statuses( true ) as $s ) {
			if ( $s['stage'] === $stage ) {
				$names[] = $s['name'];
			}
		}

		return $names ? implode( ',', array_map( array( $c, 'quote' ), $names ) ) : "''";
	}

	/** [value, label] pairs for the status dropdown. */
	public static function statusOptions( $include = null ) {
		$out = array();
		foreach ( self::statuses() as $s ) {
			$out[] = array( $s['name'], $s['name'] );
		}
		if ( $include !== null && $include !== '' && ! isset( self::statuses()[ $include ] ) ) {
			$out[] = array( $include, $include . ' (retired)' );
		}

		return $out;
	}

	// ---------------- Custom fields ----------------

	public static function fields( $all = false ) {
		$k = 'fields' . ( $all ? 'all' : '' );
		if ( ! isset( self::$cache[ $k ] ) ) {
			self::$cache[ $k ] = self::tableExists( 'record_field' )
				? self::conn()->query( "SELECT * FROM record_field " . ( $all ? '' : 'WHERE active = 1 ' ) . "ORDER BY sort_order, label" )->fetchAll( \PDO::FETCH_ASSOC )
				: array();
		}

		return self::$cache[ $k ];
	}

	public static function fieldOptions( array $field ) {
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $field['options'] ) as $o ) {
			$o = trim( $o );
			if ( $o !== '' ) {
				$out[] = $o;
			}
		}

		return $out;
	}

	/** Make sure a retired (inactive) value already saved on a record still appears in its dropdown. */
	private static function withCurrent( array $rows, $include, $table, $idCol, $labelCol ) {
		if ( $include === null || $include === '' ) {
			return $rows;
		}
		foreach ( $rows as $r ) {
			if ( (string) $r[0] === (string) $include ) {
				return $rows;
			}
		}
		try {
			$st = self::conn()->prepare( "SELECT `$idCol`, `$labelCol` FROM `$table` WHERE `$idCol` = ?" );
			$st->execute( array( $include ) );
			if ( $r = $st->fetch( \PDO::FETCH_NUM ) ) {
				$rows[] = array( 0 => $r[0], 1 => $r[1] . ' (retired)' );
			}
		} catch ( \Throwable $e ) {
		}

		return $rows;
	}
}
