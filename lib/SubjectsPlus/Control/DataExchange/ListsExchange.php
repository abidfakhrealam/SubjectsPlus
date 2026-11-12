<?php
namespace SubjectsPlus\Control\DataExchange;

use SubjectsPlus\Control\Querier;
use SubjectsPlus\Control\Spreadsheet;

/**
 * export guides, staff and FAQs; bulk import of staff and FAQs.
 * Imports follow the same pattern as records: plan() checks every row, apply() writes.
 */
class ListsExchange {

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

	/** prepare + execute, throwing on failure (the shared connection does not throw by itself) */
	private static function run( \PDO $c, $sql, array $params ) {
		$st = $c->prepare( $sql );
		if ( ! $st || ! $st->execute( $params ) ) {
			$e = $st ? $st->errorInfo() : $c->errorInfo();
			throw new \RuntimeException( 'database error (' . ( $e[2] ?? '' ) . ')' );
		}

		return $st;
	}

	private static function lc( $s ) {
		return mb_strtolower( trim( self::txt( $s ) ) );
	}

	/* ================================================================ guides */

	public static function guideSheets() {
		global $PublicPath;
		$c      = self::conn();
		$owners = $tabs = $boxes = array();
		foreach ( $c->query( "SELECT ss.subject_id, CONCAT(s.fname, ' ', s.lname) AS n, s.email FROM staff_subject ss JOIN staff s ON s.staff_id = ss.staff_id ORDER BY ss.staff_guide_order, s.lname" )->fetchAll( \PDO::FETCH_ASSOC ) as $r ) {
			$owners[ (int) $r['subject_id'] ][] = trim( self::txt( $r['n'] ) ) . ' <' . $r['email'] . '>';
		}
		foreach ( $c->query( "SELECT subject_id, COUNT(*) FROM tab GROUP BY subject_id" )->fetchAll( \PDO::FETCH_KEY_PAIR ) as $id => $n ) {
			$tabs[ (int) $id ] = (int) $n;
		}
		foreach ( $c->query( "SELECT t.subject_id, COUNT(DISTINCT ps.pluslet_id) FROM tab t JOIN section s ON s.tab_id = t.tab_id JOIN pluslet_section ps ON ps.section_id = s.section_id GROUP BY t.subject_id" )->fetchAll( \PDO::FETCH_KEY_PAIR ) as $id => $n ) {
			$boxes[ (int) $id ] = (int) $n;
		}
		$vis  = array( 0 => 'Hidden', 1 => 'Public', 2 => 'Suppressed' );
		$rows = array( array( 'Guide ID', 'Guide', 'Short form', 'Type', 'Visibility', 'Owners', 'Tabs', 'Boxes', 'Last modified', 'Public address', 'Description', 'Keywords', 'Tab style' ) );
		foreach ( $c->query( "SELECT * FROM subject ORDER BY subject" )->fetchAll( \PDO::FETCH_ASSOC ) as $g ) {
			$id    = (int) $g['subject_id'];
			$extra = json_decode( (string) $g['extra'], true );
			$rows[] = array(
				$id, self::txt( $g['subject'] ), $g['shortform'], $g['type'], $vis[ (int) $g['active'] ] ?? (string) $g['active'],
				implode( '; ', $owners[ $id ] ?? array() ), $tabs[ $id ] ?? 0, $boxes[ $id ] ?? 0, (string) $g['last_modified'],
				$PublicPath . 'guide.php?subject=' . rawurlencode( $g['shortform'] ),
				self::txt( $g['description'] ), self::txt( $g['keywords'] ),
				is_array( $extra ) && ! empty( $extra['tabstyle'] ) ? $extra['tabstyle'] : 'library default',
			);
		}

		// every link inside guide boxes (useful for checking and for accreditation evidence)
		$links = array( array( 'Guide', 'Tab', 'Box', 'Link text', 'Address' ) );
		$st    = $c->query( "SELECT su.subject, t.label, p.title, p.body FROM pluslet p
			JOIN pluslet_section ps ON ps.pluslet_id = p.pluslet_id JOIN section s ON s.section_id = ps.section_id
			JOIN tab t ON t.tab_id = s.tab_id JOIN subject su ON su.subject_id = t.subject_id
			ORDER BY su.subject, t.tab_index, s.section_index, ps.pcolumn, ps.prow" );
		foreach ( $st->fetchAll( \PDO::FETCH_ASSOC ) as $p ) {
			if ( stripos( (string) $p['body'], '<a' ) === false ) {
				continue;
			}
			if ( preg_match_all( '#<a\s[^>]*href\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)</a>#is', (string) $p['body'], $m, PREG_SET_ORDER ) ) {
				foreach ( $m as $a ) {
					$href = self::txt( $a[2] );
					if ( $href === '' || $href[0] === '#' || stripos( $href, 'javascript:' ) === 0 ) {
						continue;
					}
					$links[] = array( self::txt( $p['subject'] ), self::txt( $p['label'] ), self::txt( $p['title'] ), trim( self::txt( strip_tags( $a[3] ) ) ), $href );
				}
			}
		}

		return array( array( 'name' => 'Guides', 'rows' => $rows ), array( 'name' => 'Links in guides', 'rows' => $links ) );
	}

	/* ================================================================ staff */

	public static function staffColumns() {
		return array(
			'staff_id'   => array( 'Staff ID' ),
			'fname'      => array( 'First name', 'Given name' ),
			'lname'      => array( 'Last name', 'Surname', 'Family name' ),
			'email'      => array( 'Email', 'E-mail', 'Email address' ),
			'title'      => array( 'Position', 'Job title', 'Title', 'Designation' ),
			'department' => array( 'Department' ),
			'tel'        => array( 'Phone', 'Telephone', 'Extension' ),
			'room'       => array( 'Room', 'Room number', 'Office' ),
			'active'     => array( 'Active' ),
			'roles'      => array( 'Roles', 'Permissions', 'ptags' ),
			'guides'     => array( 'Guides', 'Guides owned' ),
		);
	}

	public static function staffRows() {
		$c      = self::conn();
		$guides = array();
		foreach ( $c->query( "SELECT ss.staff_id, su.subject FROM staff_subject ss JOIN subject su ON su.subject_id = ss.subject_id ORDER BY su.subject" )->fetchAll( \PDO::FETCH_ASSOC ) as $r ) {
			$guides[ (int) $r['staff_id'] ][] = self::txt( $r['subject'] );
		}
		$rows = array( array_map( function ( $n ) { return $n[0]; }, self::staffColumns() ) );
		// personal data (home address, emergency contacts, ...) is deliberately not exported
		foreach ( $c->query( "SELECT s.*, d.name AS dept FROM staff s LEFT JOIN department d ON d.department_id = s.department_id WHERE s.user_type_id = 1 OR s.user_type_id IS NULL ORDER BY s.lname, s.fname" )->fetchAll( \PDO::FETCH_ASSOC ) as $s ) {
			$rows[] = array(
				(int) $s['staff_id'], self::txt( $s['fname'] ), self::txt( $s['lname'] ), $s['email'], self::txt( $s['title'] ), self::txt( $s['dept'] ),
				self::txt( $s['tel'] ), self::txt( $s['room_number'] ), (int) $s['active'] === 1 ? 'Yes' : 'No',
				implode( '; ', array_filter( explode( '|', (string) $s['ptags'] ) ) ), implode( '; ', $guides[ (int) $s['staff_id'] ] ?? array() ),
			);
		}

		return $rows;
	}

	public static function staffPlan( array $sheetRows, array $opt ) {
		global $all_ptags;
		$c     = self::conn();
		$mode  = $opt['mode'] ?? 'both';
		$map   = Spreadsheet::mapHeader( $sheetRows, self::staffColumns() );
		$res   = array( 'rows' => array(), 'errors' => array(), 'unknown' => $map['unknown'], 'counts' => array( 'add' => 0, 'update' => 0, 'skip' => 0, 'error' => 0 ) );
		if ( ! in_array( 'email', $map['index'], true ) ) {
			$res['errors'][] = _( "The file needs an Email column (staff are matched by email address)." );

			return $res;
		}
		$byEmail = $depts = array();
		foreach ( $c->query( "SELECT staff_id, email FROM staff" )->fetchAll( \PDO::FETCH_ASSOC ) as $s ) {
			$byEmail[ mb_strtolower( trim( $s['email'] ) ) ] = (int) $s['staff_id'];
		}
		foreach ( $c->query( "SELECT department_id, name FROM department" )->fetchAll( \PDO::FETCH_ASSOC ) as $d ) {
			$depts[ self::lc( $d['name'] ) ] = (int) $d['department_id'];
		}
		$ptags = array_map( 'strtolower', (array) ( $all_ptags ?? array() ) );
		$seen  = array();
		foreach ( $map['rows'] as $rowNo => $in ) {
			$err   = $msg = array();
			$data  = array();
			$email = mb_strtolower( trim( $in['email'] ?? '' ) );
			if ( ! filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
				$err[] = sprintf( _( "\"%s\" is not a valid email address." ), $in['email'] ?? '' );
			}
			if ( isset( $seen[ $email ] ) ) {
				$err[] = sprintf( _( "This email also appears in row %d." ), $seen[ $email ] );
			}
			$seen[ $email ] = $rowNo;
			$id             = $byEmail[ $email ] ?? null;
			$action         = $id ? 'update' : 'add';
			if ( ( $action === 'add' && $mode === 'update' ) || ( $action === 'update' && $mode === 'add' ) ) {
				$res['rows'][ $rowNo ] = array( 'action' => 'skip', 'id' => $id, 'title' => $email, 'messages' => array( _( "Skipped (import mode)." ) ), 'data' => array() );
				$res['counts']['skip']++;
				continue;
			}
			foreach ( $in as $k => $v ) {
				if ( $k[0] === '#' || $k === 'staff_id' || $k === 'email' || $k === 'guides' || ( $v === '' && $action === 'update' ) ) {
					continue;
				}
				switch ( $k ) {
					case 'department':
						if ( $v !== '' && ! isset( $depts[ self::lc( $v ) ] ) ) {
							$err[] = sprintf( _( "Unknown department \"%s\" (add it under Admin → Departments first)." ), $v );
						} elseif ( $v !== '' ) {
							$data['department_id'] = $depts[ self::lc( $v ) ];
						}
						break;
					case 'active':
						$b = RecordsExchange::yesNo( $v );
						if ( $b === null ) {
							$err[] = _( "Active must be Yes or No." );
						} else {
							$data['active'] = $b ? 1 : 0;
						}
						break;
					case 'roles':
						$roles = array();
						foreach ( preg_split( '/\s*[;,|]\s*/', mb_strtolower( $v ) ) as $r ) {
							if ( $r === '' ) {
								continue;
							}
							if ( ! in_array( $r, $ptags, true ) ) {
								$err[] = sprintf( _( "Unknown role \"%1\$s\". Use: %2\$s." ), $r, implode( ', ', $ptags ) );
							} else {
								$roles[] = $r;
							}
						}
						$data['ptags'] = implode( '|', array_unique( $roles ) );
						break;
					case 'room':
						$data['room_number'] = $v;
						break;
					default:
						$data[ $k ] = $v;
				}
			}
			if ( $action === 'add' ) {
				if ( ( $data['fname'] ?? '' ) === '' && ( $data['lname'] ?? '' ) === '' ) {
					$err[] = _( "A new staff member needs a first or last name." );
				}
				$data += array( 'active' => 1, 'ptags' => 'records' );
				$msg[] = _( "New account: no password is set. They sign in with the university's single sign-on, or an admin sets a password." );
			}
			if ( $action === 'update' && ! $data && ! $err ) {
				$res['rows'][ $rowNo ] = array( 'action' => 'skip', 'id' => $id, 'title' => $email, 'messages' => array( _( "Nothing to change." ) ), 'data' => array() );
				$res['counts']['skip']++;
				continue;
			}
			$data['email'] = $email;
			$a             = $err ? 'error' : $action;
			$res['rows'][ $rowNo ] = array( 'action' => $a, 'id' => $id, 'title' => trim( ( $data['fname'] ?? '' ) . ' ' . ( $data['lname'] ?? '' ) ) ?: $email, 'messages' => array_merge( $err, $msg ), 'data' => $data );
			$res['counts'][ $a ]++;
		}

		return $res;
	}

	public static function staffApply( array $plan, $staffId ) {
		$c    = self::conn();
		$done = array( 'add' => 0, 'update' => 0, 'failed' => 0, 'messages' => array() );
		$root = dirname( dirname( dirname( dirname( __DIR__ ) ) ) );
		foreach ( $plan['rows'] as $rowNo => $p ) {
			if ( $p['action'] !== 'add' && $p['action'] !== 'update' ) {
				continue;
			}
			$d = $p['data'];
			$v = array();
			foreach ( array( 'fname', 'lname', 'title', 'tel', 'room_number' ) as $k ) {
				if ( array_key_exists( $k, $d ) ) {
					$v[ $k ] = htmlspecialchars( strip_tags( self::bmp( $d[ $k ] ) ), ENT_QUOTES ); // same as the staff form
				}
			}
			foreach ( array( 'department_id', 'active', 'ptags' ) as $k ) {
				if ( array_key_exists( $k, $d ) ) {
					$v[ $k ] = $d[ $k ];
				}
			}
			try {
				$c->beginTransaction();
				if ( $p['action'] === 'add' ) {
					$v += array( 'fname' => '', 'lname' => '', 'title' => '', 'tel' => '', 'department_id' => null, 'staff_sort' => 0, 'user_type_id' => 1,
					             'password' => password_hash( bin2hex( random_bytes( 24 ) ), PASSWORD_DEFAULT ), 'bio' => '', 'email' => $d['email'] );
					$st = $c->prepare( "INSERT INTO staff (" . implode( ',', array_keys( $v ) ) . ") VALUES (" . implode( ',', array_fill( 0, count( $v ), '?' ) ) . ")" );
					if ( ! $st->execute( array_values( $v ) ) ) {
						throw new \RuntimeException( (string) ( $st->errorInfo()[2] ?? 'database error' ) );
					}
					$id = (int) $c->lastInsertId();
					if ( ! empty( $v['department_id'] ) ) {
						self::run( $c, "INSERT INTO staff_department (staff_id, department_id) VALUES (?, ?)", array( $id, $v['department_id'] ) );
					}
					$dir = $root . '/assets/users/_' . preg_replace( '/[^A-Za-z0-9._-]/', '', strstr( $d['email'], '@', true ) );
					if ( ! is_dir( $dir ) && @mkdir( $dir, 0755, true ) ) {
						@copy( $root . '/assets/images/placeholder-image.jpg', $dir . '/headshot.jpg' );
						@copy( $root . '/assets/images/placeholder-image_large.jpg', $dir . '/headshot_large.jpg' );
					}
					$done['messages'][ $rowNo ] = sprintf( _( "Added (staff ID %d)." ), $id );
				} else {
					$id = (int) $p['id'];
					if ( $v ) {
						$set = implode( ', ', array_map( function ( $k ) { return "$k = ?"; }, array_keys( $v ) ) );
						$st  = $c->prepare( "UPDATE staff SET $set WHERE staff_id = ?" );
						if ( ! $st->execute( array_merge( array_values( $v ), array( $id ) ) ) ) {
							throw new \RuntimeException( (string) ( $st->errorInfo()[2] ?? 'database error' ) );
						}
					}
					if ( array_key_exists( 'department_id', $v ) ) {
						self::run( $c, "DELETE FROM staff_department WHERE staff_id = ?", array( $id ) );
						self::run( $c, "INSERT INTO staff_department (staff_id, department_id) VALUES (?, ?)", array( $id, $v['department_id'] ) );
					}
				}
				$c->commit();
				$done[ $p['action'] ]++;
			} catch ( \Throwable $e ) {
				if ( $c->inTransaction() ) {
					$c->rollBack();
				}
				$done['failed']++;
				$done['messages'][ $rowNo ] = _( "Could not be saved:" ) . ' ' . $e->getMessage();
			}
		}

		return $done;
	}

	/* ================================================================ FAQs */

	public static function faqColumns() {
		return array(
			'faq_id'      => array( 'FAQ ID', 'ID' ),
			'question'    => array( 'Question' ),
			'answer'      => array( 'Answer' ),
			'keywords'    => array( 'Keywords' ),
			'subjects'    => array( 'Subjects', 'Guides' ),
			'collections' => array( 'Collections', 'FAQ collections' ),
		);
	}

	public static function faqRows() {
		$c    = self::conn();
		$subs = $cols = array();
		foreach ( $c->query( "SELECT fs.faq_id, s.subject FROM faq_subject fs JOIN subject s ON s.subject_id = fs.subject_id ORDER BY s.subject" )->fetchAll( \PDO::FETCH_ASSOC ) as $r ) {
			$subs[ (int) $r['faq_id'] ][] = self::txt( $r['subject'] );
		}
		foreach ( $c->query( "SELECT ff.faq_id, p.name FROM faq_faqpage ff JOIN faqpage p ON p.faqpage_id = ff.faqpage_id ORDER BY p.name" )->fetchAll( \PDO::FETCH_ASSOC ) as $r ) {
			$cols[ (int) $r['faq_id'] ][] = self::txt( $r['name'] );
		}
		$rows = array( array_map( function ( $n ) { return $n[0]; }, self::faqColumns() ) );
		foreach ( $c->query( "SELECT * FROM faq ORDER BY question" )->fetchAll( \PDO::FETCH_ASSOC ) as $f ) {
			$id     = (int) $f['faq_id'];
			$rows[] = array( $id, self::txt( $f['question'] ), (string) $f['answer'], self::txt( $f['keywords'] ),
			                 implode( '; ', $subs[ $id ] ?? array() ), implode( '; ', $cols[ $id ] ?? array() ) );
		}

		return $rows;
	}

	public static function faqPlan( array $sheetRows, array $opt ) {
		$c     = self::conn();
		$mode  = $opt['mode'] ?? 'both';
		$map   = Spreadsheet::mapHeader( $sheetRows, self::faqColumns() );
		$res   = array( 'rows' => array(), 'errors' => array(), 'unknown' => $map['unknown'], 'counts' => array( 'add' => 0, 'update' => 0, 'skip' => 0, 'error' => 0 ) );
		if ( ! in_array( 'question', $map['index'], true ) && ! in_array( 'faq_id', $map['index'], true ) ) {
			$res['errors'][] = _( "The file needs a Question column." );

			return $res;
		}
		$byId = $byQ = $subjects = $colls = array();
		foreach ( $c->query( "SELECT faq_id, question FROM faq" )->fetchAll( \PDO::FETCH_ASSOC ) as $f ) {
			$byId[ (int) $f['faq_id'] ] = self::txt( $f['question'] );
			$byQ[ self::lc( $f['question'] ) ] = (int) $f['faq_id'];
		}
		foreach ( $c->query( "SELECT subject_id, subject, shortform FROM subject" )->fetchAll( \PDO::FETCH_ASSOC ) as $s ) {
			$subjects[ self::lc( $s['subject'] ) ] = (int) $s['subject_id'];
			$subjects[ self::lc( $s['shortform'] ) ] = $subjects[ self::lc( $s['shortform'] ) ] ?? (int) $s['subject_id'];
		}
		foreach ( $c->query( "SELECT faqpage_id, name FROM faqpage" )->fetchAll( \PDO::FETCH_ASSOC ) as $p ) {
			$colls[ self::lc( $p['name'] ) ] = (int) $p['faqpage_id'];
		}
		foreach ( $map['rows'] as $rowNo => $in ) {
			$err  = $msg = array();
			$data = array();
			$id   = null;
			if ( ( $in['faq_id'] ?? '' ) !== '' ) {
				if ( ! isset( $byId[ (int) $in['faq_id'] ] ) ) {
					$err[] = sprintf( _( "FAQ ID %s does not exist." ), $in['faq_id'] );
				} else {
					$id = (int) $in['faq_id'];
				}
			} elseif ( ( $in['question'] ?? '' ) !== '' && isset( $byQ[ self::lc( $in['question'] ) ] ) ) {
				$id    = $byQ[ self::lc( $in['question'] ) ];
				$msg[] = _( "Matched an existing FAQ with the same question." );
			}
			$action = $id ? 'update' : 'add';
			if ( ( $action === 'add' && $mode === 'update' ) || ( $action === 'update' && $mode === 'add' ) ) {
				$res['rows'][ $rowNo ] = array( 'action' => 'skip', 'id' => $id, 'title' => $in['question'] ?? '', 'messages' => array( _( "Skipped (import mode)." ) ), 'data' => array() );
				$res['counts']['skip']++;
				continue;
			}
			foreach ( $in as $k => $v ) {
				if ( $k[0] === '#' || $k === 'faq_id' || ( $v === '' && $action === 'update' ) ) {
					continue;
				}
				if ( $k === 'subjects' || $k === 'collections' ) {
					$list = $k === 'subjects' ? $subjects : $colls;
					$ids  = array();
					foreach ( preg_split( '/\s*[;\n|]\s*/', $v ) as $s ) {
						if ( $s === '' ) {
							continue;
						}
						if ( ! isset( $list[ self::lc( $s ) ] ) ) {
							$err[] = sprintf( $k === 'subjects' ? _( "Unknown subject \"%s\"." ) : _( "Unknown FAQ collection \"%s\"." ), $s );
						} else {
							$ids[] = $list[ self::lc( $s ) ];
						}
					}
					$data[ $k ] = array_values( array_unique( $ids ) );
				} else {
					$data[ $k ] = $v;
				}
			}
			if ( $action === 'add' && ( ( $data['question'] ?? '' ) === '' || ( $data['answer'] ?? '' ) === '' ) ) {
				$err[] = _( "A new FAQ needs a question and an answer." );
			}
			if ( $action === 'update' && ! $data && ! $err ) {
				$res['rows'][ $rowNo ] = array( 'action' => 'skip', 'id' => $id, 'title' => $byId[ $id ], 'messages' => array( _( "Nothing to change." ) ), 'data' => array() );
				$res['counts']['skip']++;
				continue;
			}
			$a = $err ? 'error' : $action;
			$res['rows'][ $rowNo ] = array( 'action' => $a, 'id' => $id, 'title' => $data['question'] ?? ( $id ? $byId[ $id ] : '' ), 'messages' => array_merge( $err, $msg ), 'data' => $data );
			$res['counts'][ $a ]++;
		}

		return $res;
	}

	public static function faqApply( array $plan, $staffId ) {
		$c    = self::conn();
		$done = array( 'add' => 0, 'update' => 0, 'failed' => 0, 'messages' => array() );
		foreach ( $plan['rows'] as $rowNo => $p ) {
			if ( $p['action'] !== 'add' && $p['action'] !== 'update' ) {
				continue;
			}
			$d = $p['data'];
			$v = array();
			if ( array_key_exists( 'question', $d ) ) {
				$v['question'] = htmlspecialchars( strip_tags( self::bmp( $d['question'] ) ), ENT_QUOTES );
			}
			if ( array_key_exists( 'answer', $d ) ) {
				$v['answer'] = mb_decode_numericentity( sp_purifier()->purify( self::bmp( $d['answer'] ) ), array( 0x80, 0x10FFFF, 0, 0x1FFFFF ), 'UTF-8' );
			}
			if ( array_key_exists( 'keywords', $d ) ) {
				$v['keywords'] = htmlspecialchars( strip_tags( self::bmp( $d['keywords'] ) ), ENT_QUOTES );
			}
			try {
				$c->beginTransaction();
				if ( $p['action'] === 'add' ) {
					$v += array( 'keywords' => '' );
					self::run( $c, "INSERT INTO faq (question, answer, keywords) VALUES (?, ?, ?)", array( $v['question'], $v['answer'], $v['keywords'] ) );
					$id = (int) $c->lastInsertId();
					$done['messages'][ $rowNo ] = sprintf( _( "Added as FAQ %d." ), $id );
				} else {
					$id = (int) $p['id'];
					if ( $v ) {
						$set = implode( ', ', array_map( function ( $k ) { return "$k = ?"; }, array_keys( $v ) ) );
						self::run( $c, "UPDATE faq SET $set WHERE faq_id = ?", array_merge( array_values( $v ), array( $id ) ) );
					}
				}
				if ( isset( $d['subjects'] ) ) {
					self::run( $c, "DELETE FROM faq_subject WHERE faq_id = ?", array( $id ) );
					foreach ( $d['subjects'] as $sid ) {
						self::run( $c, "INSERT INTO faq_subject (faq_id, subject_id) VALUES (?, ?)", array( $id, $sid ) );
					}
				}
				if ( isset( $d['collections'] ) ) {
					self::run( $c, "DELETE FROM faq_faqpage WHERE faq_id = ?", array( $id ) );
					foreach ( $d['collections'] as $pid ) {
						self::run( $c, "INSERT INTO faq_faqpage (faq_id, faqpage_id) VALUES (?, ?)", array( $id, $pid ) );
					}
				}
				$c->commit();
				$done[ $p['action'] ]++;
			} catch ( \Throwable $e ) {
				if ( $c->inTransaction() ) {
					$c->rollBack();
				}
				$done['failed']++;
				$done['messages'][ $rowNo ] = _( "Could not be saved:" ) . ' ' . $e->getMessage();
			}
		}

		return $done;
	}
}
