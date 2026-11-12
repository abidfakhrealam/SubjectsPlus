<?php
/**
 * @file import_export.php
 * @brief import and export as Excel (.xlsx) or CSV — e-resource records, usage reports,
 *        guides, staff, FAQs, and KBART title lists from publishers.
 *
 * Imports always show a check (preview) first; nothing is written until the librarian confirms.
 */

use SubjectsPlus\Control\Querier;
use SubjectsPlus\Control\Spreadsheet;
use SubjectsPlus\Control\RecordSettings;
use SubjectsPlus\Control\Workflow;
use SubjectsPlus\Control\DataExchange\RecordsExchange;
use SubjectsPlus\Control\DataExchange\ListsExchange;
use SubjectsPlus\Control\DataExchange\ReportsExchange;
use SubjectsPlus\Control\DataExchange\KbartImport;

$subsubcat  = "";
$subcat     = "records";
$page_title = "Import & Export";

// downloads must be sent before any page output
$sp_ie_download = isset( $_GET['download'] ) || ( isset( $_POST['step'] ) && $_POST['step'] === 'report' );
if ( $sp_ie_download ) {
	$header = "noshow";
}
include( "../includes/header.php" );

function ie_h( $s ) {
	return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
}

$isAdmin   = ! empty( $_SESSION['admin'] );
$can       = array(
	'records' => Workflow::hasRole( 'records' ),
	'import'  => $isAdmin || ! empty( $_SESSION['eresource_mgr'] ),
	'staff'   => $isAdmin,
	'faqs'    => $isAdmin || ! empty( $_SESSION['faq'] ),
);
$staffId   = (int) ( $_SESSION['staff_id'] ?? 0 );
$fmt       = ( $_REQUEST['fmt'] ?? 'xlsx' ) === 'csv' ? 'csv' : 'xlsx';

if ( empty( $_SESSION['sp_ie_csrf'] ) ) {
	$_SESSION['sp_ie_csrf'] = bin2hex( random_bytes( 32 ) );
}
$csrf = $_SESSION['sp_ie_csrf'];

/* ============================================================ downloads */

if ( isset( $_GET['download'] ) ) {
	$what = (string) $_GET['download'];
	switch ( $what ) {
		case 'records':
			if ( ! $can['records'] ) {
				break;
			}
			$rows = RecordsExchange::exportRows( array(
				'subject_id' => (int) ( $_GET['subject_id'] ?? 0 ),
				'status'     => (string) ( $_GET['status'] ?? '' ),
				'format_id'  => (int) ( $_GET['format_id'] ?? 0 ),
			) );
			Spreadsheet::send( 'records', array(
				array( 'name' => 'Records', 'rows' => $rows ),
				array( 'name' => 'Lists', 'rows' => RecordsExchange::listsRows() ),
				array( 'name' => 'Help', 'rows' => RecordsExchange::helpRows() ),
			), $fmt );
		case 'template':
			if ( ! $can['records'] ) {
				break;
			}
			$all     = RecordsExchange::exportRows();
			$example = array();
			foreach ( $all[0] as $h ) {
				$example[] = array( 'Title' => 'Example Database (delete this row)', 'URL' => 'https://www.example.com/', 'Format' => RecordSettings::formats()[0][1] ?? 'Web',
				                    'Access' => 'Restricted', 'Status' => RecordSettings::initialStatus(), 'Show in A-Z' => 'Yes', 'Description' => 'What the database contains and who it is for.' )[ $h ] ?? '';
			}
			Spreadsheet::send( 'records-template', array(
				array( 'name' => 'Records', 'rows' => array( $all[0], $example ) ),
				array( 'name' => 'Lists', 'rows' => RecordsExchange::listsRows() ),
				array( 'name' => 'Help', 'rows' => RecordsExchange::helpRows() ),
			), $fmt );
		case 'report':
			if ( ! $can['records'] ) {
				break;
			}
			$from = preg_match( '/^\d{4}-\d{2}$/', $_GET['from'] ?? '' ) ? $_GET['from'] : ReportsExchange::academicYear()[0];
			$to   = preg_match( '/^\d{4}-\d{2}$/', $_GET['to'] ?? '' ) ? $_GET['to'] : date( 'Y-m' );
			if ( $to < $from ) {
				list( $from, $to ) = array( $to, $from );
			}
			$sheets = ReportsExchange::workbook( $from, $to );
			if ( $fmt === 'csv' ) {
				$pick = (int) ( $_GET['sheet'] ?? 1 );
				$sheets = array( $sheets[ max( 0, min( count( $sheets ) - 1, $pick ) ) ] );
			}
			Spreadsheet::send( 'usage-' . $from . '-to-' . $to, $sheets, $fmt );
		case 'guides':
			if ( ! $can['records'] ) {
				break;
			}
			$sheets = ListsExchange::guideSheets();
			Spreadsheet::send( 'guides', $fmt === 'csv' ? array( $sheets[ (int) ( $_GET['sheet'] ?? 0 ) === 1 ? 1 : 0 ] ) : $sheets, $fmt );
		case 'staff':
			if ( ! $can['records'] ) {
				break;
			}
			Spreadsheet::send( 'staff', array( array( 'name' => 'Staff', 'rows' => ListsExchange::staffRows() ) ), $fmt );
		case 'faqs':
			if ( ! $can['records'] && ! $can['faqs'] ) {
				break;
			}
			Spreadsheet::send( 'faqs', array( array( 'name' => 'FAQs', 'rows' => ListsExchange::faqRows() ) ), $fmt );
	}
	http_response_code( 403 );
	exit( _( "You do not have permission to download this." ) );
}

