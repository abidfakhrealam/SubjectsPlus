<?php
namespace SubjectsPlus\Control\DataExchange;

use SubjectsPlus\Control\Querier;
use SubjectsPlus\Control\RecordSettings;
use SubjectsPlus\Control\RecordFields;
use SubjectsPlus\Control\Workflow;

/**
 * export e-resource (A–Z) records to a spreadsheet and import them back.
 *
 * One row per record (per location, for the rare record with several locations). On import,
 * only the columns present in the file are changed; rows are checked first (preview) and
 * written only when the librarian confirms.
 */
class RecordsExchange {

	/** key => accepted header spellings (the first one is used on export) */
	public static function columns() {
		$cols = array(
			'title_id'       => array( 'Record ID', 'ID', 'title id' ),
			'location_id'    => array( 'Location ID' ),
			'title'          => array( 'Title', 'Database', 'Name', 'publication_title' ),
			'alternate'      => array( 'Alternate title', 'Also known as', 'Alt title' ),
			'prefix'         => array( 'Prefix', 'pre' ),
			'vendor'         => array( 'Vendor / Publisher', 'Vendor', 'Publisher', 'publisher_name', 'Provider' ),
			'url'            => array( 'URL', 'Link', 'Web address', 'title_url', 'location' ),
			'format'         => array( 'Format' ),
			'access'         => array( 'Access', 'Access restrictions', 'Restriction' ),
			'status'         => array( 'Status', 'Record status' ),
			'az'             => array( 'Show in A-Z', 'Show in AZ', 'A-Z', 'Display', 'eres_display' ),
			'subjects'       => array( 'Subjects', 'Subject', 'Guides' ),
			'source'         => array( 'Subject category', 'Source type', 'Category' ),
			'description'    => array( 'Description' ),
			'display_note'   => array( 'Display note', 'Note' ),
			'helpguide'      => array( 'Help guide', 'Help guide URL', 'helpguide' ),
			'call_number'    => array( 'Call number' ),
			'ctags'          => array( 'Tags', 'ctags' ),
			'trial_start'    => array( 'Trial start' ),
			'trial_end'      => array( 'Trial end' ),
			'internal_notes' => array( 'Internal notes', 'Staff notes' ),
			'last_modified'  => array( 'Last modified' ),
		);
		if ( ! \SubjectsPlus\Control\RecordVendor::ready() ) {
			unset( $cols['vendor'] ); // database update not applied yet
		}
		$labels = array();
		foreach ( RecordSettings::fields( true ) as $f ) {
			$labels[ mb_strtolower( trim( $f['label'] ) ) ][] = (int) $f['field_id'];
		}
		foreach ( RecordSettings::fields( true ) as $f ) {
			if ( $f['type'] !== 'file' ) {
				$id   = (int) $f['field_id'];
				$name = $f['label'];
				// two fields with the same name (or one named like a built-in column) get their number added
				if ( count( $labels[ mb_strtolower( trim( $name ) ) ] ) > 1 || self::isBuiltinName( $name, $cols ) ) {
					$name .= ' [' . $id . ']';
				}
				$cols[ 'cf' . $id ] = array( $name, $f['label'] . ' [' . $id . ']' );
			}
		}

		return $cols;
	}

	private static function isBuiltinName( $name, array $cols ) {
		$n = preg_replace( '/[^a-z0-9]+/', '', mb_strtolower( $name ) );
		foreach ( $cols as $names ) {
			foreach ( $names as $x ) {
				if ( preg_replace( '/[^a-z0-9]+/', '', mb_strtolower( $x ) ) === $n ) {
					return true;
				}
			}
		}

		return false;
	}

	/** SubjectsPlus tables use MySQL "utf8" (3-byte), which cannot store emoji: drop them instead of failing the row. */
	public static function bmp( $s ) {
		return preg_replace( '/[\x{10000}-\x{10FFFF}]/u', '', (string) $s );
	}

	private static function conn() {
		return ( new Querier )->getConnection();
	}

