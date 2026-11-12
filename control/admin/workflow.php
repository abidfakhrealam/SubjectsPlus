<?php
/**
 * @file workflow.php
 * @brief record workflow — presets, transitions (who may move a record from which status to which),
 *        date rules (trial / subscription), overview of upcoming dates and recent requests.
 */

use SubjectsPlus\Control\Querier;
use SubjectsPlus\Control\RecordSettings;
use SubjectsPlus\Control\Workflow;
use SubjectsPlus\Control\WorkflowPresets;

$subsubcat  = "";
$subcat     = "admin";
$page_title = "Workflow";

include( "../includes/header.php" );
include( "../includes/autoloader.php" );

$db   = new Querier;
$conn = $db->getConnection();

function wf_h( $s ) {
	return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
}

if ( ! RecordSettings::ready() ) {
	makePluslet( _( "Workflow" ), "<p>" . _( "Run <code>php tools/migrate.php</code> on the server to enable this page." ) . "</p>", "no_overflow" );
	include( "../includes/footer.php" );
	exit;
}
if ( empty( $_SESSION['sp_wf_csrf'] ) ) {
	$_SESSION['sp_wf_csrf'] = bin2hex( random_bytes( 32 ) );
}
$csrf     = $_SESSION['sp_wf_csrf'];
$feedback = array();
$errors   = array();
$runLog   = null;

$statusIds = function () {
	$o = array();
	foreach ( RecordSettings::statuses( true ) as $s ) {
		$o[ (int) $s['status_id'] ] = $s['name'];
	}

	return $o;
};