/* ============================================================ imports */

$kinds = array(
	'records' => array( 'label' => _( "e-resource records" ), 'can' => $can['import'] ),
	'kbart'   => array( 'label' => _( "KBART title list" ), 'can' => $can['import'] ),
	'staff'   => array( 'label' => _( "staff" ), 'can' => $can['staff'] ),
	'faqs'    => array( 'label' => _( "FAQs" ), 'can' => $can['faqs'] ),
);

function ie_tmpdir() {
	$d = rtrim( sys_get_temp_dir(), '/\\' ) . DIRECTORY_SEPARATOR . 'sp-import';
	if ( ! is_dir( $d ) ) {
		@mkdir( $d, 0700, true );
	}
	// remove leftovers older than a day
	foreach ( (array) glob( $d . DIRECTORY_SEPARATOR . 'imp-*' ) as $f ) {
		if ( is_file( $f ) && filemtime( $f ) < time() - 86400 ) {
			@unlink( $f );
		}
	}

	return $d;
}

/** Reads the stored upload and works out the plan for it. */
function ie_plan( array $job ) {
	$rows = Spreadsheet::read( $job['path'], $job['name'] );
	switch ( $job['kind'] ) {
		case 'records':
			return RecordsExchange::plan( $rows, $job['opt'] );
		case 'kbart':
			$problem = null;
			$conv    = KbartImport::toRecordRows( $rows, $job['opt'], $problem );
			if ( $problem ) {
				return array( 'rows' => array(), 'errors' => array( $problem ), 'unknown' => array(), 'counts' => array( 'add' => 0, 'update' => 0, 'skip' => 0, 'error' => 0 ) );
			}

			return RecordsExchange::plan( $conv, array( 'match' => 'url', 'protect' => array( 'format', 'access', 'status', 'az', 'subjects', 'source', 'ctags' ) ) + $job['opt'] );
		case 'staff':
			return ListsExchange::staffPlan( $rows, $job['opt'] );
		case 'faqs':
			return ListsExchange::faqPlan( $rows, $job['opt'] );
	}

	return array( 'rows' => array(), 'errors' => array( 'Unknown import type' ), 'unknown' => array(), 'counts' => array() );
}

$ieMessage = '';
$ieOutput  = '';
$step      = $_POST['step'] ?? '';
if ( $step !== '' ) {
	if ( ! isset( $_POST['csrf'] ) || ! hash_equals( $csrf, (string) $_POST['csrf'] ) ) {
		$ieMessage = _( "The form expired. Please try again." );
		$step      = '';
	}
}

