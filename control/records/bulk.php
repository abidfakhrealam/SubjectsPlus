<?php
/**
 * @file bulk.php
 * @brief Records → Find & bulk edit: search records (title, alternate title, vendor / publisher, URL), filter them,
 *        and change many at once (status, vendor, format, access, A–Z display, subject, delete).
 * @since 4.7.1
 */

use SubjectsPlus\Control\AuditLog;
use SubjectsPlus\Control\Record;
use SubjectsPlus\Control\RecordSettings;
use SubjectsPlus\Control\RecordVendor;
use SubjectsPlus\Control\Schema;
use SubjectsPlus\Control\Workflow;

$subcat     = "records";
$subsubcat  = "bulk.php";
$page_title = "Find & Bulk Edit";

include( "../includes/header.php" );

$conn    = Schema::conn();
$csrf    = sp_csrf_token();
$isMgr   = ! empty( $_SESSION['eresource_mgr'] );
$ready   = RecordSettings::ready();
$vReady  = RecordVendor::ready();
$msgs    = array();
$errs    = array();
$perPage = 100;

$g = function ( $k, $d = '' ) {
	return isset( $_GET[ $k ] ) && is_string( $_GET[ $k ] ) ? trim( $_GET[ $k ] ) : $d;
};

$formats = array();
foreach ( RecordSettings::formats( true ) as $f ) {
	$formats[ (int) $f[0] ] = $f[1];
}
$restr = array();
foreach ( RecordSettings::restrictions( true ) as $r ) {
	$restr[ (int) $r[0] ] = $r[1];
}
$statuses = $ready ? array_column( RecordSettings::statuses( true ), 'name' ) : array();
$subjects = $conn->query( "SELECT subject_id, subject FROM subject WHERE type = 'Subject' ORDER BY subject" )->fetchAll( PDO::FETCH_KEY_PAIR );
$sources  = $conn->query( "SELECT source_id, source FROM source ORDER BY rs, source" );
$sources  = $sources ? $sources->fetchAll( PDO::FETCH_KEY_PAIR ) : array();