if ( $_SERVER['REQUEST_METHOD'] === 'POST' ) {
	if ( ! isset( $_POST['csrf'] ) || ! hash_equals( $csrf, (string) $_POST['csrf'] ) ) {
		$errors[] = _( "Your session has expired. Please reload the page and try again." );
	} else {
		$action = (string) ( $_POST['action'] ?? '' );
		$ids    = $statusIds();
		try {
			if ( $action === 'preset' ) {
				// "all": request runs again last so its links into Draft/Trial/Active (created by the other presets) are added too
				$keys = $_POST['preset'] === 'all' ? array( 'request', 'approval', 'lifecycle', 'request' ) : array( (string) $_POST['preset'] );
				$sum  = array( 'statuses' => 0, 'transitions' => 0, 'fields' => 0, 'rules' => 0 );
				foreach ( $keys as $k ) {
					foreach ( WorkflowPresets::apply( $k ) as $what => $n ) {
						$sum[ $what ] += $n;
					}
				}
				$feedback[] = sprintf( _( "Preset applied: %d statuses, %d transitions, %d custom fields and %d date rules added." ), $sum['statuses'], $sum['transitions'], $sum['fields'], $sum['rules'] );
			}

			if ( $action === 'transitions' ) {
				$conn->beginTransaction();
				foreach ( (array) ( $_POST['t'] ?? array() ) as $id => $t ) {
					if ( ! empty( $t['delete'] ) ) {
						$conn->prepare( "DELETE FROM workflow_transition WHERE transition_id = ?" )->execute( array( (int) $id ) );
						continue;
					}
					$from = (int) ( $t['from'] ?? 0 );
					$to   = (int) ( $t['to'] ?? 0 );
					if ( ! isset( $ids[ $to ] ) || ( $from && ! isset( $ids[ $from ] ) ) || $from === $to ) {
						continue;
					}
					$role = array_key_exists( $t['role'] ?? '', Workflow::ROLES ) ? $t['role'] : 'records';
					$conn->prepare( "UPDATE workflow_transition SET from_status_id = ?, to_status_id = ?, label = ?, role = ?, require_comment = ?, notify = ?, sort_order = ? WHERE transition_id = ?" )
					     ->execute( array( $from ?: null, $to, mb_substr( trim( strip_tags( (string) ( $t['label'] ?? '' ) ) ), 0, 100 ), $role, empty( $t['comment'] ) ? 0 : 1, empty( $t['notify'] ) ? 0 : 1, (int) ( $t['sort'] ?? 0 ), (int) $id ) );
				}
				$n = $_POST['newt'] ?? array();
				if ( ! empty( $n['to'] ) && isset( $ids[ (int) $n['to'] ] ) && (int) ( $n['from'] ?? 0 ) !== (int) $n['to'] ) {
					$role = array_key_exists( $n['role'] ?? '', Workflow::ROLES ) ? $n['role'] : 'records';
					$conn->prepare( "INSERT INTO workflow_transition (from_status_id, to_status_id, label, role, require_comment, notify, sort_order) VALUES (?,?,?,?,?,?,99)" )
					     ->execute( array( (int) ( $n['from'] ?? 0 ) ?: null, (int) $n['to'], mb_substr( trim( strip_tags( (string) ( $n['label'] ?? '' ) ) ), 0, 100 ), $role, empty( $n['comment'] ) ? 0 : 1, empty( $n['notify'] ) ? 0 : 1 ) );
				}
				$conn->commit();
				$feedback[] = _( "Transitions saved." );
			}

			if ( $action === 'rules' ) {
				$sources = Workflow::dateSources();
				$conn->beginTransaction();
				$saveRule = function ( $r, $id = null ) use ( $conn, $ids, $sources ) {
					$status = (int) ( $r['status'] ?? 0 );
					if ( ! isset( $ids[ $status ] ) || ! isset( $sources[ $r['source'] ?? '' ] ) ) {
						return false;
					}
					$to     = (int) ( $r['to'] ?? 0 );
					$emails = implode( ', ', array_filter( array_map( 'trim', preg_split( '/[,;\s]+/', (string) ( $r['emails'] ?? '' ) ) ), function ( $e ) {
						return filter_var( $e, FILTER_VALIDATE_EMAIL );
					} ) );
					$vals   = array( mb_substr( trim( strip_tags( (string) ( $r['name'] ?? '' ) ) ), 0, 150 ), $status, $r['source'], max( -3650, min( 3650, (int) ( $r['offset'] ?? 0 ) ) ),
					                 isset( $ids[ $to ] ) ? $to : null, empty( $r['notify'] ) ? 0 : 1, $emails, ( $id === null || ! empty( $r['active'] ) ) ? 1 : 0 );
					if ( $id === null ) {
						$conn->prepare( "INSERT INTO workflow_rule (name, status_id, date_source, offset_days, to_status_id, notify, notify_emails, active) VALUES (?,?,?,?,?,?,?,?)" )->execute( $vals );
					} else {
						$vals[] = (int) $id;
						$conn->prepare( "UPDATE workflow_rule SET name=?, status_id=?, date_source=?, offset_days=?, to_status_id=?, notify=?, notify_emails=?, active=? WHERE rule_id=?" )->execute( $vals );
					}

					return true;
				};
				foreach ( (array) ( $_POST['r'] ?? array() ) as $id => $r ) {
					if ( ! empty( $r['delete'] ) ) {
						$conn->prepare( "DELETE FROM workflow_rule WHERE rule_id = ?" )->execute( array( (int) $id ) );
						continue;
					}
					$saveRule( $r, $id );
				}
				$saveRule( $_POST['newr'] ?? array() );
				$conn->commit();
				$feedback[] = _( "Date rules saved." );
			}

			if ( $action === 'run' ) {
				$runLog = Workflow::runRules();
			}
		} catch ( \Throwable $e ) {
			if ( $conn->inTransaction() ) {
				$conn->rollBack();
			}
			error_log( 'SubjectsPlus workflow admin: ' . $e->getMessage() );
			$errors[] = _( "The changes could not be saved." );
		}
		RecordSettings::reset();
	}
}

$ids      = $statusIds();
$statuses = RecordSettings::statuses( true );
$sel      = function ( $name, array $opts, $cur, $empty = null ) {
	$h = '<select name="' . $name . '">' . ( $empty !== null ? '<option value="0">' . wf_h( $empty ) . '</option>' : '' );
	foreach ( $opts as $k => $v ) {
		$h .= '<option value="' . wf_h( $k ) . '"' . ( (string) $k === (string) $cur ? ' selected' : '' ) . '>' . wf_h( $v ) . '</option>';
	}

	return $h . '</select>';
};
$chk  = function ( $name, $on ) {
	return '<input type="checkbox" name="' . $name . '" value="1"' . ( $on ? ' checked' : '' ) . '>';
};
$badge = function ( $name ) use ( $statuses ) {
	$c = $statuses[ $name ]['color'] ?? '#777777';

	return '<span class="wf-badge" style="background:' . wf_h( $c ) . ';color:' . sp_brand_on( sp_brand_color( $c ) ?: '#777777' ) . '">' . wf_h( $name ) . '</span>';
};
$hidden = '<input type="hidden" name="csrf" value="' . wf_h( $csrf ) . '">';

