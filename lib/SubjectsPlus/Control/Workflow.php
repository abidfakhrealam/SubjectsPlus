<?php

namespace SubjectsPlus\Control;

use PHPMailer\PHPMailer\PHPMailer;

/**
 * record workflow — statuses, allowed transitions by role, public visibility per status,
 * date rules (trial / subscription end), e-resource requests, history and email notices.
 *
 * Free mode: while no transitions are defined, staff can pick any status (SubjectsPlus 4.6 behaviour).
 * Once any transition exists, status changes must follow the defined transitions.
 */
#[\AllowDynamicProperties]
class Workflow {

	/** Who may use a transition. 'admin' staff may use every transition. */
	const ROLES = array(
		'records'       => 'Any staff who can edit records',
		'eresource_mgr' => 'E-resource managers (eresource_mgr)',
		'approver'      => 'Approvers (approver)',
		'admin'         => 'Administrators only (admin)',
	);

	/** Dates a rule can watch. Custom date fields are added as "field:<id>". */
	public static function dateSources() {
		$out = array( 'trial_start' => 'Trial start', 'trial_end' => 'Trial end' );
		foreach ( RecordSettings::fields( true ) as $f ) {
			if ( $f['type'] === 'date' ) {
				$out[ 'field:' . $f['field_id'] ] = $f['label'];
			}
		}

		return $out;
	}

	private static function conn() {
		$db = new Querier;

		return $db->getConnection();
	}

	public static function enforced() {
		if ( ! RecordSettings::ready() ) {
			return false;
		}
		$r = self::conn()->query( "SELECT COUNT(*) FROM workflow_transition" );

		return $r && (int) $r->fetchColumn() > 0;
	}

	public static function currentStatus( $titleId ) {
		$st = self::conn()->prepare( "SELECT l.record_status FROM location l JOIN location_title lt ON lt.location_id = l.location_id WHERE lt.title_id = ? ORDER BY l.location_id LIMIT 1" );
		$st->execute( array( (int) $titleId ) );
		$v = $st->fetchColumn();

		return $v === false ? null : (string) $v;
	}

	/** Does the signed-in staff member have this role? */
	public static function hasRole( $role, ?array $session = null ) {
		$s = $session ?? ( $_SESSION ?? array() );
		if ( ! empty( $s['admin'] ) ) {
			return true;
		}
		switch ( $role ) {
			case 'records':
				return ! empty( $s['records'] ) || ! empty( $s['eresource_mgr'] ) || ! empty( $s['approver'] );
			case 'eresource_mgr':
				return ! empty( $s['eresource_mgr'] );
			case 'approver':
				return ! empty( $s['approver'] );
			default:
				return false;
		}
	}