if ( $step === 'preview' ) {
	$kind = (string) ( $_POST['kind'] ?? '' );
	$up   = $_FILES['file'] ?? null;
	if ( ! isset( $kinds[ $kind ] ) || ! $kinds[ $kind ]['can'] ) {
		$ieMessage = _( "You do not have permission to import this." );
	} elseif ( ! $up || ( $up['error'] ?? 1 ) !== UPLOAD_ERR_OK ) {
		$ieMessage = ( $up && in_array( $up['error'], array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true ) )
			? _( "The file is larger than the server allows (upload_max_filesize in php.ini)." )
			: _( "Please choose a file (.xlsx or .csv)." );
	} elseif ( ! preg_match( '/\.(xlsx|csv|tsv|txt)$/i', (string) $up['name'] ) ) {
		$ieMessage = _( "Please upload an Excel workbook (.xlsx) or a CSV/TSV file." );
	} else {
		$token = bin2hex( random_bytes( 12 ) );
		$path  = ie_tmpdir() . DIRECTORY_SEPARATOR . 'imp-' . $token;
		if ( ! move_uploaded_file( $up['tmp_name'], $path ) ) {
			$ieMessage = _( "The uploaded file could not be stored on the server (check the temp folder)." );
		} else {
			$opt = array(
				'mode'   => in_array( $_POST['mode'] ?? '', array( 'both', 'add', 'update' ), true ) ? $_POST['mode'] : 'both',
				'match'  => in_array( $_POST['match'] ?? '', array( 'url', 'title', 'none' ), true ) ? $_POST['match'] : 'url',
				'blanks' => ( $_POST['blanks'] ?? '' ) === 'clear' ? 'clear' : 'keep',
			);
			if ( $kind === 'kbart' ) {
				foreach ( array( 'format', 'access', 'status', 'subjects', 'category', 'free_access' ) as $k ) {
					$opt[ $k ] = trim( (string) ( $_POST[ 'k_' . $k ] ?? '' ) );
				}
			}
			$_SESSION['sp_ie_jobs'][ $token ] = array( 'kind' => $kind, 'path' => $path, 'name' => basename( (string) $up['name'] ), 'opt' => $opt, 'staff' => $staffId );
			$step                             = 'show';
			$_POST['token']                   = $token;
		}
	}
}

$job = null;
if ( in_array( $step, array( 'show', 'apply', 'report' ), true ) ) {
	$token = (string) ( $_POST['token'] ?? '' );
	$job   = $_SESSION['sp_ie_jobs'][ $token ] ?? null;
	if ( ! $job || ! is_file( $job['path'] ) || (int) $job['staff'] !== $staffId || ! $kinds[ $job['kind'] ]['can'] ) {
		$ieMessage = _( "This import has expired. Please upload the file again." );
		$job       = null;
		$step      = '';
	}
}

if ( $job ) {
	try {
		$plan = ie_plan( $job );
	} catch ( \RuntimeException $e ) {
		$plan      = null;
		$ieMessage = $e->getMessage();
		@unlink( $job['path'] );
		unset( $_SESSION['sp_ie_jobs'][ $token ] );
	}
}

if ( $sp_ie_download && ( ! $job || ! $plan ) ) {
	exit( ie_h( $ieMessage !== '' ? $ieMessage : _( "Nothing to download." ) ) );
}

if ( $job && $plan && $step === 'report' ) {
	$out = array( array( _( "Row" ), _( "What will happen" ), _( "Record" ), _( "Messages" ) ) );
	foreach ( $plan['rows'] as $n => $r ) {
		$out[] = array( $n, $r['action'], $r['title'], implode( ' ', $r['messages'] ) );
	}
	Spreadsheet::send( 'import-check', array( array( 'name' => 'Check', 'rows' => $out ) ), 'xlsx' );
}

$labels = array( 'add' => _( "Add" ), 'update' => _( "Update" ), 'skip' => _( "Skip" ), 'error' => _( "Problem" ) );