	private static function txt( $s ) {
		return html_entity_decode( (string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	/* =================================================================== export */

	/**
	 * Rows (first row = header). $filter: subject_id, status, format_id, q (text search).
	 */
	public static function exportRows( array $filter = array() ) {
		$c      = self::conn();
		$where  = array( '1=1' );
		$params = array();
		if ( ! empty( $filter['subject_id'] ) ) {
			$where[]  = 't.title_id IN (SELECT title_id FROM `rank` WHERE subject_id = ?)';
			$params[] = (int) $filter['subject_id'];
		}
		if ( isset( $filter['status'] ) && $filter['status'] !== '' ) {
			$where[]  = 'l.record_status = ?';
			$params[] = $filter['status'];
		}
		if ( ! empty( $filter['format_id'] ) ) {
			$where[]  = 'l.format = ?';
			$params[] = (int) $filter['format_id'];
		}
		$hasVendor = \SubjectsPlus\Control\RecordVendor::ready();
		$st = $c->prepare( "SELECT t.title_id, t.title, t.alternate_title, t.pre, " . ( $hasVendor ? "t.vendor, " : "" ) . "t.description, t.internal_notes, t.last_modified,
				l.location_id, l.location, l.format, l.access_restrictions, l.record_status, l.eres_display, l.eres_display_saved, l.display_note,
				l.helpguide, l.call_number, l.ctags, l.trial_start, l.trial_end
			FROM title t
			LEFT JOIN location_title lt ON lt.title_id = t.title_id
			LEFT JOIN location l ON l.location_id = lt.location_id
			WHERE " . implode( ' AND ', $where ) . "
			ORDER BY t.title, l.location_id" );
		$st->execute( $params );
		$records = $st->fetchAll( \PDO::FETCH_ASSOC );

		$formats = $restr = array();
		foreach ( RecordSettings::formats( true ) as $f ) {
			$formats[ (int) $f[0] ] = $f[1];
		}
		foreach ( RecordSettings::restrictions( true ) as $r ) {
			$restr[ (int) $r[0] ] = $r[1];
		}
		$subjects = $sources = array();
		foreach ( $c->query( "SELECT r.title_id, s.subject, so.source FROM `rank` r JOIN subject s ON s.subject_id = r.subject_id LEFT JOIN source so ON so.source_id = r.source_id ORDER BY s.subject" )->fetchAll( \PDO::FETCH_ASSOC ) as $r ) {
			$subjects[ (int) $r['title_id'] ][] = $r['subject'];
			if ( ! isset( $sources[ (int) $r['title_id'] ] ) && $r['source'] !== null ) {
				$sources[ (int) $r['title_id'] ] = $r['source'];
			}
		}
		$fields = array();
		foreach ( RecordSettings::fields( true ) as $f ) {
			if ( $f['type'] !== 'file' ) {
				$fields[ (int) $f['field_id'] ] = $f;
			}
		}
		$values = array();
		if ( $fields ) {
			foreach ( $c->query( "SELECT title_id, field_id, value FROM record_field_value" )->fetchAll( \PDO::FETCH_ASSOC ) as $v ) {
				$values[ (int) $v['title_id'] ][ (int) $v['field_id'] ] = $v['value'];
			}
		}

		$cols   = self::columns();
		$header = array();
		foreach ( $cols as $k => $names ) {
			$header[] = $names[0];
		}
		$out = array( $header );
		foreach ( $records as $r ) {
			$id = (int) $r['title_id'];
			// the librarian's own A-Z choice, even while the status hides the record
			$az  = $r['eres_display'] === 'Y' || $r['eres_display_saved'] === 'Y' ? 'Yes' : 'No';
			$row = array(
				$id,
				$r['location_id'] !== null ? (int) $r['location_id'] : '',
				self::txt( $r['title'] ),
				self::txt( $r['alternate_title'] ),
				self::txt( $r['pre'] ),
			);
			if ( $hasVendor ) {
				$row[] = (string) $r['vendor'];
			}
			array_push( $row,
				self::txt( $r['location'] ),
				$formats[ (int) $r['format'] ] ?? '',
				$restr[ (int) $r['access_restrictions'] ] ?? '',
				(string) $r['record_status'],
				$r['location_id'] !== null ? $az : '',
				implode( '; ', array_map( array( __CLASS__, 'txt' ), $subjects[ $id ] ?? array() ) ),
				self::txt( $sources[ $id ] ?? '' ),
				(string) $r['description'],
				(string) $r['display_note'],
				self::txt( $r['helpguide'] ),
				self::txt( $r['call_number'] ),
				self::txt( $r['ctags'] ),
				self::dateOut( $r['trial_start'] ),
				self::dateOut( $r['trial_end'] ),
				(string) $r['internal_notes'],
				(string) $r['last_modified']
			);
			foreach ( $fields as $fid => $f ) {
				$v = $values[ $id ][ $fid ] ?? '';
				if ( $f['type'] === 'checkboxes' && $v !== '' ) {
					$v = implode( '; ', (array) json_decode( $v, true ) );
				} elseif ( $f['type'] === 'date' && $v !== '' ) {
					$v = self::dateOut( $v );
				} elseif ( ( $f['type'] === 'number' || $f['type'] === 'money' ) && is_numeric( $v ) ) {
					$v = $v + 0;
				}
				$row[] = $v;
			}
			$out[] = $row;
		}

		return $out;
	}

	private static function dateOut( $d ) {
		if ( ! $d || strpos( (string) $d, '0000' ) === 0 ) {
			return '';
		}

		return new \DateTime( substr( (string) $d, 0, 10 ) );
	}

	/** Sheet with the allowed values, to help people filling in the file. */
	public static function listsRows() {
		$c    = self::conn();
		$cols = array(
			'Format'           => array_map( function ( $f ) { return $f[1]; }, RecordSettings::formats() ),
			'Access'           => array_map( function ( $r ) { return $r[1]; }, RecordSettings::restrictions() ),
			'Status'           => array_keys( RecordSettings::statuses() ),
			'Show in A-Z'      => array( 'Yes', 'No' ),
			'Subjects'         => array_map( array( __CLASS__, 'txt' ), $c->query( "SELECT subject FROM subject ORDER BY subject" )->fetchAll( \PDO::FETCH_COLUMN ) ),
			'Subject category' => array_map( array( __CLASS__, 'txt' ), $c->query( "SELECT source FROM source ORDER BY rs, source" )->fetchAll( \PDO::FETCH_COLUMN ) ),
		);
		foreach ( RecordSettings::fields() as $f ) {
			if ( $f['type'] === 'select' || $f['type'] === 'checkboxes' ) {
				$cols[ $f['label'] ] = RecordSettings::fieldOptions( $f );
			}
		}
		$rows = array( array_keys( $cols ) );
		$max  = max( array_map( 'count', $cols ) );
		for ( $i = 0; $i < $max; $i++ ) {
			$row = array();
			foreach ( $cols as $vals ) {
				$row[] = $vals[ $i ] ?? '';
			}
			$rows[] = $row;
		}

		return $rows;
	}

	public static function helpRows() {
		return array(
			array( 'How to use this file' ),
			array( 'Keep the first sheet ("Records") and its header row. Columns can be in any order; columns you remove are left unchanged on import.' ),
			array( 'Record ID: leave empty for a new record. Keep it to update an existing record.' ),
			array( 'Subjects: several subjects separated by semicolons, e.g. "Law; Business". Names must match existing guides (see the Lists sheet).' ),
			array( 'Format, Access, Status, Subject category and dropdown fields: use a value from the Lists sheet (upper/lower case does not matter).' ),
			array( 'Show in A-Z: Yes or No. Records whose status is not public stay hidden until their status is public.' ),
			array( 'Dates: YYYY-MM-DD (e.g. 2026-11-30) or DD/MM/YYYY (e.g. 30/11/2026), or an Excel date.' ),
			array( 'Checkbox fields: several values separated by semicolons.' ),
			array( 'Description, Display note and Internal notes may contain simple HTML (links, bold, lists).' ),
			array( 'Last modified is for information only and is ignored on import.' ),
		);
	}

	/* =================================================================== import */

	/**
	 * Checks every row and works out what would happen. Nothing is written.
	 * $opt: mode ('both'|'add'|'update'), match ('url'|'title'|'none'), blanks ('keep'|'clear').
	 * Returns array( 'rows' => [ rowNo => ['action','id','title','messages','data'] ], 'errors' => [general], 'counts' => [] ).
	 */
	public static function plan( array $sheetRows, array $opt ) {
		$c      = self::conn();
		$mode   = $opt['mode'] ?? 'both';
		$match  = $opt['match'] ?? 'url';
		$blanks = $opt['blanks'] ?? 'keep';
		$map    = \SubjectsPlus\Control\Spreadsheet::mapHeader( $sheetRows, self::columns() );
		$res    = array( 'rows' => array(), 'errors' => array(), 'unknown' => $map['unknown'], 'counts' => array( 'add' => 0, 'update' => 0, 'skip' => 0, 'error' => 0 ) );
		if ( ! $map['rows'] ) {
			$res['errors'][] = _( "No data rows found. The first row must be the column headings (e.g. Title, URL, Format…)." );

			return $res;
		}
		$present = array_flip( array_values( $map['index'] ) );
		if ( ! isset( $present['title'] ) && ! isset( $present['title_id'] ) ) {
			$res['errors'][] = _( "The file needs a Title column (for new records) or a Record ID column (to update records)." );

			return $res;
		}

		// lookups (lower-case name => id)
		$lc      = function ( $s ) { return mb_strtolower( trim( self::txt( $s ) ) ); };
		$formats = $restr = $subjects = $sources = array();
		foreach ( RecordSettings::formats() as $f ) {
			$formats[ $lc( $f[1] ) ] = (int) $f[0];
		}
		foreach ( RecordSettings::restrictions() as $r ) {
			$restr[ $lc( $r[1] ) ] = (int) $r[0];
		}
		$statuses = array();
		foreach ( RecordSettings::statuses() as $name => $s ) {
			$statuses[ $lc( $name ) ] = $name;
		}
		foreach ( $c->query( "SELECT subject_id, subject, shortform FROM subject" )->fetchAll( \PDO::FETCH_ASSOC ) as $s ) {
			$subjects[ $lc( $s['subject'] ) ]   = (int) $s['subject_id'];
			$subjects[ $lc( $s['shortform'] ) ] = $subjects[ $lc( $s['shortform'] ) ] ?? (int) $s['subject_id'];
		}
		foreach ( $c->query( "SELECT source_id, source FROM source" )->fetchAll( \PDO::FETCH_ASSOC ) as $s ) {
			$sources[ $lc( $s['source'] ) ] = (int) $s['source_id'];
		}
		$fields = array();
		foreach ( RecordSettings::fields() as $f ) {
			$fields[ 'cf' . $f['field_id'] ] = $f;
		}
		// existing records for matching
		$byId = $byUrl = $byTitle = array();
		foreach ( $c->query( "SELECT t.title_id, t.title, l.location_id, l.location FROM title t LEFT JOIN location_title lt ON lt.title_id = t.title_id LEFT JOIN location l ON l.location_id = lt.location_id ORDER BY l.location_id" )->fetchAll( \PDO::FETCH_ASSOC ) as $r ) {
			$id = (int) $r['title_id'];
			if ( ! isset( $byId[ $id ] ) ) {
				$byId[ $id ] = array( 'title' => self::txt( $r['title'] ), 'locations' => array() );
			}
			if ( $r['location_id'] !== null ) {
				$byId[ $id ]['locations'][] = (int) $r['location_id'];
				$u = self::normUrl( self::txt( $r['location'] ) );
				if ( $u !== '' ) {
					$byUrl[ $u ][] = $id;
				}
			}
			$byTitle[ $lc( $r['title'] ) ][] = $id;
		}

		$seenInFile = array();
		foreach ( $map['rows'] as $rowNo => $in ) {
			$msg  = array();
			$err  = array();
			$data = array();
			$get  = function ( $k ) use ( $in ) {
				return array_key_exists( $k, $in ) ? $in[ $k ] : null;
			};
			// which record?
			$id     = null;
			$idCell = $get( 'title_id' );
			if ( $idCell !== null && $idCell !== '' ) {
				if ( ! ctype_digit( $idCell ) || ! isset( $byId[ (int) $idCell ] ) ) {
					$err[] = sprintf( _( "Record ID %s does not exist (leave it empty to add a new record)." ), $idCell );
				} else {
					$id = (int) $idCell;
				}
			} elseif ( $match === 'url' && ( $u = self::normUrl( (string) $get( 'url' ) ) ) !== '' && isset( $byUrl[ $u ] ) ) {
				$id = $byUrl[ $u ][0];
				if ( count( array_unique( $byUrl[ $u ] ) ) > 1 ) {
					$msg[] = sprintf( _( "Several records have this URL; updating record %d." ), $id );
				} else {
					$msg[] = _( "Matched an existing record by URL." );
				}
			} elseif ( $match === 'title' && ( $t = $lc( (string) $get( 'title' ) ) ) !== '' && isset( $byTitle[ $t ] ) ) {
				$id    = $byTitle[ $t ][0];
				$msg[] = _( "Matched an existing record by title." );
			}
			$action = $id ? 'update' : 'add';
			if ( ( $action === 'add' && $mode === 'update' ) || ( $action === 'update' && $mode === 'add' ) ) {
				$res['rows'][ $rowNo ] = array( 'action' => 'skip', 'id' => $id, 'title' => (string) ( $get( 'title' ) ?? ( $id ? $byId[ $id ]['title'] : '' ) ),
				                                'messages' => array( $action === 'add' ? _( "New record — skipped (update-only import)." ) : _( "Already exists — skipped (add-only import)." ) ), 'data' => array() );
				$res['counts']['skip']++;
				continue;
			}
			if ( $id && isset( $seenInFile[ $id ] ) ) {
				$err[] = sprintf( _( "Record %1\$d is also changed by row %2\$d." ), $id, $seenInFile[ $id ] );
			}
			$loc = null;
			if ( $id ) {
				$seenInFile[ $id ] = $rowNo;
				$lid               = $get( 'location_id' );
				if ( $lid !== null && $lid !== '' ) {
					if ( ! in_array( (int) $lid, $byId[ $id ]['locations'], true ) ) {
						$err[] = sprintf( _( "Location ID %1\$s does not belong to record %2\$d." ), $lid, $id );
					} else {
						$loc = (int) $lid;
					}
				} else {
					$loc = $byId[ $id ]['locations'][0] ?? null;
				}
			}

			// each column present: validate; '' means clear (if blanks=clear) or leave unchanged
			foreach ( $in as $k => $v ) {
				if ( $k[0] === '#' || in_array( $k, array( 'title_id', 'location_id', 'last_modified' ), true ) ) {
					continue;
				}
				// KBART updates refresh the link and description only; the library's own settings stay
				if ( $action === 'update' && ! empty( $opt['protect'] ) && in_array( $k, (array) $opt['protect'], true ) ) {
					continue;
				}
				if ( $v === '' ) {
					if ( $action === 'update' && $blanks === 'keep' ) {
						continue;
					}
					if ( $action === 'add' ) {
						continue;
					}
				}
				switch ( $k ) {
					case 'title':
						$v = trim( str_replace( '"', '', $v ) );
						if ( $v === '' ) {
							$err[] = _( "Title cannot be empty." );
						}
						$data['title'] = $v;
						break;
					case 'url':
					case 'helpguide':
						if ( $v !== '' && ! preg_match( '#^(https?://|ftp://|mailto:)[^\s<>"]+$#i', $v ) ) {
							$err[] = sprintf( _( "%s must be a web address starting with http:// or https://." ), $k === 'url' ? 'URL' : _( "Help guide" ) );
						}
						$data[ $k ] = $v;
						break;
					case 'format':
					case 'access':
						$list = $k === 'format' ? $formats : $restr;
						if ( $v === '' ) {
							$err[] = sprintf( _( "%s cannot be empty." ), ucfirst( $k ) );
						} elseif ( ! isset( $list[ $lc( $v ) ] ) ) {
							$names = $k === 'format' ? array_map( function ( $f ) { return $f[1]; }, RecordSettings::formats() ) : array_map( function ( $r ) { return $r[1]; }, RecordSettings::restrictions() );
							$err[] = sprintf( _( "Unknown %1\$s \"%2\$s\". Use one of: %3\$s." ), $k === 'format' ? _( "format" ) : _( "access type" ), $v, implode( ', ', $names ) );
						} else {
							$data[ $k ] = $list[ $lc( $v ) ];
						}
						break;
					case 'status':
						if ( $v === '' ) {
							$err[] = _( "Status cannot be empty." );
						} elseif ( ! isset( $statuses[ $lc( $v ) ] ) ) {
							$err[] = sprintf( _( "Unknown status \"%1\$s\". Use one of: %2\$s." ), $v, implode( ', ', $statuses ) );
						} else {
							$data['status'] = $statuses[ $lc( $v ) ];
						}
						break;
					case 'az':
						$b = self::yesNo( $v );
						if ( $b === null ) {
							$err[] = _( "Show in A-Z must be Yes or No." );
						} else {
							$data['az'] = $b ? 'Y' : 'N';
						}
						break;
					case 'subjects':
						$ids = array();
						foreach ( preg_split( '/\s*[;\n|]\s*/', $v ) as $s ) {
							if ( $s === '' ) {
								continue;
							}
							if ( ! isset( $subjects[ $lc( $s ) ] ) ) {
								$err[] = sprintf( _( "Unknown subject \"%s\" (it must match a guide's name or short form)." ), $s );
							} else {
								$ids[] = $subjects[ $lc( $s ) ];
							}
						}
						$data['subjects'] = array_values( array_unique( $ids ) );
						break;
					case 'source':
						if ( $v !== '' && ! isset( $sources[ $lc( $v ) ] ) ) {
							$err[] = sprintf( _( "Unknown subject category \"%s\"." ), $v );
						} elseif ( $v !== '' ) {
							$data['source'] = $sources[ $lc( $v ) ];
						}
						break;
					case 'trial_start':
					case 'trial_end':
						$d = self::dateIn( $v );
						if ( $v !== '' && $d === null ) {
							$err[] = sprintf( _( "\"%s\" is not a date (use YYYY-MM-DD or DD/MM/YYYY)." ), $v );
						} else {
							$data[ $k ] = $d;
						}
						break;
					default:
						if ( isset( $fields[ $k ] ) ) {
							$f = $fields[ $k ];
							if ( $f['type'] === 'date' && $v !== '' ) {
								$d = self::dateIn( $v );
								if ( $d === null ) {
									$err[] = sprintf( _( "%1\$s: \"%2\$s\" is not a date." ), $f['label'], $v );
									break;
								}
								$v = $d;
							}
							if ( ( $f['type'] === 'number' || $f['type'] === 'money' ) && $v !== '' ) {
								$v = str_replace( array( ',', '₹', 'Rs.', 'Rs', 'INR', ' ' ), '', $v );
							}
							if ( $f['type'] === 'checkboxes' ) {
								$parts = $v === '' ? array() : preg_split( '/\s*;\s*/', $v );
								$opts  = RecordSettings::fieldOptions( $f );
								$fixed = array();
								foreach ( $parts as $p ) {
									$hit = null;
									foreach ( $opts as $o ) {
										if ( mb_strtolower( $o ) === mb_strtolower( $p ) ) {
											$hit = $o;
										}
									}
									if ( $hit === null ) {
										$err[] = sprintf( _( "%1\$s: \"%2\$s\" is not one of the choices (%3\$s)." ), $f['label'], $p, implode( ', ', $opts ) );
									} else {
										$fixed[] = $hit;
									}
								}
								$data['cf'][ (int) $f['field_id'] ] = $fixed;
								break;
							}
							if ( $f['type'] === 'select' && $v !== '' ) {
								foreach ( RecordSettings::fieldOptions( $f ) as $o ) {
									if ( mb_strtolower( $o ) === mb_strtolower( $v ) ) {
										$v = $o;
									}
								}
							}
							RecordFields::clean( $f, $v, $e );
							if ( $e ) {
								$err[] = $f['label'] . ': ' . $e;
							} else {
								$data['cf'][ (int) $f['field_id'] ] = $v;
							}
						} else {
							$data[ $k ] = $v; // plain text columns
						}
				}
			}
			if ( $action === 'add' ) {
				if ( empty( $data['title'] ) ) {
					$err[] = _( "A new record needs a Title." );
				}
				$data += array( 'format' => $formats[ $lc( 'Web' ) ] ?? ( reset( $formats ) ?: 1 ), 'access' => $restr[ $lc( 'Restricted' ) ] ?? ( reset( $restr ) ?: 1 ) );
				if ( ! isset( $data['status'] ) ) {
					$data['status'] = RecordSettings::initialStatus();
					$msg[]          = sprintf( _( "Status will be \"%s\" (the starting status)." ), $data['status'] );
				}
				if ( ! isset( $data['az'] ) ) {
					$data['az'] = 'Y';
				}
				foreach ( $fields as $f ) {
					if ( (int) $f['required'] === 1 && empty( $data['cf'][ (int) $f['field_id'] ] ) ) {
						$msg[] = sprintf( _( "Required field \"%s\" is empty." ), $f['label'] );
					}
				}
			} elseif ( ! $loc && array_intersect_key( $data, array_flip( array( 'url', 'format', 'access', 'status', 'az', 'display_note', 'helpguide', 'call_number', 'ctags', 'trial_start', 'trial_end' ) ) ) ) {
				$msg[] = _( "This record had no location; one will be created." );
			}
			if ( $action === 'update' && ! $data && ! $err ) {
				$res['rows'][ $rowNo ] = array( 'action' => 'skip', 'id' => $id, 'title' => $byId[ $id ]['title'], 'messages' => array( _( "Nothing to change." ) ), 'data' => array() );
				$res['counts']['skip']++;
				continue;
			}
			$a = $err ? 'error' : $action;
			$res['rows'][ $rowNo ] = array( 'action' => $a, 'id' => $id, 'location_id' => $loc, 'title' => $data['title'] ?? ( $id ? $byId[ $id ]['title'] : '' ),
			                                'messages' => array_merge( $err, $msg ), 'data' => $data );
			$res['counts'][ $a ]++;
		}

		return $res;
	}

	/** Writes the rows that passed the checks. Returns counts and per-row messages. */
	public static function apply( array $plan, $staffId, $fileName ) {
		$c    = self::conn();
		$done = array( 'add' => 0, 'update' => 0, 'failed' => 0, 'messages' => array() );
		foreach ( $plan['rows'] as $rowNo => $p ) {
			if ( $p['action'] !== 'add' && $p['action'] !== 'update' ) {
				continue;
			}
			$c->beginTransaction();
			try {
				$id = self::writeRow( $c, $p, $staffId, $fileName );
				$c->commit();
				$done[ $p['action'] ]++;
				if ( $p['action'] === 'add' ) {
					$done['messages'][ $rowNo ] = sprintf( _( "Added as record %d." ), $id );
				}
			} catch ( \Throwable $e ) {
				$c->rollBack();
				$done['failed']++;
				$done['messages'][ $rowNo ] = _( "Could not be saved:" ) . ' ' . $e->getMessage();
				error_log( 'SubjectsPlus import row ' . $rowNo . ': ' . $e->getMessage() );
			}
		}
		RecordSettings::reset();

		return $done;
	}

	private static function x( \PDO $c, $sql, array $params ) {
		$st = $c->prepare( $sql );
		if ( ! $st || ! $st->execute( $params ) ) {
			$e = $st ? $st->errorInfo() : $c->errorInfo();
			throw new \RuntimeException( _( "database error" ) . ' (' . ( $e[2] ?? '' ) . ')' );
		}

		return $st;
	}

	/** The record's current values, in the same form as the export (so unchanged cells can be recognised). */
	private static function current( \PDO $c, $id, $loc ) {
		$hasVendor = \SubjectsPlus\Control\RecordVendor::ready();
		$st = self::x( $c, "SELECT t.title, t.alternate_title, t.pre, " . ( $hasVendor ? "t.vendor, " : "" ) . "t.description, t.internal_notes, l.location, l.format, l.access_restrictions,
				l.record_status, l.eres_display, l.eres_display_saved, l.display_note, l.helpguide, l.call_number, l.ctags, l.trial_start, l.trial_end
			FROM title t LEFT JOIN location l ON l.location_id = ? WHERE t.title_id = ?", array( (int) $loc, (int) $id ) );
		$r = $st->fetch( \PDO::FETCH_ASSOC );
		if ( ! $r ) {
			return array();
		}
		$date = function ( $v ) {
			return $v && strpos( (string) $v, '0000' ) !== 0 ? substr( (string) $v, 0, 10 ) : null;
		};
		$cur = array(
			'title' => self::txt( $r['title'] ), 'alternate' => self::txt( $r['alternate_title'] ), 'prefix' => self::txt( $r['pre'] ),
			'description' => (string) $r['description'], 'internal_notes' => (string) $r['internal_notes'],
		);
		if ( $hasVendor ) {
			$cur['vendor'] = (string) $r['vendor'];
		}
		if ( $loc ) {
			$cur += array(
				'url' => self::txt( $r['location'] ), 'format' => (int) $r['format'], 'access' => (int) $r['access_restrictions'],
				'status' => (string) $r['record_status'], 'az' => ( $r['eres_display'] === 'Y' || $r['eres_display_saved'] === 'Y' ) ? 'Y' : 'N',
				'display_note' => (string) $r['display_note'], 'helpguide' => self::txt( $r['helpguide'] ), 'call_number' => self::txt( $r['call_number'] ),
				'ctags' => self::txt( $r['ctags'] ), 'trial_start' => $date( $r['trial_start'] ), 'trial_end' => $date( $r['trial_end'] ),
			);
		}
		$subj = self::x( $c, "SELECT subject_id, source_id FROM `rank` WHERE title_id = ? ORDER BY rank_id", array( (int) $id ) )->fetchAll( \PDO::FETCH_ASSOC );
		$ids  = array_map( 'intval', array_column( $subj, 'subject_id' ) );
		sort( $ids );
		$cur['subjects'] = $ids;
		$cur['source']   = $subj ? (int) $subj[0]['source_id'] : null;

		return $cur;
	}

	private static function writeRow( \PDO $c, array $p, $staffId, $fileName ) {
		$d   = $p['data'];
		$id  = $p['id'];
		if ( $p['action'] === 'update' ) {
			// leave alone whatever the file did not change, so a re-imported export is a no-op
			$cur = self::current( $c, $id, $p['location_id'] ?? null );
			foreach ( $d as $k => $v ) {
				if ( ! array_key_exists( $k, $cur ) ) {
					continue;
				}
				$a = $v;
				$b = $cur[ $k ];
				if ( $k === 'subjects' ) {
					sort( $a );
				}
				if ( $k === 'source' && $b === null ) {
					continue;
				}
				if ( is_array( $a ) ? $a === $b : (string) $a === (string) $b ) {
					unset( $d[ $k ] );
				}
			}
			if ( ! $d ) {
				return $id; // nothing to change
			}
		}
		$enc = function ( $s ) { // the record form stores these fields HTML-escaped
			return htmlspecialchars( strip_tags( self::bmp( $s ) ), ENT_QUOTES );
		};
		$html = function ( $s ) { // safe HTML; keep non-Latin-script and other non-ASCII text readable (no &#NNNN;)
			return mb_decode_numericentity( sp_purifier()->purify( self::bmp( $s ) ), array( 0x80, 0x10FFFF, 0, 0x1FFFFF ), 'UTF-8' );
		};
		$t = array();
		foreach ( array( 'title' => $enc, 'alternate' => $enc, 'prefix' => $enc, 'description' => $html, 'internal_notes' => $html ) as $k => $fn ) {
			if ( array_key_exists( $k, $d ) ) {
				$t[ array( 'title' => 'title', 'alternate' => 'alternate_title', 'prefix' => 'pre', 'description' => 'description', 'internal_notes' => 'internal_notes' )[ $k ] ] = $fn( $d[ $k ] );
			}
		}
		if ( array_key_exists( 'vendor', $d ) && \SubjectsPlus\Control\RecordVendor::ready() ) {
			$t['vendor'] = \SubjectsPlus\Control\RecordVendor::clean( self::bmp( $d['vendor'] ) );
			$t['vendor'] = $t['vendor'] === '' ? null : $t['vendor'];
		}
		if ( $p['action'] === 'add' ) {
			$t += array( 'alternate_title' => '', 'description' => '', 'internal_notes' => '', 'pre' => '' );
			self::x( $c, "INSERT INTO title (" . implode( ',', array_keys( $t ) ) . ", last_modified_by) VALUES (" . implode( ',', array_fill( 0, count( $t ), '?' ) ) . ", ?)",
				array_merge( array_values( $t ), array( (string) $staffId ) ) );
			$id = (int) $c->lastInsertId();
		} elseif ( $t ) {
			$set = implode( ', ', array_map( function ( $k ) { return "$k = ?"; }, array_keys( $t ) ) );
			self::x( $c, "UPDATE title SET $set, last_modified_by = ?, last_modified = NOW() WHERE title_id = ?", array_merge( array_values( $t ), array( (string) $staffId, $id ) ) );
		} else {
			self::x( $c, "UPDATE title SET last_modified_by = ?, last_modified = NOW() WHERE title_id = ?", array( (string) $staffId, $id ) );
		}

		// location
		$l = array();
		foreach ( array( 'url' => 'location', 'format' => 'format', 'access' => 'access_restrictions', 'az' => 'eres_display', 'display_note' => 'display_note',
		                 'helpguide' => 'helpguide', 'call_number' => 'call_number', 'ctags' => 'ctags', 'trial_start' => 'trial_start', 'trial_end' => 'trial_end' ) as $k => $col ) {
			if ( array_key_exists( $k, $d ) ) {
				$v = $d[ $k ];
				if ( in_array( $k, array( 'url', 'helpguide', 'call_number', 'ctags' ), true ) ) {
					$v = $enc( $v );
				} elseif ( $k === 'display_note' ) {
					$v = $html( $v );
				}
				$l[ $col ] = $v;
			}
		}
		$oldStatus = $id && $p['action'] === 'update' ? Workflow::currentStatus( $id ) : null;
		$loc       = $p['location_id'] ?? null;
		$touchLoc  = $l || isset( $d['status'] );
		if ( ! $loc && $touchLoc ) {
			$l += array( 'location' => '', 'format' => 1, 'access_restrictions' => 1, 'eres_display' => 'Y', 'display_note' => '', 'call_number' => '', 'ctags' => '', 'helpguide' => '' );
			$l['record_status'] = $d['status'] ?? RecordSettings::initialStatus();
			self::x( $c, "INSERT INTO location (" . implode( ',', array_keys( $l ) ) . ") VALUES (" . implode( ',', array_fill( 0, count( $l ), '?' ) ) . ")", array_values( $l ) );
			$loc = (int) $c->lastInsertId();
			self::x( $c, "INSERT INTO location_title (title_id, location_id) VALUES (?, ?)", array( $id, $loc ) );
		} elseif ( $l ) {
			$set = implode( ', ', array_map( function ( $k ) { return "$k = ?"; }, array_keys( $l ) ) );
			self::x( $c, "UPDATE location SET $set WHERE location_id = ?", array_merge( array_values( $l ), array( $loc ) ) );
		}
		if ( isset( $d['status'] ) && $p['action'] === 'update' && $d['status'] !== $oldStatus ) {
			self::x( $c, "UPDATE location l JOIN location_title lt ON lt.location_id = l.location_id SET l.record_status = ? WHERE lt.title_id = ?", array( $d['status'], $id ) );
		}
		if ( RecordSettings::ready() && ( isset( $d['status'] ) || isset( $d['az'] ) || $p['action'] === 'add' ) ) {
			Workflow::syncVisibility( $id, isset( $d['az'] ) );
			$new = Workflow::currentStatus( $id );
			if ( $p['action'] === 'add' || $new !== $oldStatus ) {
				Workflow::addHistory( $id, $p['action'] === 'add' ? null : $oldStatus, $new, $staffId, 'import', sprintf( _( "Imported from %s" ), $fileName ) );
			}
		}

		// subjects: keep existing rows (and their notes/order) for subjects still listed
		if ( array_key_exists( 'subjects', $d ) ) {
			$have = array();
			foreach ( self::x( $c, "SELECT rank_id, subject_id, source_id FROM `rank` WHERE title_id = ?", array( $id ) )->fetchAll( \PDO::FETCH_ASSOC ) as $r ) {
				$have[ (int) $r['subject_id'] ] = $r;
			}
			$src = $d['source'] ?? null;
			if ( $src === null ) {
				$first = reset( $have );
				$src   = $first ? (int) $first['source_id'] : (int) self::x( $c, "SELECT source_id FROM source ORDER BY rs, source_id LIMIT 1", array() )->fetchColumn();
			}
			foreach ( $have as $sid => $r ) {
				if ( ! in_array( $sid, $d['subjects'], true ) ) {
					self::x( $c, "DELETE FROM `rank` WHERE rank_id = ?", array( $r['rank_id'] ) );
				} elseif ( isset( $d['source'] ) && (int) $r['source_id'] !== (int) $d['source'] ) {
					self::x( $c, "UPDATE `rank` SET source_id = ? WHERE rank_id = ?", array( $d['source'], $r['rank_id'] ) );
				}
			}
			foreach ( $d['subjects'] as $sid ) {
				if ( ! isset( $have[ $sid ] ) ) {
					self::x( $c, "INSERT INTO `rank` (`rank`, subject_id, title_id, source_id, dbbysub_active, description_override) VALUES (0, ?, ?, ?, 1, '')", array( $sid, $id, $src ) );
				}
			}
		} elseif ( isset( $d['source'] ) ) {
			self::x( $c, "UPDATE `rank` SET source_id = ? WHERE title_id = ?", array( $d['source'], $id ) );
		}

		// custom fields
		if ( ! empty( $d['cf'] ) ) {
			$oldValues = $p['action'] === 'update' ? RecordFields::values( $id ) : array();
			$set = $c->prepare( "INSERT INTO record_field_value (title_id, field_id, value) VALUES (?,?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)" );
			$del = $c->prepare( "DELETE FROM record_field_value WHERE title_id = ? AND field_id = ?" );
			foreach ( RecordSettings::fields() as $f ) {
				$fid = (int) $f['field_id'];
				if ( ! array_key_exists( $fid, $d['cf'] ) ) {
					continue;
				}
				$v   = self::bmp( RecordFields::clean( $f, $d['cf'][ $fid ], $e ) );
				$old = $oldValues[ $fid ] ?? '';
				if ( $v === $old || ( in_array( $f['type'], array( 'number', 'money' ), true ) && is_numeric( $v ) && is_numeric( $old ) && (float) $v === (float) $old ) ) {
					continue; // unchanged (e.g. 125000.5 coming back for 125000.50)
				}
				if ( $v === '' || $v === '[]' ) {
					$del->execute( array( $id, $fid ) );
				} else {
					$set->execute( array( $id, $fid, $v ) );
				}
			}
		}

		$title = $d['title'] ?? Workflow::recordTitle( $id );
		if ( function_exists( 'changeMe' ) && $staffId ) {
			changeMe( 'record', $p['action'] === 'add' ? 'insert' : 'update', $id, str_replace( '"', '', $title ), (int) $staffId );
		}

		return $id;
	}

	/* =================================================================== helpers */

	public static function normUrl( $u ) {
		$u = mb_strtolower( trim( (string) $u ) );
		if ( $u === '' ) {
			return '';
		}
		$u = preg_replace( '#^https?://(www\.)?#', '', $u );

		return rtrim( $u, '/' );
	}

	public static function yesNo( $v ) {
		$v = mb_strtolower( trim( (string) $v ) );
		if ( in_array( $v, array( 'y', 'yes', '1', 'true', 'show', 'shown' ), true ) ) {
			return true;
		}
		if ( in_array( $v, array( 'n', 'no', '0', 'false', 'hide', 'hidden' ), true ) ) {
			return false;
		}

		return null;
	}

	/** YYYY-MM-DD, DD/MM/YYYY, DD-MM-YYYY, DD.MM.YYYY, "30 Nov 2026" → YYYY-MM-DD (null if not a date). */
	public static function dateIn( $v ) {
		$v = trim( (string) $v );
		if ( $v === '' ) {
			return null;
		}
		if ( preg_match( '/^(\d{4})-(\d{1,2})-(\d{1,2})(?:[ T].*)?$/', $v, $m ) ) {
			list( , $y, $mo, $d ) = $m;
		} elseif ( preg_match( '#^(\d{1,2})[/.-](\d{1,2})[/.-](\d{4})$#', $v, $m ) ) {
			list( , $d, $mo, $y ) = $m; // day-first order (DD/MM/YYYY)
		} elseif ( preg_match( '/^\d+(\.\d+)?$/', $v ) && (float) $v > 20000 && (float) $v < 80000 ) {
			return ( new \DateTime( '1899-12-30' ) )->modify( '+' . (int) $v . ' days' )->format( 'Y-m-d' ); // Excel serial in a CSV
		} elseif ( ( $ts = strtotime( $v ) ) !== false && preg_match( '/[a-z]{3}/i', $v ) ) {
			return date( 'Y-m-d', $ts );
		} else {
			return null;
		}

		return checkdate( (int) $mo, (int) $d, (int) $y ) ? sprintf( '%04d-%02d-%02d', $y, $mo, $d ) : null;
	}
}