	/** All transitions out of a status (rows include to_name, role, label, require_comment, notify). */
	public static function transitionsFrom( $statusName ) {
		$from = RecordSettings::status( (string) $statusName );
		$st   = self::conn()->prepare( "SELECT t.*, s.name AS to_name, s.color AS to_color
			FROM workflow_transition t JOIN record_status_option s ON s.status_id = t.to_status_id
			WHERE s.active = 1 AND (t.from_status_id IS NULL OR t.from_status_id = ?)
			ORDER BY t.sort_order, t.transition_id" );
		$st->execute( array( $from ? (int) $from['status_id'] : -1 ) );
		$out = array();
		foreach ( $st->fetchAll( \PDO::FETCH_ASSOC ) as $t ) {
			if ( $t['to_name'] !== $statusName ) {
				$out[] = $t;
			}
		}

		return $out;
	}

	/**
	 * What the current staff member may change this record to.
	 * @return array of ['to' => name, 'label' => button text, 'require_comment' => bool, 'notify' => bool, 'transition_id' => int|null]
	 */
	public static function allowedActions( $currentStatus, ?array $session = null ) {
		$out = array();
		if ( ! self::enforced() ) {
			foreach ( RecordSettings::statuses() as $name => $s ) {
				if ( $name !== $currentStatus ) {
					$out[] = array( 'to' => $name, 'label' => $name, 'require_comment' => false, 'notify' => false, 'transition_id' => null, 'color' => $s['color'] );
				}
			}

			return $out;
		}
		foreach ( self::transitionsFrom( $currentStatus ) as $t ) {
			if ( self::hasRole( $t['role'], $session ) ) {
				$out[] = array( 'to' => $t['to_name'], 'label' => $t['label'] !== '' ? $t['label'] : $t['to_name'], 'require_comment' => (bool) $t['require_comment'],
				                'notify' => (bool) $t['notify'], 'transition_id' => (int) $t['transition_id'], 'color' => $t['to_color'] );
			}
		}

		return $out;
	}

	/**
	 * Change the status of a record (all its locations).
	 *
	 * @param string $via 'staff' | 'rule' | 'request' | 'system'
	 * @return true|string  true, or an error message
	 */
	public static function changeStatus( $titleId, $toStatus, $comment = '', $staffId = null, $via = 'staff', $transitionId = null, ?array $session = null ) {
		$titleId = (int) $titleId;
		$to      = RecordSettings::status( (string) $toStatus );
		if ( ! $to || ! (int) $to['active'] ) {
			return _( "Unknown status." );
		}
		$from = self::currentStatus( $titleId );
		if ( $from === null ) {
			return _( "This record has no location to set a status on." );
		}
		if ( $from === $to['name'] ) {
			return true;
		}
		$notify = false;
		if ( $via === 'staff' ) {
			$allowed = null;
			foreach ( self::allowedActions( $from, $session ) as $a ) {
				if ( $a['to'] === $to['name'] && ( $transitionId === null || (int) $a['transition_id'] === (int) $transitionId || $a['transition_id'] === null ) ) {
					$allowed = $a;
					break;
				}
			}
			if ( ! $allowed ) {
				return sprintf( _( "You are not allowed to change this record from %s to %s." ), $from, $to['name'] );
			}
			if ( $allowed['require_comment'] && trim( (string) $comment ) === '' ) {
				return _( "Please add a comment explaining this change." );
			}
			$notify = $allowed['notify'];
		}

		$c = self::conn();
		$c->beginTransaction();
		try {
			$c->prepare( "UPDATE location l JOIN location_title lt ON lt.location_id = l.location_id SET l.record_status = ? WHERE lt.title_id = ?" )
			  ->execute( array( $to['name'], $titleId ) );
			self::syncVisibility( $titleId );
			self::addHistory( $titleId, $from, $to['name'], $staffId, $via, $comment );
			$c->commit();
		} catch ( \Throwable $e ) {
			$c->rollBack();
			error_log( 'SubjectsPlus workflow error: ' . $e->getMessage() );

			return _( "The status could not be changed. Please try again." );
		}

		if ( $notify ) {
			$title = self::recordTitle( $titleId );
			self::notify( $titleId, sprintf( _( "%s: %s → %s" ), $title, $from, $to['name'] ),
				'<p>' . sprintf( _( "%s changed the status of %s from <strong>%s</strong> to <strong>%s</strong>." ),
					htmlspecialchars( self::staffName( $staffId ) ), '<em>' . htmlspecialchars( $title ) . '</em>', htmlspecialchars( $from ), htmlspecialchars( $to['name'] ) ) . '</p>'
				. ( trim( (string) $comment ) !== '' ? '<p>' . _( "Comment" ) . ': ' . nl2br( htmlspecialchars( $comment ) ) . '</p>' : '' ) );
		}

		return true;
	}

	/**
	 * Hide records whose status is not public from the A–Z list, and restore them when they become
	 * public again. The librarian's own "show in A–Z" choice is remembered in eres_display_saved.
	 */
	public static function syncVisibility( $titleId, $fromForm = false ) {
		if ( ! RecordSettings::ready() ) {
			return;
		}
		$public = RecordSettings::publicStatusNames();
		$c      = self::conn();
		$st     = $c->prepare( "SELECT l.location_id, l.record_status, l.eres_display, l.eres_display_saved FROM location l JOIN location_title lt ON lt.location_id = l.location_id WHERE lt.title_id = ?" );
		$st->execute( array( (int) $titleId ) );
		foreach ( $st->fetchAll( \PDO::FETCH_ASSOC ) as $l ) {
			$isPublic = in_array( (string) $l['record_status'], $public, true );
			if ( $fromForm && ! $isPublic ) {
				// just saved from the record form: eres_display holds the librarian's intended A-Z setting
				$c->prepare( "UPDATE location SET eres_display = 'N', eres_display_saved = ? WHERE location_id = ?" )
				  ->execute( array( $l['eres_display'] === 'Y' ? 'Y' : null, $l['location_id'] ) );
			} elseif ( $fromForm && $isPublic ) {
				$c->prepare( "UPDATE location SET eres_display_saved = NULL WHERE location_id = ?" )->execute( array( $l['location_id'] ) );
			} elseif ( ! $isPublic && $l['eres_display'] === 'Y' ) {
				$c->prepare( "UPDATE location SET eres_display = 'N', eres_display_saved = 'Y' WHERE location_id = ?" )->execute( array( $l['location_id'] ) );
			} elseif ( $isPublic && $l['eres_display_saved'] === 'Y' ) {
				$c->prepare( "UPDATE location SET eres_display = 'Y', eres_display_saved = NULL WHERE location_id = ?" )->execute( array( $l['location_id'] ) );
			}
		}
	}

	public static function addHistory( $titleId, $from, $to, $staffId, $via, $comment ) {
		self::conn()->prepare( "INSERT INTO workflow_history (title_id, from_status, to_status, staff_id, via, comment, created_at) VALUES (?,?,?,?,?,?,NOW())" )
		    ->execute( array( (int) $titleId, $from, $to, $staffId ? (int) $staffId : null, $via, trim( (string) $comment ) !== '' ? trim( (string) $comment ) : null ) );
	}

	public static function history( $titleId, $limit = 50 ) {
		$st = self::conn()->prepare( "SELECT h.*, CONCAT(s.fname, ' ', s.lname) AS staff_name FROM workflow_history h LEFT JOIN staff s ON s.staff_id = h.staff_id WHERE h.title_id = ? ORDER BY h.created_at DESC, h.history_id DESC LIMIT " . (int) $limit );
		$st->execute( array( (int) $titleId ) );

		return $st->fetchAll( \PDO::FETCH_ASSOC );
	}

	public static function recordTitle( $titleId ) {
		$st = self::conn()->prepare( "SELECT title FROM title WHERE title_id = ?" );
		$st->execute( array( (int) $titleId ) );

		return (string) $st->fetchColumn();
	}

	private static function staffName( $staffId ) {
		if ( ! $staffId ) {
			return _( "SubjectsPlus" );
		}
		$st = self::conn()->prepare( "SELECT CONCAT(fname, ' ', lname) FROM staff WHERE staff_id = ?" );
		$st->execute( array( (int) $staffId ) );

		return (string) $st->fetchColumn();
	}

	// ---------------- Dates ----------------

	/** The date a rule watches for one record, or null. */
	public static function recordDate( $titleId, $source ) {
		$c = self::conn();
		if ( $source === 'trial_start' || $source === 'trial_end' ) {
			$st = $c->prepare( "SELECT MIN(l.`$source`) FROM location l JOIN location_title lt ON lt.location_id = l.location_id WHERE lt.title_id = ?" );
			$st->execute( array( (int) $titleId ) );
			$v = $st->fetchColumn();
		} elseif ( preg_match( '/^field:(\d+)$/', (string) $source, $m ) ) {
			$st = $c->prepare( "SELECT value FROM record_field_value WHERE title_id = ? AND field_id = ?" );
			$st->execute( array( (int) $titleId, (int) $m[1] ) );
			$v = $st->fetchColumn();
		} else {
			return null;
		}

		return ( $v && preg_match( '/^\d{4}-\d{2}-\d{2}/', $v ) ) ? substr( $v, 0, 10 ) : null;
	}

	public static function setTrialDates( $titleId, $start, $end ) {
		$norm = function ( $d ) {
			$d = trim( (string) $d );

			return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) ? $d : null;
		};
		self::conn()->prepare( "UPDATE location l JOIN location_title lt ON lt.location_id = l.location_id SET l.trial_start = ?, l.trial_end = ? WHERE lt.title_id = ?" )
		    ->execute( array( $norm( $start ), $norm( $end ), (int) $titleId ) );
	}