if ( $job && $plan && $step === 'apply' ) {
	switch ( $job['kind'] ) {
		case 'records':
		case 'kbart':
			$done = RecordsExchange::apply( $plan, $staffId, $job['name'] );
			break;
		case 'staff':
			$done = ListsExchange::staffApply( $plan, $staffId );
			break;
		default:
			$done = ListsExchange::faqApply( $plan, $staffId );
	}
	@unlink( $job['path'] );
	unset( $_SESSION['sp_ie_jobs'][ $token ] );
	$ieOutput .= '<div class="ie-done" role="status"><strong>' . _( "Import finished." ) . '</strong> '
		. sprintf( _( "%1\$d added, %2\$d updated, %3\$d not saved because of problems, %4\$d skipped." ), $done['add'], $done['update'], $plan['counts']['error'] + $done['failed'], $plan['counts']['skip'] )
		. '</div>';
	$ieOutput .= '<table class="pure-table pure-table-horizontal ie-table"><thead><tr><th>' . _( "Row" ) . '</th><th>' . _( "Result" ) . '</th><th>' . _( "Record" ) . '</th><th>' . _( "Details" ) . '</th></tr></thead><tbody>';
	foreach ( $plan['rows'] as $n => $r ) {
		$res  = $r['action'];
		$text = implode( ' ', $r['messages'] );
		if ( isset( $done['messages'][ $n ] ) ) {
			$text = $done['messages'][ $n ];
			if ( strpos( $text, _( "Could not be saved:" ) ) === 0 ) {
				$res = 'error';
			}
		}
		$ieOutput .= '<tr class="ie-' . $res . '"><td>' . (int) $n . '</td><td><span class="ie-badge ie-b-' . $res . '">' . ie_h( $labels[ $res ] ?? $res ) . '</span></td><td>' . ie_h( $r['title'] ) . ( $r['id'] && in_array( $job['kind'], array( 'records', 'kbart' ), true ) ? ' <a href="record.php?record_id=' . (int) $r['id'] . '">#' . (int) $r['id'] . '</a>' : '' ) . '</td><td>' . ie_h( $text ) . '</td></tr>';
	}
	$ieOutput .= '</tbody></table>';
	$job = null;
}

if ( $job && $plan && $step === 'show' ) {
	$c         = $plan['counts'];
	$ieOutput .= '<h2 class="ie-h">' . sprintf( _( "Check of %s" ), ie_h( $job['name'] ) ) . '</h2>';
	foreach ( $plan['errors'] as $e ) {
		$ieOutput .= '<p class="ie-error" role="alert">' . ie_h( $e ) . '</p>';
	}
	if ( ! empty( $plan['unknown'] ) ) {
		$ieOutput .= '<p class="ie-note">' . _( "These columns are not recognised and will be ignored:" ) . ' <em>' . ie_h( implode( ', ', $plan['unknown'] ) ) . '</em></p>';
	}
	if ( $plan['rows'] ) {
		$ieOutput .= '<p class="ie-summary">'
			. '<span class="ie-badge ie-b-add">' . sprintf( _( "%d to add" ), $c['add'] ) . '</span> '
			. '<span class="ie-badge ie-b-update">' . sprintf( _( "%d to update" ), $c['update'] ) . '</span> '
			. '<span class="ie-badge ie-b-skip">' . sprintf( _( "%d skipped" ), $c['skip'] ) . '</span> '
			. '<span class="ie-badge ie-b-error">' . sprintf( _( "%d with problems" ), $c['error'] ) . '</span></p>';
		$hidden = '<input type="hidden" name="csrf" value="' . ie_h( $csrf ) . '"><input type="hidden" name="token" value="' . ie_h( $_POST['token'] ) . '">';
		$ieOutput .= '<div class="ie-actions">';
		if ( $c['add'] + $c['update'] > 0 ) {
			$ieOutput .= '<form method="post" style="display:inline">' . $hidden . '<input type="hidden" name="step" value="apply"><button class="pure-button pure-button-primary" type="submit">'
				. sprintf( _( "Import %d row(s) now" ), $c['add'] + $c['update'] ) . '</button></form> ';
		}
		$ieOutput .= '<form method="post" style="display:inline">' . $hidden . '<input type="hidden" name="step" value="report"><button class="pure-button ie-secondary" type="submit">' . _( "Download this check (Excel)" ) . '</button></form> '
			. '<a class="pure-button ie-secondary" href="import_export.php?tab=' . ie_h( $job['kind'] === 'kbart' ? 'kbart' : $job['kind'] ) . '">' . _( "Cancel" ) . '</a></div>';
		if ( $c['error'] ) {
			$ieOutput .= '<p class="ie-note">' . _( "Rows with problems are not imported. Correct them in the file and upload it again, or import the rest now." ) . '</p>';
		}
		$ieOutput .= '<table class="pure-table pure-table-horizontal ie-table"><thead><tr><th>' . _( "Row" ) . '</th><th>' . _( "What will happen" ) . '</th><th>' . _( "Record" ) . '</th><th>' . _( "Details" ) . '</th></tr></thead><tbody>';
		$shown = 0;
		// problems first, so they are not missed in a long file
		$ordered = $plan['rows'];
		uasort( $ordered, function ( $a, $b ) { return ( $b['action'] === 'error' ) <=> ( $a['action'] === 'error' ); } );
		foreach ( $ordered as $n => $r ) {
			if ( ++$shown > 500 ) {
				$ieOutput .= '<tr><td colspan="4">' . sprintf( _( "… and %d more rows (download the check to see them all)." ), count( $ordered ) - 500 ) . '</td></tr>';
				break;
			}
			$ieOutput .= '<tr class="ie-' . $r['action'] . '"><td>' . (int) $n . '</td><td><span class="ie-badge ie-b-' . $r['action'] . '">' . ie_h( $labels[ $r['action'] ] ) . '</span></td><td>'
				. ie_h( $r['title'] ) . ( $r['id'] ? ' <small>#' . (int) $r['id'] . '</small>' : '' ) . '</td><td>' . ie_h( implode( ' ', $r['messages'] ) ) . '</td></tr>';
		}
		$ieOutput .= '</tbody></table>';
	}
}