// ------------------------------------------------------------------ bulk action
if ( $_SERVER['REQUEST_METHOD'] === 'POST' ) {
	$ids    = array_values( array_unique( array_filter( array_map( 'intval', (array) ( $_POST['ids'] ?? array() ) ) ) ) );
	$action = (string) ( $_POST['bulk_action'] ?? '' );
	if ( ! sp_csrf_valid( $_POST['csrf'] ?? '' ) ) {
		$errs[] = _( "Your session has expired. Please reload the page and try again." );
	} elseif ( ! $ids ) {
		$errs[] = _( "Tick at least one record." );
	} elseif ( count( $ids ) > 1000 ) {
		$errs[] = _( "Change at most 1,000 records at a time." );
	} else {
		$done   = 0;
		$failed = array();
		$detail = array();
		$touch  = $conn->prepare( "UPDATE title SET last_modified = NOW(), last_modified_by = ? WHERE title_id = ?" );
		switch ( $action ) {
			case 'status':
				$to      = (string) ( $_POST['to_status'] ?? '' );
				$comment = trim( (string) ( $_POST['comment'] ?? '' ) );
				if ( ! in_array( $to, $statuses, true ) ) {
					$errs[] = _( "Choose a status." );
					break;
				}
				foreach ( $ids as $id ) {
					$r = Workflow::changeStatus( $id, $to, $comment !== '' ? $comment : _( "Bulk change" ), $_SESSION['staff_id'] ?? null );
					if ( $r === true ) {
						$done++;
					} else {
						$failed[ $id ] = $r;
					}
				}
				$detail = array( 'to' => $to );
				break;
			case 'vendor':
				if ( ! $vReady ) {
					$errs[] = _( "Apply the database updates first (Admin → Database Updates)." );
					break;
				}
				$v = RecordVendor::clean( $_POST['vendor'] ?? '' );
				foreach ( $ids as $id ) {
					RecordVendor::save( $id, $v );
					$touch->execute( array( (string) ( $_SESSION['staff_id'] ?? '' ), $id ) );
					$done++;
				}
				$detail = array( 'vendor' => $v );
				break;
			case 'format':
			case 'access':
				$val = (int) ( $_POST[ $action === 'format' ? 'format_id' : 'access_id' ] ?? 0 );
				$col = $action === 'format' ? 'format' : 'access_restrictions';
				if ( ! isset( ( $action === 'format' ? $formats : $restr )[ $val ] ) ) {
					$errs[] = $action === 'format' ? _( "Choose a format." ) : _( "Choose an access restriction." );
					break;
				}
				$st = $conn->prepare( "UPDATE location l JOIN location_title lt ON lt.location_id = l.location_id SET l.$col = ? WHERE lt.title_id = ?" );
				foreach ( $ids as $id ) {
					$st->execute( array( $val, $id ) );
					$touch->execute( array( (string) ( $_SESSION['staff_id'] ?? '' ), $id ) );
					$done++;
				}
				$detail = array( $col => $val );
				break;
			case 'az_show':
			case 'az_hide':
				$st = $conn->prepare( "UPDATE location l JOIN location_title lt ON lt.location_id = l.location_id SET l.eres_display = ? WHERE lt.title_id = ?" );
				foreach ( $ids as $id ) {
					$st->execute( array( $action === 'az_show' ? 'Y' : 'N', $id ) );
					Workflow::syncVisibility( $id, true ); // a record whose status is not public stays hidden until it is
					$touch->execute( array( (string) ( $_SESSION['staff_id'] ?? '' ), $id ) );
					$done++;
				}
				break;
			case 'subject':
				$sid = (int) ( $_POST['subject_id'] ?? 0 );
				$src = (int) ( $_POST['source_id'] ?? 0 );
				if ( ! isset( $subjects[ $sid ] ) || ! isset( $sources[ $src ] ) ) {
					$errs[] = _( "Choose a subject and a category." );
					break;
				}
				$has = $conn->prepare( "SELECT COUNT(*) FROM `rank` WHERE subject_id = ? AND title_id = ?" );
				$ins = $conn->prepare( "INSERT INTO `rank` (`rank`, subject_id, title_id, source_id, description_override, dbbysub_active) VALUES (0, ?, ?, ?, '', 1)" );
				foreach ( $ids as $id ) {
					$has->execute( array( $sid, $id ) );
					if ( ! (int) $has->fetchColumn() ) {
						$ins->execute( array( $sid, $id, $src ) );
					}
					$done++;
				}
				$detail = array( 'subject_id' => $sid, 'source_id' => $src );
				break;
			case 'delete':
				if ( ! $isMgr ) {
					$errs[] = _( "Only e-resource managers can delete records." );
					break;
				}
				if ( ( $_POST['confirm_delete'] ?? '' ) !== 'DELETE' ) {
					$errs[] = _( "Type DELETE in the box to confirm." );
					break;
				}
				foreach ( $ids as $id ) {
					ob_start();
					$rec = new Record( $id, 'delete' );
					$ok  = $rec->deleteRecord();
					ob_end_clean();
					$ok ? $done++ : ( $failed[ $id ] = _( "could not be deleted" ) );
				}
				break;
			default:
				$errs[] = _( "Choose what to do with the ticked records." );
		}
		if ( ! $errs ) {
			$msgs[] = sprintf( _( "%d record(s) changed." ), $done );
			foreach ( $failed as $id => $why ) {
				$errs[] = sprintf( _( "Record %d: %s" ), $id, $why );
			}
			AuditLog::add( 'records.bulk.' . $action, 'record', implode( ',', array_slice( $ids, 0, 20 ) ) . ( count( $ids ) > 20 ? ',…' : '' ),
				sprintf( 'Bulk %s on %d record(s)', $action, count( $ids ) ), $detail + array( 'ids' => $ids, 'failed' => array_keys( $failed ) ) );
		}
	}
}

