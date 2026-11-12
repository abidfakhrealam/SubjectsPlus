<?php

namespace SubjectsPlus\Control;

/**
 * ready-made workflows a librarian can apply in Admin > Workflow, then adjust.
 * Applying a preset only ADDS missing statuses, transitions, date rules and fields — it never
 * deletes or changes what is already there, so presets can be combined and re-applied safely.
 */
#[\AllowDynamicProperties]
class WorkflowPresets {

	public static function all() {
		return array(
			'approval'  => array(
				'label'       => 'Approval',
				'description' => 'Staff prepare a record as a Draft and submit it for review; an approver publishes it or sends it back with a comment.',
				'statuses'    => array(
					array( 'Draft', 'draft', 0, '#8d6e63', 5, 'New records start here; not shown to the public.' ),
					array( 'In review', 'review', 0, '#ef6c00', 7, 'Waiting for an approver.' ),
					array( 'Active', 'active', 1, '#2e7d32', 10, 'Published.' ),
					array( 'Inactive', 'inactive', 0, '#757575', 30, 'Withdrawn.' ),
				),
				'initial'     => 'Draft',
				'transitions' => array(
					array( 'Draft', 'In review', 'Submit for review', 'records', 0, 1 ),
					array( 'In review', 'Active', 'Approve and publish', 'approver', 0, 1 ),
					array( 'In review', 'Draft', 'Send back for changes', 'approver', 1, 1 ),
					array( 'Active', 'Inactive', 'Withdraw', 'eresource_mgr', 1, 1 ),
					array( 'Inactive', 'In review', 'Resubmit for review', 'records', 0, 1 ),
				),
			),
			'lifecycle' => array(
				'label'       => 'E-resource lifecycle',
				'description' => 'Trial → Active → Renewal due → Expired or Cancelled, driven by trial and subscription dates, with email reminders.',
				'statuses'    => array(
					array( 'Trial', 'trial', 1, '#1565c0', 20, 'On trial; shown to users.' ),
					array( 'Active', 'active', 1, '#2e7d32', 10, 'Subscribed.' ),
					array( 'Renewal due', 'renewal', 1, '#f9a825', 25, 'Subscription ends soon; still shown to users.' ),
					array( 'Expired', 'expired', 0, '#6d4c41', 35, 'Access ended; hidden.' ),
					array( 'Cancelled', 'inactive', 0, '#757575', 36, 'Not renewed / not subscribed; hidden.' ),
				),
				'transitions' => array(
					array( 'Trial', 'Active', 'Subscribe (make active)', 'approver', 0, 1 ),
					array( 'Trial', 'Cancelled', 'End trial — not subscribing', 'eresource_mgr', 1, 1 ),
					array( 'Active', 'Renewal due', 'Mark renewal due', 'eresource_mgr', 0, 0 ),
					array( 'Renewal due', 'Active', 'Renewed', 'eresource_mgr', 0, 1 ),
					array( 'Renewal due', 'Cancelled', 'Cancel subscription', 'eresource_mgr', 1, 1 ),
					array( 'Expired', 'Active', 'Reactivate', 'approver', 0, 1 ),
					array( 'Cancelled', 'Active', 'Reactivate', 'approver', 0, 1 ),
				),
				'fields'      => array(
					array( 'Vendor / publisher', 'text', '', 0, 0 ),
					array( 'Access model', 'select', "Subscription\nPerpetual access\nConsortium (e.g. e-ShodhSindhu)\nOpen access\nTrial only", 0, 0 ),
					array( 'Subscription start', 'date', '', 0, 0 ),
					array( 'Subscription end', 'date', '', 0, 0 ),
					array( 'Annual cost', 'money', '', 0, 0 ),
					array( 'Licence agreement', 'file', '', 0, 0 ),
					array( 'Licence notes (ILL, e-reserves, text mining…)', 'textarea', '', 0, 0 ),
					array( 'Usage statistics portal', 'url', '', 0, 0 ),
				),
				'rules'       => array(
					array( 'Trial ends in 7 days', 'Trial', 'trial_end', -7, null ),
					array( 'Trial ended', 'Trial', 'trial_end', 1, 'Expired' ),
					array( 'Renewal due in 60 days', 'Active', 'field:Subscription end', -60, 'Renewal due' ),
					array( 'Renewal reminder (15 days left)', 'Renewal due', 'field:Subscription end', -15, null ),
					array( 'Subscription ended', 'Renewal due', 'field:Subscription end', 1, 'Expired' ),
				),
			),
			'request'   => array(
				'label'       => 'Request & acquisition',
				'description' => 'Faculty, students or staff suggest a resource through a public form; e-resource managers evaluate it, run a trial, and approvers decide.',
				'statuses'    => array(
					array( 'Requested', 'request', 0, '#6a1b9a', 1, 'Suggested through the request form.' ),
					array( 'Under evaluation', 'request', 0, '#8e24aa', 2, 'Being assessed (cost, overlap, licence).' ),
					array( 'Approved for purchase', 'request', 0, '#00897b', 3, 'Approved; waiting for access to be set up.' ),
					array( 'Rejected', 'rejected', 0, '#c62828', 40, 'Not acquired.' ),
				),
				'transitions' => array(
					array( 'Requested', 'Under evaluation', 'Start evaluation', 'eresource_mgr', 0, 0 ),
					array( 'Requested', 'Rejected', 'Decline request', 'eresource_mgr', 1, 1 ),
					array( 'Under evaluation', 'Trial', 'Start a trial', 'eresource_mgr', 0, 1 ),
					array( 'Under evaluation', 'Approved for purchase', 'Approve purchase', 'approver', 0, 1 ),
					array( 'Under evaluation', 'Rejected', 'Reject', 'approver', 1, 1 ),
					array( 'Trial', 'Approved for purchase', 'Approve purchase', 'approver', 0, 1 ),
					array( 'Approved for purchase', 'Draft', 'Prepare catalogue entry', 'records', 0, 0 ),
					array( 'Approved for purchase', 'Active', 'Activate subscription', 'eresource_mgr', 0, 1 ),
				),
			),
		);
	}