/* ============================================================ page */

$tabs = array(
	'records' => _( "E-resource records" ),
	'kbart'   => _( "KBART title lists" ),
	'reports' => _( "Usage reports" ),
	'guides'  => _( "Guides" ),
	'staff'   => _( "Staff" ),
	'faqs'    => _( "FAQs" ),
);
$tab = $_GET['tab'] ?? ( $job ? ( $job['kind'] ) : 'records' );
if ( ! isset( $tabs[ $tab ] ) ) {
	$tab = 'records';
}
print <<<'CSS'
<style>
.ie-tabs .ie-tab{background:#fff;color:#333;border:1px solid #888}
.ie-secondary{background:#fff;color:#333;border:1px solid #888}
.ie-note{color:#555;font-size:.92em}
.ie-error{background:#fdecea;border-left:4px solid #c62828;padding:8px 12px}
.ie-done{background:#e8f5e9;border-left:4px solid #2e7d32;padding:8px 12px;margin-bottom:10px}
.ie-form input[type=file]{margin-bottom:8px}
.ie-fmt label{display:inline-block !important;margin-right:1.2em}
.ie-table{width:100%;margin-top:10px;font-size:.92em}
.ie-table td{vertical-align:top}
.ie-badge{display:inline-block;padding:1px 8px;border-radius:10px;font-size:.85em;font-weight:600;white-space:nowrap}
.ie-b-add{background:#e3f2fd;color:#0d47a1}.ie-b-update{background:#e8f5e9;color:#1b5e20}
.ie-b-skip{background:#eee;color:#555}.ie-b-error{background:#fdecea;color:#b71c1c}
tr.ie-error td{background:#fff7f6}
.ie-actions{margin:10px 0}
.ie-summary .ie-badge{font-size:.95em;padding:3px 10px}
</style>
CSS;
print '<div class="pure-g"><div class="pure-u-1"><p class="ie-tabs">';
foreach ( $tabs as $k => $label ) {
	print $k === $tab && ! $ieOutput ? '<strong class="pure-button pure-button-primary">' . ie_h( $label ) . '</strong> ' : '<a class="pure-button ie-tab" href="import_export.php?tab=' . $k . '">' . ie_h( $label ) . '</a> ';
}
print '</p></div></div>';

if ( $ieMessage !== '' ) {
	print '<p class="ie-error" role="alert">' . ie_h( $ieMessage ) . '</p>';
}
if ( $ieOutput !== '' ) {
	print '<div class="pure-g"><div class="pure-u-1">';
	makePluslet( _( "Import" ), $ieOutput, "no_overflow" );
	print '</div></div>';
	include( "../includes/footer.php" );
	exit;
}

$fmtPick = function ( $name = 'fmt' ) {
	return '<span class="ie-fmt"><label><input type="radio" name="' . $name . '" value="xlsx" checked> Excel (.xlsx)</label><label><input type="radio" name="' . $name . '" value="csv"> CSV</label></span>';
};
$importForm = function ( $kind, $extra = '', $showMatch = true ) use ( $csrf ) {
	$f = '<form method="post" enctype="multipart/form-data" class="pure-form pure-form-stacked ie-form">
		<input type="hidden" name="csrf" value="' . ie_h( $csrf ) . '"><input type="hidden" name="step" value="preview"><input type="hidden" name="kind" value="' . $kind . '">
		<label for="ie-file-' . $kind . '">' . _( "File (.xlsx or .csv)" ) . '</label>
		<input type="file" id="ie-file-' . $kind . '" name="file" accept=".xlsx,.csv,.tsv,.txt" required>' . $extra . '
		<label for="ie-mode-' . $kind . '">' . _( "What to do" ) . '</label>
		<select name="mode" id="ie-mode-' . $kind . '"><option value="both">' . _( "Add new and update existing" ) . '</option><option value="add">' . _( "Only add new" ) . '</option><option value="update">' . _( "Only update existing" ) . '</option></select>';
	if ( $showMatch ) {
		$f .= '<label for="ie-match-' . $kind . '">' . _( "Rows without a Record ID match existing records by" ) . '</label>
		<select name="match" id="ie-match-' . $kind . '"><option value="url">' . _( "URL" ) . '</option><option value="title">' . _( "Title (exact)" ) . '</option><option value="none">' . _( "Nothing — always add" ) . '</option></select>
		<label for="ie-blanks-' . $kind . '">' . _( "Empty cells when updating" ) . '</label>
		<select name="blanks" id="ie-blanks-' . $kind . '"><option value="keep">' . _( "Leave the current value" ) . '</option><option value="clear">' . _( "Clear the value" ) . '</option></select>';
	}

	return $f . '<button type="submit" class="pure-button pure-button-primary">' . _( "Check the file" ) . '</button>
		<p class="ie-note">' . _( "Nothing is changed yet: you will see what would happen first." ) . '</p></form>';
};
$noPerm = '<p class="ie-note">' . _( "You can export, but importing needs the e-resource manager or admin permission." ) . '</p>';

$db = ( new Querier )->getConnection();
print '<div class="pure-g">';
switch ( $tab ) {
	case 'records':
		$subjOpts = '<option value="">' . _( "All subjects" ) . '</option>';
		foreach ( $db->query( "SELECT subject_id, subject FROM subject ORDER BY subject" )->fetchAll( PDO::FETCH_NUM ) as $s ) {
			$subjOpts .= '<option value="' . (int) $s[0] . '">' . ie_h( html_entity_decode( $s[1], ENT_QUOTES ) ) . '</option>';
		}
		$statOpts = '<option value="">' . _( "All statuses" ) . '</option>';
		foreach ( array_keys( RecordSettings::statuses( true ) ) as $s ) {
			$statOpts .= '<option>' . ie_h( $s ) . '</option>';
		}
		$fmtOpts = '<option value="">' . _( "All formats" ) . '</option>';
		foreach ( RecordSettings::formats( true ) as $f ) {
			$fmtOpts .= '<option value="' . (int) $f[0] . '">' . ie_h( $f[1] ) . '</option>';
		}
		$export = '<form method="get" class="pure-form pure-form-stacked"><input type="hidden" name="download" value="records">
			<label>' . _( "Subject" ) . '</label><select name="subject_id">' . $subjOpts . '</select>
			<label>' . _( "Status" ) . '</label><select name="status">' . $statOpts . '</select>
			<label>' . _( "Format" ) . '</label><select name="format_id">' . $fmtOpts . '</select>
			<p>' . $fmtPick() . '</p>
			<button class="pure-button pure-button-primary" type="submit">' . _( "Export records" ) . '</button></form>
			<p class="ie-note">' . _( "The Excel file also has a Lists sheet (allowed values for Format, Access, Status, Subjects…) and a Help sheet. Edit it and import it back to change many records at once." ) . '</p>
			<p><a href="import_export.php?download=template">' . _( "Download an empty template (Excel)" ) . '</a></p>';
		print '<div class="pure-u-1 pure-u-lg-1-2">';
		makePluslet( _( "Export e-resource records" ), $export, "no_overflow" );
		print '</div><div class="pure-u-1 pure-u-lg-1-2">';
		makePluslet( _( "Import e-resource records" ), $can['import'] ? $importForm( 'records' )
			. '<p class="ie-note">' . _( "Status changes made by an import are recorded in each record's workflow history (\"Imported from …\"). Workflow approval steps are not applied to imports, so only e-resource managers and admins can import." ) . '</p>' : $noPerm, "no_overflow" );
		print '</div>';
		break;

	case 'kbart':
		$opt = function ( $rows, $sel = '' ) {
			$o = '';
			foreach ( $rows as $v ) {
				$o .= '<option' . ( $v === $sel ? ' selected' : '' ) . '>' . ie_h( $v ) . '</option>';
			}

			return $o;
		};
		$fmts   = array_map( function ( $f ) { return $f[1]; }, RecordSettings::formats() );
		$restrs = array_map( function ( $r ) { return $r[1]; }, RecordSettings::restrictions() );
		$cats   = array_map( function ( $s ) { return html_entity_decode( $s, ENT_QUOTES ); }, $db->query( "SELECT source FROM source ORDER BY rs, source" )->fetchAll( PDO::FETCH_COLUMN ) );
		$extra  = '<label>' . _( "Format for these titles" ) . '</label><select name="k_format">' . $opt( $fmts, 'Web' ) . '</select>
			<label>' . _( "Access" ) . '</label><select name="k_access">' . $opt( $restrs, 'Restricted' ) . '</select>
			<label>' . _( "Access for titles marked free/open (access_type F)" ) . '</label><select name="k_free_access"><option value="">' . _( "Same as above" ) . '</option>' . $opt( $restrs ) . '</select>
			<label>' . _( "Status" ) . '</label><select name="k_status">' . $opt( array_keys( RecordSettings::statuses() ), RecordSettings::initialStatus() ) . '</select>
			<label>' . _( "Subjects (optional, separated by ;)" ) . '</label><input type="text" name="k_subjects" placeholder="e.g. Law; Business">
			<label>' . _( "Subject category" ) . '</label><select name="k_category"><option value="">—</option>' . $opt( $cats, 'Journals/Magazines' ) . '</select>';
		$help   = '<p>' . _( "KBART is the standard title list that publishers and platforms provide (EBSCO, ProQuest, Springer, JSTOR, Taylor & Francis, Sage, Emerald…). Download it from the vendor's admin site (often as a .txt file — it can be uploaded as is), then import it here: each journal or e-book becomes a record with its link, ISSN/ISBN, publisher and coverage in the description." ) . '</p>'
			. '<p>' . _( "Titles already in SubjectsPlus (same link) are updated instead of duplicated. If you create custom fields named ISSN, eISSN, Publisher or Coverage under Record Settings, those values go into them too." ) . '</p>'
			. '<p class="ie-note">' . _( "Large lists add many entries to the A–Z list. Consider a status that is not public, or Show in A-Z = No, for journal-level titles." ) . '</p>';
		print '<div class="pure-u-1 pure-u-lg-1-2">';
		makePluslet( _( "Import a KBART title list" ), $can['import'] ? $importForm( 'kbart', $extra, false ) : $noPerm, "no_overflow" );
		print '</div><div class="pure-u-1 pure-u-lg-1-2">';
		makePluslet( _( "About KBART" ), $help, "no_overflow" );
		print '</div>';
		break;

	case 'reports':
		list( $ayFrom, $ayTo ) = ReportsExchange::academicYear();
		list( $pyFrom, $pyTo ) = ReportsExchange::academicYear( -1 );
		$form = '<form method="get" class="pure-form pure-form-stacked"><input type="hidden" name="download" value="report">
			<label>' . _( "From (month)" ) . '</label><input type="month" name="from" value="' . ie_h( $ayFrom ) . '" required>
			<label>' . _( "To (month)" ) . '</label><input type="month" name="to" value="' . ie_h( date( 'Y-m' ) ) . '" required>
			<p class="ie-note">' . sprintf( _( "Quick picks: <a href=\"%1\$s\">this academic year</a> · <a href=\"%2\$s\">last academic year</a> (%3\$s)" ),
				ie_h( 'import_export.php?download=report&from=' . $ayFrom . '&to=' . date( 'Y-m' ) ), ie_h( 'import_export.php?download=report&from=' . $pyFrom . '&to=' . $pyTo ), ie_h( date( 'F', mktime( 0, 0, 0, (int) substr( $ayFrom, 5, 2 ), 1 ) ) . '–' . date( 'F', mktime( 0, 0, 0, (int) substr( $ayTo, 5, 2 ), 1 ) ) ) ) . '</p>
			<p>' . $fmtPick() . '</p>
			<label>' . _( "For CSV, which table" ) . '</label><select name="sheet"><option value="0">' . _( "Summary by month" ) . '</option><option value="1" selected>' . _( "Database clicks" ) . '</option><option value="2">' . _( "Guide views" ) . '</option><option value="3">' . _( "Guide tabs" ) . '</option><option value="4">' . _( "Top links" ) . '</option><option value="5">' . _( "E-resources list" ) . '</option></select>
			<button class="pure-button pure-button-primary" type="submit">' . _( "Download report" ) . '</button></form>';
		$about = '<p>' . _( "The Excel report has one sheet each for:" ) . '</p><ul>'
			. '<li><strong>' . _( "Summary" ) . '</strong> — ' . _( "guide views, tab clicks, link clicks, database clicks and approximate visitors per month; totals of e-resources, guides, FAQs and staff." ) . '</li>'
			. '<li><strong>' . _( "Database clicks" ) . '</strong> — ' . _( "every e-resource with clicks per month (useful for renewal decisions and accreditation / annual statistics returns)." ) . '</li>'
			. '<li><strong>' . _( "Guide views, Guide tabs, Top links" ) . '</strong> — ' . _( "how guides are used." ) . '</li>'
			. '<li><strong>' . _( "E-resources list" ) . '</strong> — ' . _( "all public e-resources with access type, subjects and your custom fields (e.g. vendor, cost)." ) . '</li></ul>'
			. '<p class="ie-note">' . _( "Clicks on database links on the A–Z list and home page are counted from this version on; before, only clicks inside guides were recorded. Vendor usage statistics (COUNTER reports) remain the official source for downloads and searches." ) . '</p>';
		print '<div class="pure-u-1 pure-u-lg-1-2">';
		makePluslet( _( "Usage report" ), $form, "no_overflow" );
		print '</div><div class="pure-u-1 pure-u-lg-1-2">';
		makePluslet( _( "What is in the report" ), $about, "no_overflow" );
		print '</div>';
		break;

	case 'guides':
		$form = '<form method="get" class="pure-form pure-form-stacked"><input type="hidden" name="download" value="guides"><p>' . $fmtPick() . '</p>
			<label>' . _( "For CSV, which table" ) . '</label><select name="sheet"><option value="0">' . _( "Guides" ) . '</option><option value="1">' . _( "Links in guides" ) . '</option></select>
			<button class="pure-button pure-button-primary" type="submit">' . _( "Export guides" ) . '</button></form>
			<p class="ie-note">' . _( "Two sheets: every guide (owners, tabs, boxes, last change, address) and every link inside guide boxes." ) . '</p>';
		print '<div class="pure-u-1 pure-u-lg-1-2">';
		makePluslet( _( "Export guides" ), $form, "no_overflow" );
		print '</div>';
		break;

	case 'staff':
		$form = '<form method="get" class="pure-form pure-form-stacked"><input type="hidden" name="download" value="staff"><p>' . $fmtPick() . '</p>
			<button class="pure-button pure-button-primary" type="submit">' . _( "Export staff" ) . '</button></form>
			<p class="ie-note">' . _( "Name, email, position, department, phone, room, roles and guides. Home addresses and emergency contacts are never exported." ) . '</p>';
		print '<div class="pure-u-1 pure-u-lg-1-2">';
		makePluslet( _( "Export staff" ), $form, "no_overflow" );
		print '</div><div class="pure-u-1 pure-u-lg-1-2">';
		makePluslet( _( "Import staff" ), $can['staff'] ? $importForm( 'staff', '', false )
			. '<p class="ie-note">' . _( "Staff are matched by email. Columns: First name, Last name, Email, Position, Department, Phone, Room, Active (Yes/No), Roles (e.g. records; faq). New accounts get no usable password: they sign in with single sign-on, or an admin sets one." ) . '</p>'
			: '<p class="ie-note">' . _( "Only admins can import staff." ) . '</p>', "no_overflow" );
		print '</div>';
		break;

	case 'faqs':
		$form = '<form method="get" class="pure-form pure-form-stacked"><input type="hidden" name="download" value="faqs"><p>' . $fmtPick() . '</p>
			<button class="pure-button pure-button-primary" type="submit">' . _( "Export FAQs" ) . '</button></form>';
		print '<div class="pure-u-1 pure-u-lg-1-2">';
		makePluslet( _( "Export FAQs" ), $form, "no_overflow" );
		print '</div><div class="pure-u-1 pure-u-lg-1-2">';
		makePluslet( _( "Import FAQs" ), $can['faqs'] ? $importForm( 'faqs', '', false )
			. '<p class="ie-note">' . _( "Columns: Question, Answer (may contain simple HTML), Keywords, Subjects and Collections (separated by ;). Rows with an FAQ ID, or the same question as an existing FAQ, update it." ) . '</p>'
			: '<p class="ie-note">' . _( "Importing FAQs needs the FAQ permission." ) . '</p>', "no_overflow" );
		print '</div>';
		break;
}
print '</div>';


include( "../includes/footer.php" );