// ------------------------------------------------------------------ search
$q      = mb_substr( $g( 'q' ), 0, 200 );
$fv     = $g( 'vendor' );
$fs     = $g( 'status' );
$ff     = (int) $g( 'format', '0' );
$fa     = (int) $g( 'access', '0' );
$faz    = $g( 'az' );
$fsub   = (int) $g( 'subject_id', '0' );
$sort   = in_array( $g( 'sort' ), array( 'title', 'vendor', 'status', 'modified' ), true ) ? $g( 'sort' ) : 'title';
$page   = max( 1, (int) $g( 'page', '1' ) );
$where  = array( '1=1' );
$params = array();
if ( $q !== '' ) {
	$like     = '%' . addcslashes( $q, '%_\\' ) . '%';
	$parts    = array( 't.title LIKE ?', 't.alternate_title LIKE ?', 'l.location LIKE ?' );
	$params   = array_merge( $params, array( $like, $like, $like ) );
	if ( $vReady ) {
		$parts[]  = 't.vendor LIKE ?';
		$params[] = $like;
	}
	if ( ctype_digit( $q ) ) {
		$parts[]  = 't.title_id = ?';
		$params[] = (int) $q;
	}
	$where[] = '(' . implode( ' OR ', $parts ) . ')';
}
if ( $vReady && $fv !== '' ) {
	if ( $fv === '-' ) {
		$where[] = "(t.vendor IS NULL OR t.vendor = '')";
	} else {
		$where[]  = 't.vendor = ?';
		$params[] = $fv;
	}
}
if ( $fs !== '' ) {
	$where[]  = 'l.record_status = ?';
	$params[] = $fs;
}
if ( $ff ) {
	$where[]  = 'l.format = ?';
	$params[] = $ff;
}
if ( $fa ) {
	$where[]  = 'l.access_restrictions = ?';
	$params[] = $fa;
}
if ( $faz === 'Y' || $faz === 'N' ) {
	$where[]  = 'l.eres_display = ?';
	$params[] = $faz;
}
if ( $fsub ) {
	$where[]  = 't.title_id IN (SELECT title_id FROM `rank` WHERE subject_id = ?)';
	$params[] = $fsub;
}
$order = array( 'title' => 'MIN(t.title)', 'vendor' => ( $vReady ? 'MIN(t.vendor)' : 'MIN(t.title)' ) . ', MIN(t.title)', 'status' => 'MIN(l.record_status), MIN(t.title)', 'modified' => 'MAX(t.last_modified) DESC' )[ $sort ];
$from  = "FROM title t LEFT JOIN location_title lt ON lt.title_id = t.title_id LEFT JOIN location l ON l.location_id = lt.location_id WHERE " . implode( ' AND ', $where );
$st    = $conn->prepare( "SELECT COUNT(DISTINCT t.title_id) $from" );
$st->execute( $params );
$total = (int) $st->fetchColumn();
$pages = max( 1, (int) ceil( $total / $perPage ) );
$page  = min( $page, $pages );
// every column aggregated: MariaDB's ONLY_FULL_GROUP_BY does not recognise functional dependencies
$st = $conn->prepare( "SELECT t.title_id, MIN(t.title) AS title, " . ( $vReady ? "MIN(t.vendor)" : "''" ) . " AS vendor, MIN(l.location) AS url,
		MIN(l.record_status) AS status, MAX(l.eres_display) AS az, MIN(l.format) AS format, MIN(l.access_restrictions) AS access, MAX(t.last_modified) AS modified
	$from GROUP BY t.title_id ORDER BY $order LIMIT $perPage OFFSET " . ( ( $page - 1 ) * $perPage ) );
$st->execute( $params );
$rows = $st->fetchAll( PDO::FETCH_ASSOC );

// ------------------------------------------------------------------ render
$opt = function ( array $list, $cur, $blank ) {
	$h = $blank !== null ? '<option value="">' . sp_h( $blank ) . '</option>' : '';
	foreach ( $list as $k => $v ) {
		$h .= '<option value="' . sp_h( $k ) . '"' . ( (string) $k === (string) $cur ? ' selected' : '' ) . '>' . sp_h( $v ) . '</option>';
	}

	return $h;
};
$qs = function ( array $over ) {
	$p = array_merge( array_intersect_key( $_GET, array_flip( array( 'q', 'vendor', 'status', 'format', 'access', 'az', 'subject_id', 'sort' ) ) ), $over );

	return 'bulk.php?' . http_build_query( array_filter( $p, function ( $v ) { return $v !== '' && $v !== null; } ) );
};
$vendorOpts = $vReady ? array( '-' => _( "(no vendor)" ) ) + array_combine( RecordVendor::allVendors(), RecordVendor::allVendors() ) : array();

$filters = '<form method="get" action="bulk.php" class="pure-form pure-form-stacked bulk-filters" role="search"><div class="pure-g">
	<div class="pure-u-1 pure-u-md-1-3"><label for="bq">' . _( "Search title, alternate title, vendor, URL or ID" ) . '</label><input type="search" id="bq" name="q" value="' . sp_h( $q ) . '" class="pure-input-1"></div>'
	. ( $vReady ? '<div class="pure-u-1 pure-u-md-1-6"><label for="bv">' . _( "Vendor / Publisher" ) . '</label><select id="bv" name="vendor" class="pure-input-1">' . $opt( $vendorOpts, $fv, _( "Any" ) ) . '</select></div>' : '' )
	. ( $statuses ? '<div class="pure-u-1 pure-u-md-1-6"><label for="bs">' . _( "Status" ) . '</label><select id="bs" name="status" class="pure-input-1">' . $opt( array_combine( $statuses, $statuses ), $fs, _( "Any" ) ) . '</select></div>' : '' )
	. '<div class="pure-u-1 pure-u-md-1-6"><label for="bf">' . _( "Format" ) . '</label><select id="bf" name="format" class="pure-input-1">' . $opt( $formats, $ff ?: '', _( "Any" ) ) . '</select></div>
	<div class="pure-u-1 pure-u-md-1-6"><label for="ba">' . _( "Access" ) . '</label><select id="ba" name="access" class="pure-input-1">' . $opt( $restr, $fa ?: '', _( "Any" ) ) . '</select></div>
	<div class="pure-u-1 pure-u-md-1-6"><label for="bz">' . _( "In A–Z list" ) . '</label><select id="bz" name="az" class="pure-input-1">' . $opt( array( 'Y' => _( "Shown" ), 'N' => _( "Hidden" ) ), $faz, _( "Any" ) ) . '</select></div>
	<div class="pure-u-1 pure-u-md-1-3"><label for="bsub">' . _( "Subject" ) . '</label><select id="bsub" name="subject_id" class="pure-input-1">' . $opt( $subjects, $fsub ?: '', _( "Any" ) ) . '</select></div>
	</div><p><button type="submit" class="pure-button pure-button-primary">' . _( "Search" ) . '</button> <a class="pure-button" href="bulk.php">' . _( "Clear" ) . '</a></p></form>';

$out = '';
foreach ( $msgs as $m ) {
	$out .= '<div class="feedback" role="status">' . sp_h( $m ) . '</div>';
}
foreach ( $errs as $e ) {
	$out .= '<div class="feedback bulk-error" role="alert">' . sp_h( $e ) . '</div>';
}
$sortLink = function ( $key, $label ) use ( $qs, $sort ) {
	return '<a href="' . sp_h( $qs( array( 'sort' => $key, 'page' => null ) ) ) . '"' . ( $sort === $key ? ' aria-current="true"><strong>' . sp_h( $label ) . ' ▲</strong>' : '>' . sp_h( $label ) ) . '</a>';
};
$out .= '<p>' . sprintf( _( "%d record(s) found." ), $total ) . '</p>';
$out .= '<form method="post" action="' . sp_h( $qs( array( 'page' => $page > 1 ? $page : null ) ) ) . '" class="pure-form" id="bulk-form"><input type="hidden" name="csrf" value="' . sp_h( $csrf ) . '">';
$out .= '<div style="overflow-x:auto"><table class="pure-table pure-table-horizontal bulk-table"><caption class="sp-sr-only">' . _( "Records" ) . '</caption><thead><tr>
	<th scope="col"><input type="checkbox" id="bulk-all" aria-label="' . sp_h( _( "Select all records on this page" ) ) . '"></th>
	<th scope="col">' . $sortLink( 'title', _( "Title" ) ) . '</th>'
	. ( $vReady ? '<th scope="col">' . $sortLink( 'vendor', _( "Vendor / Publisher" ) ) . '</th>' : '' )
	. '<th scope="col">' . $sortLink( 'status', _( "Status" ) ) . '</th><th scope="col">' . _( "Format" ) . '</th><th scope="col">' . _( "Access" ) . '</th><th scope="col">' . _( "A–Z" ) . '</th><th scope="col">' . $sortLink( 'modified', _( "Modified" ) ) . '</th></tr></thead><tbody>';
foreach ( $rows as $r ) {
	$id   = (int) $r['title_id'];
	$out .= '<tr><td><input type="checkbox" name="ids[]" value="' . $id . '" class="bulk-id" aria-label="' . sp_h( sprintf( _( "Select %s" ), html_entity_decode( (string) $r['title'], ENT_QUOTES ) ) ) . '"></td>
		<td><a href="record.php?record_id=' . $id . '">' . sp_h( html_entity_decode( (string) $r['title'], ENT_QUOTES ) ) . '</a> <small class="smallgrey">#' . $id . '</small></td>'
		. ( $vReady ? '<td>' . sp_h( (string) $r['vendor'] ) . '</td>' : '' )
		. '<td>' . sp_h( (string) $r['status'] ) . '</td><td>' . sp_h( $formats[ (int) $r['format'] ] ?? '' ) . '</td><td>' . sp_h( $restr[ (int) $r['access'] ] ?? '' ) . '</td>
		<td>' . ( $r['az'] === 'Y' ? '<span title="' . sp_h( _( "Shown" ) ) . '">✓</span><span class="sp-sr-only">' . _( "Shown" ) . '</span>' : '<span class="smallgrey">—</span><span class="sp-sr-only">' . _( "Hidden" ) . '</span>' ) . '</td>
		<td><small>' . sp_h( substr( (string) $r['modified'], 0, 10 ) ) . '</small></td></tr>';
}
if ( ! $rows ) {
	$out .= '<tr><td colspan="8">' . _( "No records match." ) . '</td></tr>';
}
$out .= '</tbody></table></div>';
if ( $pages > 1 ) {
	$out .= '<nav class="bulk-pages" aria-label="' . sp_h( _( "Pages" ) ) . '">';
	for ( $i = 1; $i <= $pages; $i++ ) {
		$out .= $i === $page ? ' <strong aria-current="page">' . $i . '</strong>' : ' <a href="' . sp_h( $qs( array( 'page' => $i ) ) ) . '">' . $i . '</a>';
	}
	$out .= '</nav>';
}

// actions
$out .= '<fieldset class="bulk-actions"><legend>' . _( "With the ticked records" ) . '</legend>
	<label for="bulk_action">' . _( "Action" ) . '</label> <select name="bulk_action" id="bulk_action">
	<option value="">' . _( "Choose…" ) . '</option>'
	. ( $statuses ? '<option value="status">' . _( "Change status" ) . '</option>' : '' )
	. ( $vReady ? '<option value="vendor">' . _( "Set vendor / publisher" ) . '</option>' : '' )
	. '<option value="format">' . _( "Set format" ) . '</option><option value="access">' . _( "Set access restriction" ) . '</option>
	<option value="az_show">' . _( "Show in the A–Z list" ) . '</option><option value="az_hide">' . _( "Hide from the A–Z list" ) . '</option>
	<option value="subject">' . _( "Add to a subject" ) . '</option>'
	. ( $isMgr ? '<option value="delete">' . _( "Delete records" ) . '</option>' : '' ) . '</select>
	<span class="bulk-arg" data-for="status">' . ( $statuses ? '<label>' . _( "to" ) . ' <select name="to_status">' . $opt( array_combine( $statuses, $statuses ), '', null ) . '</select></label> <label>' . _( "Comment" ) . ' <input type="text" name="comment" size="24"></label>' : '' ) . '</span>
	<span class="bulk-arg" data-for="vendor"><label>' . _( "Vendor / Publisher" ) . ' <input type="text" name="vendor" list="bulk_vendor_list" maxlength="255" placeholder="' . sp_h( _( "empty = remove" ) ) . '"></label><datalist id="bulk_vendor_list">' . implode( '', array_map( function ( $v ) { return '<option value="' . sp_h( $v ) . '">'; }, RecordVendor::allVendors() ) ) . '</datalist></span>
	<span class="bulk-arg" data-for="format"><label>' . _( "Format" ) . ' <select name="format_id">' . $opt( $formats, '', null ) . '</select></label></span>
	<span class="bulk-arg" data-for="access"><label>' . _( "Access" ) . ' <select name="access_id">' . $opt( $restr, '', null ) . '</select></label></span>
	<span class="bulk-arg" data-for="subject"><label>' . _( "Subject" ) . ' <select name="subject_id">' . $opt( $subjects, '', null ) . '</select></label> <label>' . _( "Category" ) . ' <select name="source_id">' . $opt( $sources, '', null ) . '</select></label></span>
	<span class="bulk-arg" data-for="delete"><label>' . _( "Type DELETE to confirm" ) . ' <input type="text" name="confirm_delete" size="8" autocomplete="off"></label></span>
	<button type="submit" class="pure-button pure-button-primary">' . _( "Apply" ) . '</button></fieldset></form>';

print '<div class="pure-g"><div class="pure-u-1">';
makePluslet( _( "Find records" ), $filters, "no_overflow" );
makePluslet( _( "Records" ), $out, "no_overflow" );
print '</div></div>';
?>
<style>
.bulk-table td,.bulk-table th{vertical-align:middle}.bulk-error{background:#fdecea}.bulk-actions{margin-top:1em;border:1px solid #ccd;border-radius:6px;padding:.6em 1em}
.bulk-actions label{margin-right:.6em}.bulk-arg{display:none;margin-left:.6em}.bulk-arg.on{display:inline}.bulk-pages{margin:.8em 0}
</style>
<script src="<?php echo sp_h( $AssetPath . 'js/admin/bulk-records.js?v=' . rawurlencode( sp_version() ) ); ?>" defer></script>
<?php
include( "../includes/footer.php" );