	private static function conn() {
		$db = new Querier;

		return $db->getConnection();
	}

	/** @return array summary of what was added */
	public static function apply( $key ) {
		$presets = self::all();
		if ( ! isset( $presets[ $key ] ) ) {
			return array();
		}
		$p    = $presets[ $key ];
		$c    = self::conn();
		$done = array( 'statuses' => 0, 'transitions' => 0, 'fields' => 0, 'rules' => 0 );

		$ins = $c->prepare( "INSERT IGNORE INTO record_status_option (name, stage, is_public, color, sort_order, description) VALUES (?,?,?,?,?,?)" );
		foreach ( $p['statuses'] as $s ) {
			$ins->execute( $s );
			$done['statuses'] += $ins->rowCount();
		}
		if ( ! empty( $p['initial'] ) ) {
			$c->exec( "UPDATE record_status_option SET is_initial = 0" );
			$c->prepare( "UPDATE record_status_option SET is_initial = 1 WHERE name = ?" )->execute( array( $p['initial'] ) );
		}
		RecordSettings::reset();
		$ids = array();
		foreach ( $c->query( "SELECT status_id, name FROM record_status_option" )->fetchAll( \PDO::FETCH_ASSOC ) as $r ) {
			$ids[ $r['name'] ] = (int) $r['status_id'];
		}

		foreach ( $p['transitions'] ?? array() as $t ) {
			list( $from, $to, $label, $role, $comment, $notify ) = $t;
			if ( ! isset( $ids[ $from ], $ids[ $to ] ) ) {
				continue; // e.g. "Start a trial" needs the lifecycle preset's Trial status
			}
			$chk = $c->prepare( "SELECT COUNT(*) FROM workflow_transition WHERE from_status_id = ? AND to_status_id = ?" );
			$chk->execute( array( $ids[ $from ], $ids[ $to ] ) );
			if ( (int) $chk->fetchColumn() === 0 ) {
				$c->prepare( "INSERT INTO workflow_transition (from_status_id, to_status_id, label, role, require_comment, notify, sort_order) VALUES (?,?,?,?,?,?,?)" )
				  ->execute( array( $ids[ $from ], $ids[ $to ], $label, $role, $comment, $notify, $done['transitions'] ) );
				$done['transitions']++;
			}
		}

		foreach ( $p['fields'] ?? array() as $i => $f ) {
			list( $label, $type, $options, $required, $public ) = $f;
			$chk = $c->prepare( "SELECT COUNT(*) FROM record_field WHERE label = ?" );
			$chk->execute( array( $label ) );
			if ( (int) $chk->fetchColumn() === 0 ) {
				$c->prepare( "INSERT INTO record_field (label, type, options, required, is_public, sort_order) VALUES (?,?,?,?,?,?)" )
				  ->execute( array( $label, $type, $options, $required, $public, 100 + $i ) );
				$done['fields']++;
			}
		}
		RecordSettings::reset();

		foreach ( $p['rules'] ?? array() as $r ) {
			list( $name, $status, $source, $offset, $to ) = $r;
			if ( strpos( $source, 'field:' ) === 0 ) {
				$st = $c->prepare( "SELECT field_id FROM record_field WHERE label = ? AND type = 'date'" );
				$st->execute( array( substr( $source, 6 ) ) );
				$fid = $st->fetchColumn();
				if ( ! $fid ) {
					continue;
				}
				$source = 'field:' . $fid;
			}
			if ( ! isset( $ids[ $status ] ) || ( $to && ! isset( $ids[ $to ] ) ) ) {
				continue;
			}
			$chk = $c->prepare( "SELECT COUNT(*) FROM workflow_rule WHERE name = ?" );
			$chk->execute( array( $name ) );
			if ( (int) $chk->fetchColumn() === 0 ) {
				$c->prepare( "INSERT INTO workflow_rule (name, status_id, date_source, offset_days, to_status_id, notify) VALUES (?,?,?,?,?,1)" )
				  ->execute( array( $name, $ids[ $status ], $source, $offset, $to ? $ids[ $to ] : null ) );
				$done['rules']++;
			}
		}

		return $done;
	}
}