	/**
	 * Apply the date rules (run daily by tools/workflow-daily.php, or from Admin > Workflow).
	 * A rule fires once per record for each value of the watched date, when today >= date + offset.
	 *
	 * @return array log lines
	 */
	public static function runRules( $today = null ) {
		$today = $today ?: date( 'Y-m-d' );
		$log   = array();
		$c     = self::conn();
		$rules = $c->query( "SELECT r.*, s.name AS status_name, t.name AS to_name FROM workflow_rule r
			JOIN record_status_option s ON s.status_id = r.status_id
			LEFT JOIN record_status_option t ON t.status_id = r.to_status_id
			WHERE r.active = 1 ORDER BY r.rule_id" )->fetchAll( \PDO::FETCH_ASSOC );

		foreach ( $rules as $r ) {
			$st = $c->prepare( "SELECT DISTINCT lt.title_id FROM location l JOIN location_title lt ON lt.location_id = l.location_id WHERE l.record_status = ?" );
			$st->execute( array( $r['status_name'] ) );
			foreach ( $st->fetchAll( \PDO::FETCH_COLUMN ) as $titleId ) {
				$date = self::recordDate( $titleId, $r['date_source'] );
				if ( ! $date ) {
					continue;
				}
				$due = date( 'Y-m-d', strtotime( $date . ' ' . ( (int) $r['offset_days'] >= 0 ? '+' : '' ) . (int) $r['offset_days'] . ' days' ) );
				if ( $today < $due ) {
					continue;
				}
				$done = $c->prepare( "INSERT IGNORE INTO workflow_rule_log (rule_id, title_id, fired_for, fired_at) VALUES (?,?,?,NOW())" );
				$done->execute( array( $r['rule_id'], $titleId, $date ) );
				if ( $done->rowCount() === 0 ) {
					continue; // already fired for this date
				}
				$title = self::recordTitle( $titleId );
				$what  = $r['name'] !== '' ? $r['name'] : _( "Date rule" );
				if ( $r['to_name'] ) {
					$res   = self::changeStatus( $titleId, $r['to_name'], sprintf( _( "Automatic: %s (%s %s)" ), $what, self::dateSources()[ $r['date_source'] ] ?? $r['date_source'], $date ), null, 'rule' );
					$log[] = "$title: {$r['status_name']} → {$r['to_name']} ($what)" . ( $res === true ? '' : " — FAILED: $res" );
				} else {
					$log[] = "$title: reminder ($what)";
				}
				if ( (int) $r['notify'] === 1 ) {
					self::notify( $titleId, sprintf( _( "%s: %s" ), $title, $what ),
						'<p>' . sprintf( _( "%s — %s is %s." ), '<em>' . htmlspecialchars( $title ) . '</em>', htmlspecialchars( self::dateSources()[ $r['date_source'] ] ?? $r['date_source'] ), htmlspecialchars( $date ) ) . '</p>'
						. ( $r['to_name'] ? '<p>' . sprintf( _( "Status changed automatically from %s to %s." ), htmlspecialchars( $r['status_name'] ), htmlspecialchars( $r['to_name'] ) ) . '</p>' : '' ),
						array_filter( array_map( 'trim', preg_split( '/[,;\s]+/', (string) $r['notify_emails'] ) ) ) );
				}
			}
		}

		return $log;
	}

	// ---------------- Requests ----------------

	/** First status in the 'request' stage, else the initial status. */
	public static function requestStatus() {
		foreach ( RecordSettings::statuses() as $s ) {
			if ( $s['stage'] === 'request' ) {
				return $s['name'];
			}
		}

		return RecordSettings::initialStatus();
	}

	/**
	 * Create a record from a user's suggestion. Not shown publicly until a librarian publishes it.
	 * @return int title_id
	 */
	public static function createRequest( array $d ) {
		$c = self::conn();
		$c->beginTransaction();
		try {
			$c->prepare( "INSERT INTO title (title, alternate_title, description, internal_notes, pre) VALUES (?, '', ?, ?, '')" )
			  ->execute( array( $d['title'], (string) ( $d['description'] ?? '' ), _( "Suggested through the request form." ) ) );
			$titleId = (int) $c->lastInsertId();
			$restr   = 2;
			foreach ( RecordSettings::restrictions() as $r ) {
				if ( RecordSettings::usesProxy( $r[0] ) ) {
					$restr = (int) $r[0];
					break;
				}
			}
			$c->prepare( "INSERT INTO location (format, call_number, location, access_restrictions, eres_display, display_note, ctags, helpguide, record_status)
				VALUES (1, '', ?, ?, 'N', '', '', '', ?)" )->execute( array( (string) ( $d['url'] ?? '' ), $restr, self::requestStatus() ) );
			$locId = (int) $c->lastInsertId();
			$c->prepare( "INSERT INTO location_title (location_id, title_id) VALUES (?, ?)" )->execute( array( $locId, $titleId ) );
			$c->prepare( "INSERT INTO resource_request (title_id, name, email, department, justification, created_at) VALUES (?,?,?,?,?,NOW())" )
			  ->execute( array( $titleId, $d['name'], $d['email'], (string) ( $d['department'] ?? '' ), (string) ( $d['justification'] ?? '' ) ) );
			self::addHistory( $titleId, null, self::requestStatus(), null, 'request', sprintf( _( "Requested by %s (%s)" ), $d['name'], $d['email'] ) );
			$c->commit();
		} catch ( \Throwable $e ) {
			$c->rollBack();
			throw $e;
		}
		global $CpanelPath, $BaseURL;
		$link = rtrim( (string) $BaseURL, '/' ) . '/control/records/record.php?record_id=' . $titleId;
		self::notify( $titleId, sprintf( _( "New e-resource request: %s" ), $d['title'] ),
			'<p>' . sprintf( _( "%s (%s, %s) suggested a new e-resource:" ), htmlspecialchars( $d['name'] ), htmlspecialchars( $d['email'] ), htmlspecialchars( (string) ( $d['department'] ?? '' ) ) ) . '</p>'
			. '<p><strong>' . htmlspecialchars( $d['title'] ) . '</strong><br>' . htmlspecialchars( (string) ( $d['url'] ?? '' ) ) . '</p>'
			. '<p>' . nl2br( htmlspecialchars( (string) ( $d['justification'] ?? '' ) ) ) . '</p>'
			. '<p><a href="' . htmlspecialchars( $link ) . '">' . _( "Open the record" ) . '</a></p>' );

		return $titleId;
	}

	public static function requestFor( $titleId ) {
		$st = self::conn()->prepare( "SELECT * FROM resource_request WHERE title_id = ? ORDER BY request_id DESC LIMIT 1" );
		$st->execute( array( (int) $titleId ) );

		return $st->fetch( \PDO::FETCH_ASSOC ) ?: null;
	}

	// ---------------- Email ----------------

	/** Staff to tell about workflow events: e-resource managers and approvers. */
	public static function recipients() {
		$out = array();
		foreach ( self::conn()->query( "SELECT email, ptags FROM staff WHERE active = 1 AND email <> ''" )->fetchAll( \PDO::FETCH_ASSOC ) as $s ) {
			$tags = explode( '|', (string) $s['ptags'] );
			if ( array_intersect( array( 'eresource_mgr', 'approver' ), $tags ) ) {
				$out[] = $s['email'];
			}
		}

		return $out;
	}

	public static function notify( $titleId, $subject, $html, array $extra = array() ) {
		return self::sendMail( array_merge( self::recipients(), $extra ), $subject, $html );
	}

	/** Sends an HTML email (BCC to every address) with the site's mail settings. */
	public static function sendMail( array $addresses, $subject, $html ) {
		global $administrator_email, $email_host, $email_port, $email_smtp_auth, $email_username, $email_password, $resource_name;
		$to = array_values( array_unique( array_filter( $addresses, function ( $e ) {
			return filter_var( $e, FILTER_VALIDATE_EMAIL );
		} ) ) );
		if ( ! $to ) {
			return false;
		}
		try {
			$m = new PHPMailer( true );
			$m->CharSet = 'UTF-8';
			if ( ! empty( $email_host ) ) {
				$m->isSMTP();
				$m->Host = $email_host;
				$m->Port = (int) ( $email_port ?: 25 );
				if ( ! empty( $email_smtp_auth ) && strtolower( (string) $email_smtp_auth ) !== 'false' ) {
					$m->SMTPAuth = true;
					$m->Username = (string) $email_username;
					$m->Password = (string) $email_password;
				}
			}
			$from = filter_var( $administrator_email, FILTER_VALIDATE_EMAIL ) ? $administrator_email : 'no-reply@localhost';
			$m->setFrom( $from, (string) ( $resource_name ?: 'SubjectsPlus' ) );
			foreach ( $to as $addr ) {
				$m->addBCC( $addr );
			}
			$m->Subject = '[' . ( $resource_name ?: 'SubjectsPlus' ) . '] ' . $subject;
			$m->isHTML( true );
			$m->Body    = $html;
			$m->AltBody = trim( html_entity_decode( strip_tags( str_replace( array( '<br>', '</p>' ), "\n", $html ) ) ) );
			$m->send();

			return true;
		} catch ( \Throwable $e ) {
			error_log( 'SubjectsPlus workflow email not sent: ' . $e->getMessage() );

			return false;
		}
	}
}