print '<div class="pure-g"><div class="pure-u-1"><p><a href="record_settings.php?tab=statuses">' . _( "← Statuses, formats and custom fields" ) . '</a></p>';
foreach ( $feedback as $f ) {
	print '<div class="feedback">' . wf_h( $f ) . '</div>';
}
foreach ( $errors as $e ) {
	print '<div class="feedback" style="background:#fdecea" role="alert">' . wf_h( $e ) . '</div>';
}
print '</div></div><div class="pure-g"><div class="pure-u-1 pure-u-lg-1-3">';

// ---- overview
$counts = $conn->query( "SELECT record_status, COUNT(DISTINCT lt.title_id) FROM location l JOIN location_title lt ON lt.location_id = l.location_id GROUP BY record_status" )->fetchAll( PDO::FETCH_KEY_PAIR );
$ov     = '<p>' . ( Workflow::enforced() ? _( "Workflow is <strong>on</strong>: staff change a record's status with the buttons defined below." ) : _( "Workflow is <strong>off</strong>: staff can pick any status. Add a transition or apply a preset to switch it on." ) ) . '</p><ul class="wf-counts">';
foreach ( $statuses as $name => $s ) {
	$ov .= '<li>' . $badge( $name ) . ' ' . (int) ( $counts[ $name ] ?? 0 ) . ( (int) $s['is_public'] ? '' : ' <small>(' . _( "hidden" ) . ')</small>' ) . '</li>';
}
$ov .= '</ul>';
// upcoming dates (next 90 days) from trial end and custom date fields
$upcoming = array();
$q        = $conn->query( "SELECT DISTINCT t.title_id, t.title, l.record_status, l.trial_end AS d, 'Trial end' AS what FROM title t JOIN location_title lt ON lt.title_id = t.title_id JOIN location l ON l.location_id = lt.location_id
	WHERE l.trial_end IS NOT NULL AND l.trial_end BETWEEN CURDATE() - INTERVAL 7 DAY AND CURDATE() + INTERVAL 90 DAY
	UNION SELECT DISTINCT t.title_id, t.title, l.record_status, v.value AS d, f.label AS what FROM record_field_value v JOIN record_field f ON f.field_id = v.field_id AND f.type = 'date' AND f.active = 1
	JOIN title t ON t.title_id = v.title_id JOIN location_title lt ON lt.title_id = t.title_id JOIN location l ON l.location_id = lt.location_id
	WHERE v.value BETWEEN DATE_FORMAT(CURDATE() - INTERVAL 7 DAY, '%Y-%m-%d') AND DATE_FORMAT(CURDATE() + INTERVAL 90 DAY, '%Y-%m-%d')
	ORDER BY d LIMIT 50" );
foreach ( $q ? $q->fetchAll( PDO::FETCH_ASSOC ) : array() as $u ) {
	$upcoming[] = '<li><strong>' . wf_h( date( 'j M Y', strtotime( $u['d'] ) ) ) . '</strong> — <a href="../records/record.php?record_id=' . (int) $u['title_id'] . '">' . wf_h( $u['title'] ) . '</a> · ' . wf_h( $u['what'] ) . ' ' . $badge( $u['record_status'] ) . '</li>';
}
$ov .= '<h3>' . _( "Coming up (next 90 days)" ) . '</h3>' . ( $upcoming ? '<ul class="wf-upcoming">' . implode( '', $upcoming ) . '</ul>' : '<p>' . _( "Nothing due." ) . '</p>' );
makePluslet( _( "Overview" ), $ov, "no_overflow" );

// ---- presets
$pr = '<p>' . _( "Start from a ready-made workflow, then adjust it below. Presets only add what is missing; nothing is removed." ) . '</p>';
foreach ( WorkflowPresets::all() as $k => $p ) {
	$pr .= '<form method="post" class="wf-preset">' . $hidden . '<input type="hidden" name="action" value="preset"><input type="hidden" name="preset" value="' . wf_h( $k ) . '">
		<p><button type="submit" class="pure-button wf-secondary">' . wf_h( _( $p['label'] ) ) . '</button><br><small>' . wf_h( _( $p['description'] ) ) . '</small></p></form>';
}
$pr .= '<form method="post">' . $hidden . '<input type="hidden" name="action" value="preset"><input type="hidden" name="preset" value="all"><p><button type="submit" class="pure-button pure-button-primary">' . _( "Apply all three (recommended)" ) . '</button></p></form>';
makePluslet( _( "Presets" ), $pr, "no_overflow" );

// ---- requests
global $BaseURL;
$reqUrl = rtrim( (string) $BaseURL, '/' ) . '/subjects/request.php';
$rq     = '<p>' . _( "Public form for suggesting a new e-resource:" ) . '<br><a href="' . wf_h( $reqUrl ) . '" target="_blank">' . wf_h( $reqUrl ) . '</a></p>';
$rows   = $conn->query( "SELECT r.*, t.title, (SELECT l.record_status FROM location_title lt JOIN location l ON l.location_id = lt.location_id WHERE lt.title_id = r.title_id LIMIT 1) AS st FROM resource_request r LEFT JOIN title t ON t.title_id = r.title_id ORDER BY r.created_at DESC LIMIT 10" )->fetchAll( PDO::FETCH_ASSOC );
$rq    .= $rows ? '<ul>' : '<p>' . _( "No requests yet." ) . '</p>';
foreach ( $rows as $r ) {
	$rq .= '<li><a href="../records/record.php?record_id=' . (int) $r['title_id'] . '">' . wf_h( $r['title'] ) . '</a> — ' . wf_h( $r['name'] ) . ', ' . wf_h( date( 'j M', strtotime( $r['created_at'] ) ) ) . ' ' . ( $r['st'] ? $badge( $r['st'] ) : '' ) . '</li>';
}
$rq .= $rows ? '</ul>' : '';
makePluslet( _( "Recent requests" ), $rq, "no_overflow" );

print '</div><div class="pure-u-1 pure-u-lg-2-3">';

// ---- transitions
$tr  = '<p>' . _( "Each row is a button on the record page: from one status (or any) to another. Only the chosen group of staff sees the button; administrators see all of them. While this list is empty, staff can set any status." ) . '</p>';
$tr .= '<form method="post">' . $hidden . '<input type="hidden" name="action" value="transitions"><div class="wf-scroll"><table class="pure-table pure-table-horizontal wf-table"><thead><tr><th>' . _( "From" ) . '</th><th>' . _( "To" ) . '</th><th>' . _( "Button label" ) . '</th><th>' . _( "Who" ) . '</th><th>' . _( "Comment required" ) . '</th><th>' . _( "Email notice" ) . '</th><th>' . _( "Order" ) . '</th><th>' . _( "Delete" ) . '</th></tr></thead><tbody>';
foreach ( $conn->query( "SELECT * FROM workflow_transition ORDER BY from_status_id IS NULL, from_status_id, sort_order, transition_id" )->fetchAll( PDO::FETCH_ASSOC ) as $t ) {
	$id  = (int) $t['transition_id'];
	$tr .= '<tr><td>' . $sel( "t[$id][from]", $ids, (int) $t['from_status_id'], _( "(any status)" ) ) . '</td><td>' . $sel( "t[$id][to]", $ids, (int) $t['to_status_id'] ) . '</td><td><input type="text" name="t[' . $id . '][label]" value="' . wf_h( $t['label'] ) . '" size="18"></td><td>' . $sel( "t[$id][role]", Workflow::ROLES, $t['role'] ) . '</td><td>' . $chk( "t[$id][comment]", $t['require_comment'] ) . '</td><td>' . $chk( "t[$id][notify]", $t['notify'] ) . '</td><td><input type="number" name="t[' . $id . '][sort]" value="' . (int) $t['sort_order'] . '" style="width:4em"></td><td>' . $chk( "t[$id][delete]", false ) . '</td></tr>';
}
$tr .= '<tr class="wf-new"><td>' . $sel( 'newt[from]', $ids, 0, _( "(any status)" ) ) . '</td><td>' . $sel( 'newt[to]', $ids, 0, _( "— add —" ) ) . '</td><td><input type="text" name="newt[label]" size="18" placeholder="' . _( "e.g. Submit for review" ) . '"></td><td>' . $sel( 'newt[role]', Workflow::ROLES, 'records' ) . '</td><td>' . $chk( 'newt[comment]', false ) . '</td><td>' . $chk( 'newt[notify]', false ) . '</td><td colspan="2"></td></tr></tbody></table></div>
<p><button type="submit" class="pure-button pure-button-primary">' . _( "Save transitions" ) . '</button></p></form>';
makePluslet( _( "Transitions — who can move a record where" ), $tr, "no_overflow" );

// ---- rules
$sources = Workflow::dateSources();
$ru      = '<p>' . _( "Rules run once a day (schedule <code>php tools/workflow-daily.php</code>) and fire once per record for each date: when today is on or after the date plus the offset (use a negative number for “days before”). Leave “Move to” empty for a reminder email only." ) . '</p>';
$ru     .= '<form method="post">' . $hidden . '<input type="hidden" name="action" value="rules"><div class="wf-scroll"><table class="pure-table pure-table-horizontal wf-table"><thead><tr><th>' . _( "Name" ) . '</th><th>' . _( "When status is" ) . '</th><th>' . _( "Date" ) . '</th><th>' . _( "Days (±)" ) . '</th><th>' . _( "Move to" ) . '</th><th>' . _( "Email" ) . '</th><th>' . _( "Extra recipients" ) . '</th><th>' . _( "Active" ) . '</th><th>' . _( "Delete" ) . '</th></tr></thead><tbody>';
foreach ( $conn->query( "SELECT * FROM workflow_rule ORDER BY rule_id" )->fetchAll( PDO::FETCH_ASSOC ) as $r ) {
	$id  = (int) $r['rule_id'];
	$ru .= '<tr><td><input type="text" name="r[' . $id . '][name]" value="' . wf_h( $r['name'] ) . '" size="16"></td><td>' . $sel( "r[$id][status]", $ids, (int) $r['status_id'] ) . '</td><td>' . $sel( "r[$id][source]", $sources, $r['date_source'] ) . '</td><td><input type="number" name="r[' . $id . '][offset]" value="' . (int) $r['offset_days'] . '" style="width:5em"></td><td>' . $sel( "r[$id][to]", $ids, (int) $r['to_status_id'], _( "(reminder only)" ) ) . '</td><td>' . $chk( "r[$id][notify]", $r['notify'] ) . '</td><td><input type="text" name="r[' . $id . '][emails]" value="' . wf_h( $r['notify_emails'] ) . '" size="14"></td><td>' . $chk( "r[$id][active]", $r['active'] ) . '</td><td>' . $chk( "r[$id][delete]", false ) . '</td></tr>';
}
$ru .= '<tr class="wf-new"><td><input type="text" name="newr[name]" size="16" placeholder="' . _( "Add a rule…" ) . '"></td><td>' . $sel( 'newr[status]', $ids, 0, _( "—" ) ) . '</td><td>' . $sel( 'newr[source]', $sources, '' ) . '</td><td><input type="number" name="newr[offset]" value="0" style="width:5em"></td><td>' . $sel( 'newr[to]', $ids, 0, _( "(reminder only)" ) ) . '</td><td>' . $chk( 'newr[notify]', true ) . '</td><td><input type="text" name="newr[emails]" size="14"></td><td colspan="2"></td></tr></tbody></table></div>
<p><button type="submit" class="pure-button pure-button-primary">' . _( "Save rules" ) . '</button></p></form>
<form method="post">' . $hidden . '<input type="hidden" name="action" value="run"><p><button type="submit" class="pure-button wf-secondary">' . _( "Run the rules now" ) . '</button></p></form>';
if ( $runLog !== null ) {
	$ru .= '<div class="wf-runlog"><strong>' . _( "Result" ) . ':</strong> ' . ( $runLog ? '<ul><li>' . implode( '</li><li>', array_map( 'wf_h', $runLog ) ) . '</li></ul>' : _( "nothing was due." ) ) . '</div>';
}
makePluslet( _( "Date rules — trials, renewals, reminders" ), $ru, "no_overflow" );

print '</div></div>';
?>
<style>
.wf-badge{display:inline-block;padding:.1em .5em;border-radius:1em;font-size:.85em;white-space:nowrap}
.wf-counts{list-style:none;padding:0}.wf-counts li{margin:.2em 0}.wf-new{background:#f6f8fb}.wf-table td{vertical-align:middle}
.wf-runlog{background:#f6f8fb;padding:.5em;border-radius:4px}
.wf-secondary{background:#fff;color:#222;border:1px solid #888}.wf-secondary:hover{background:#eee}
.wf-scroll{overflow-x:auto;max-width:100%}.wf-table{font-size:.9em}.wf-table select,.wf-table input[type=text]{max-width:11em}
</style>
<?php
include( "../includes/footer.php" );
