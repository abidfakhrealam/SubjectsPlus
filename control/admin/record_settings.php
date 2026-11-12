<?php
/**
 * @file record_settings.php
 * @brief manage the record page's dropdowns (Format, Access Restrictions, Status) and custom fields.
 */

use SubjectsPlus\Control\Querier;
use SubjectsPlus\Control\RecordSettings;

$subsubcat  = "";
$subcat     = "admin";
$page_title = "Record Settings";

include( "../includes/header.php" );
include( "../includes/autoloader.php" );

$db   = new Querier;
$conn = $db->getConnection();

function rs_h( $s ) {
	return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
}

if ( empty( $_SESSION['sp_rs_csrf'] ) ) {
	$_SESSION['sp_rs_csrf'] = bin2hex( random_bytes( 32 ) );
}
$csrf     = $_SESSION['sp_rs_csrf'];
$tabs     = array( 'formats' => _( "Formats" ), 'restrictions' => _( "Access restrictions" ), 'statuses' => _( "Statuses" ), 'fields' => _( "Custom fields" ) );
$tab      = isset( $_GET['tab'], $tabs[ $_GET['tab'] ] ) ? $_GET['tab'] : 'formats';
$feedback = array();
$errors   = array();

if ( ! RecordSettings::ready() ) {
	makePluslet( _( "Record Settings" ), "<p>" . _( "Run <code>php tools/migrate.php</code> on the server to enable this page." ) . "</p>", "no_overflow" );
	include( "../includes/footer.php" );
	exit;
}

$inUse = function ( $sql, $param ) use ( $conn ) {
	$st = $conn->prepare( $sql );
	$st->execute( array( $param ) );

	return (int) $st->fetchColumn();
};

// ------------------------------------------------------------------ save
if ( $_SERVER['REQUEST_METHOD'] === 'POST' ) {
	if ( ! isset( $_POST['csrf'] ) || ! hash_equals( $csrf, (string) $_POST['csrf'] ) ) {
		$errors[] = _( "Your session has expired. Please reload the page and try again." );
	} else {
		$rows = isset( $_POST['rows'] ) && is_array( $_POST['rows'] ) ? $_POST['rows'] : array();
		$new  = isset( $_POST['new'] ) && is_array( $_POST['new'] ) ? $_POST['new'] : array();
		$del  = isset( $_POST['delete'] ) ? (int) $_POST['delete'] : 0;

		try {
			$conn->beginTransaction();

			if ( $tab === 'formats' ) {
				if ( $del ) {
					if ( $inUse( "SELECT COUNT(*) FROM location WHERE format = ?", $del ) ) {
						$errors[] = _( "That format is used by records. Untick “Active” to retire it instead." );
					} else {
						$conn->prepare( "DELETE FROM format WHERE format_id = ?" )->execute( array( $del ) );
						$feedback[] = _( "Format deleted." );
					}
				}
				foreach ( $rows as $id => $r ) {
					$name = trim( strip_tags( (string) ( $r['name'] ?? '' ) ) );
					if ( $name === '' ) {
						continue;
					}
					$conn->prepare( "UPDATE format SET format = ?, sort_order = ?, active = ? WHERE format_id = ?" )
					     ->execute( array( $name, (int) ( $r['sort'] ?? 0 ), empty( $r['active'] ) ? 0 : 1, (int) $id ) );
				}
				if ( trim( (string) ( $new['name'] ?? '' ) ) !== '' ) {
					$conn->prepare( "INSERT INTO format (format, sort_order, active) VALUES (?, ?, 1)" )->execute( array( trim( strip_tags( $new['name'] ) ), (int) ( $new['sort'] ?? 99 ) ) );
					$feedback[] = _( "Format added." );
				}
			}

			if ( $tab === 'restrictions' ) {
				if ( $del ) {
					if ( $del <= 4 ) {
						$errors[] = _( "The four built-in restrictions cannot be deleted (rename or retire them instead)." );
					} elseif ( $inUse( "SELECT COUNT(*) FROM location WHERE access_restrictions = ?", $del ) ) {
						$errors[] = _( "That restriction is used by records. Untick “Active” to retire it instead." );
					} else {
						$conn->prepare( "DELETE FROM restrictions WHERE restrictions_id = ?" )->execute( array( $del ) );
						$feedback[] = _( "Restriction deleted." );
					}
				}
				foreach ( $rows as $id => $r ) {
					$name = trim( strip_tags( (string) ( $r['name'] ?? '' ) ) );
					$beh  = array_key_exists( $r['behaviour'] ?? '', RecordSettings::BEHAVIOURS ) ? $r['behaviour'] : 'proxy';
					if ( $name === '' ) {
						continue;
					}
					$conn->prepare( "UPDATE restrictions SET restrictions = ?, behaviour = ?, sort_order = ?, active = ? WHERE restrictions_id = ?" )
					     ->execute( array( $name, $beh, (int) ( $r['sort'] ?? 0 ), empty( $r['active'] ) ? 0 : 1, (int) $id ) );
				}
				if ( trim( (string) ( $new['name'] ?? '' ) ) !== '' ) {
					$beh = array_key_exists( $new['behaviour'] ?? '', RecordSettings::BEHAVIOURS ) ? $new['behaviour'] : 'proxy';
					$conn->prepare( "INSERT INTO restrictions (restrictions, behaviour, sort_order, active) VALUES (?, ?, ?, 1)" )->execute( array( trim( strip_tags( $new['name'] ) ), $beh, (int) ( $new['sort'] ?? 99 ) ) );
					$feedback[] = _( "Restriction added." );
				}
			}

			if ( $tab === 'statuses' ) {
				if ( $del ) {
					$s = RecordSettings::statusById( $del );
					if ( $s && $inUse( "SELECT COUNT(*) FROM location WHERE record_status = ?", $s['name'] ) ) {
						$errors[] = _( "That status is used by records. Untick “Active” to retire it instead." );
					} else {
						$conn->prepare( "DELETE FROM record_status_option WHERE status_id = ?" )->execute( array( $del ) );
						$feedback[] = _( "Status deleted (its transitions and rules were removed too)." );
					}
				}
				$initial = isset( $_POST['initial'] ) ? (int) $_POST['initial'] : 0;
				foreach ( $rows as $id => $r ) {
					$name = trim( strip_tags( (string) ( $r['name'] ?? '' ) ) );
					$old  = RecordSettings::statusById( (int) $id );
					if ( $name === '' || ! $old ) {
						continue;
					}
					if ( $name !== $old['name'] ) {
						if ( RecordSettings::status( $name ) ) {
							$errors[] = sprintf( _( "There is already a status called %s." ), rs_h( $name ) );
							continue;
						}
						// renaming: move every record (and history) to the new name
						$conn->prepare( "UPDATE location SET record_status = ? WHERE record_status = ?" )->execute( array( $name, $old['name'] ) );
					}
					$stage = array_key_exists( $r['stage'] ?? '', RecordSettings::STAGES ) ? $r['stage'] : 'active';
					$color = sp_brand_color( $r['color'] ?? '' ) ?: '#777777';
					$conn->prepare( "UPDATE record_status_option SET name = ?, stage = ?, is_public = ?, is_initial = ?, color = ?, description = ?, sort_order = ?, active = ? WHERE status_id = ?" )
					     ->execute( array( $name, $stage, empty( $r['public'] ) ? 0 : 1, (int) $id === $initial ? 1 : 0, $color,
					                       mb_substr( trim( strip_tags( (string) ( $r['description'] ?? '' ) ) ), 0, 255 ), (int) ( $r['sort'] ?? 0 ), empty( $r['active'] ) ? 0 : 1, (int) $id ) );
				}
				if ( trim( (string) ( $new['name'] ?? '' ) ) !== '' ) {
					$name = trim( strip_tags( $new['name'] ) );
					if ( RecordSettings::status( $name ) ) {
						$errors[] = sprintf( _( "There is already a status called %s." ), rs_h( $name ) );
					} else {
						$stage = array_key_exists( $new['stage'] ?? '', RecordSettings::STAGES ) ? $new['stage'] : 'active';
						$conn->prepare( "INSERT INTO record_status_option (name, stage, is_public, color, sort_order, active) VALUES (?, ?, ?, ?, ?, 1)" )
						     ->execute( array( $name, $stage, empty( $new['public'] ) ? 0 : 1, sp_brand_color( $new['color'] ?? '' ) ?: '#777777', (int) ( $new['sort'] ?? 99 ) ) );
						$feedback[] = _( "Status added." );
					}
				}
			}

			if ( $tab === 'fields' ) {
				if ( $del ) {
					$n = $inUse( "SELECT COUNT(*) FROM record_field_value WHERE field_id = ?", $del );
					if ( $n && empty( $_POST['confirm_delete'][ $del ] ) ) {
						$errors[] = sprintf( _( "That field has values on %d records. Untick “Active” to hide it, or tick “also delete the values” and delete again." ), $n );
					} else {
						$conn->prepare( "DELETE FROM record_field WHERE field_id = ?" )->execute( array( $del ) );
						$feedback[] = _( "Field deleted." );
					}
				}
				$saveField = function ( $r, $id = null ) use ( $conn, &$errors ) {
					$label = trim( strip_tags( (string) ( $r['label'] ?? '' ) ) );
					if ( $label === '' ) {
						return false;
					}
					$type = array_key_exists( $r['type'] ?? '', RecordSettings::FIELD_TYPES ) ? $r['type'] : 'text';
					$opts = trim( strip_tags( (string) ( $r['options'] ?? '' ) ) );
					if ( in_array( $type, array( 'select', 'checkboxes' ), true ) && $opts === '' ) {
						$errors[] = sprintf( _( "%s: add the choices, one per line." ), rs_h( $label ) );
					}
					$cur  = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) ( $r['currency'] ?? 'INR' ) ) ) ?: 'INR';
					$vals = array( $label, $type, $opts, mb_substr( trim( strip_tags( (string) ( $r['help'] ?? '' ) ) ), 0, 255 ),
					               empty( $r['required'] ) ? 0 : 1, empty( $r['public'] ) ? 0 : 1, substr( $cur, 0, 3 ), (int) ( $r['sort'] ?? 0 ), ( $id === null || ! empty( $r['active'] ) ) ? 1 : 0 );
					if ( $id === null ) {
						$conn->prepare( "INSERT INTO record_field (label, type, options, help, required, is_public, currency, sort_order, active) VALUES (?,?,?,?,?,?,?,?,?)" )->execute( $vals );
					} else {
						$vals[] = (int) $id;
						$conn->prepare( "UPDATE record_field SET label=?, type=?, options=?, help=?, required=?, is_public=?, currency=?, sort_order=?, active=? WHERE field_id=?" )->execute( $vals );
					}

					return true;
				};
				foreach ( $rows as $id => $r ) {
					$saveField( $r, $id );
				}
				if ( $saveField( $new ) ) {
					$feedback[] = _( "Field added." );
				}
			}

			if ( $errors ) {
				$conn->rollBack();
			} else {
				$conn->commit();
				if ( ! $feedback ) {
					$feedback[] = _( "Changes saved." );
				}
			}
		} catch ( \Throwable $e ) {
			if ( $conn->inTransaction() ) {
				$conn->rollBack();
			}
			error_log( 'SubjectsPlus record settings: ' . $e->getMessage() );
			$errors[] = _( "The changes could not be saved." );
		}
		RecordSettings::reset();
	}
}

// ------------------------------------------------------------------ render
$navHtml = '<p class="rs-tabs">';
foreach ( $tabs as $k => $label ) {
	$navHtml .= $k === $tab ? '<strong class="pure-button pure-button-primary rs-tab-on">' . rs_h( $label ) . '</strong> ' : '<a class="pure-button rs-tab" href="record_settings.php?tab=' . $k . '">' . rs_h( $label ) . '</a> ';
}
$navHtml .= ' <a href="workflow.php">' . _( "Workflow →" ) . '</a></p>';

print '<div class="pure-g"><div class="pure-u-1">' . $navHtml;
foreach ( $feedback as $f ) {
	print '<div class="feedback">' . rs_h( $f ) . '</div>';
}
foreach ( $errors as $e ) {
	print '<div class="feedback rs-error" role="alert">' . $e . '</div>';
}

$form = '<form method="post" action="record_settings.php?tab=' . $tab . '" class="pure-form rs-form"><input type="hidden" name="csrf" value="' . rs_h( $csrf ) . '">';
$chk  = function ( $name, $on ) {
	return '<input type="checkbox" name="' . $name . '" value="1"' . ( $on ? ' checked' : '' ) . '>';
};
$sel  = function ( $name, array $opts, $cur ) {
	$h = '<select name="' . $name . '">';
	foreach ( $opts as $k => $v ) {
		$h .= '<option value="' . rs_h( $k ) . '"' . ( (string) $k === (string) $cur ? ' selected' : '' ) . '>' . rs_h( $v ) . '</option>';
	}

	return $h . '</select>';
};
$delBtn = function ( $id ) {
	return '<button type="submit" name="delete" value="' . (int) $id . '" class="pure-button rs-del" onclick="return confirm(\'' . _( "Delete this?" ) . '\')">' . _( "Delete" ) . '</button>';
};

if ( $tab === 'formats' ) {
	$counts = $conn->query( "SELECT format, COUNT(*) FROM location GROUP BY format" )->fetchAll( PDO::FETCH_KEY_PAIR );
	$t      = '<p>' . _( "Formats appear in the Format dropdown on each record (e.g. Web, Print, Print with URL)." ) . '</p><div style="overflow-x:auto"><table class="pure-table pure-table-horizontal rs-table"><thead><tr><th>' . _( "Name" ) . '</th><th>' . _( "Order" ) . '</th><th>' . _( "Active" ) . '</th><th>' . _( "Records" ) . '</th><th></th></tr></thead><tbody>';
	foreach ( RecordSettings::formats( true ) as $r ) {
		$id = (int) $r['format_id'];
		$t .= '<tr><td><input type="text" name="rows[' . $id . '][name]" value="' . rs_h( $r['format'] ) . '" required></td><td><input type="number" name="rows[' . $id . '][sort]" value="' . (int) $r['sort_order'] . '" style="width:5em"></td><td>' . $chk( 'rows[' . $id . '][active]', $r['active'] ) . '</td><td>' . (int) ( $counts[ $id ] ?? 0 ) . '</td><td>' . $delBtn( $id ) . '</td></tr>';
	}
	$t .= '<tr class="rs-new"><td><input type="text" name="new[name]" placeholder="' . _( "Add a format…" ) . '"></td><td><input type="number" name="new[sort]" value="99" style="width:5em"></td><td colspan="3"></td></tr></tbody></table></div>';
	makePluslet( _( "Formats" ), $form . $t . '<p><button type="submit" class="pure-button pure-button-primary">' . _( "Save" ) . '</button></p></form>', "no_overflow" );
}

if ( $tab === 'restrictions' ) {
	$counts = $conn->query( "SELECT access_restrictions, COUNT(*) FROM location GROUP BY access_restrictions" )->fetchAll( PDO::FETCH_KEY_PAIR );
	$t      = '<p>' . _( "Access restrictions appear on each record. The behaviour decides how its link is built: through the off-campus access service (Admin › Off-campus Access) or directly." ) . '</p><div style="overflow-x:auto"><table class="pure-table pure-table-horizontal rs-table"><thead><tr><th>' . _( "Name" ) . '</th><th>' . _( "Behaviour" ) . '</th><th>' . _( "Order" ) . '</th><th>' . _( "Active" ) . '</th><th>' . _( "Records" ) . '</th><th></th></tr></thead><tbody>';
	foreach ( RecordSettings::restrictions( true ) as $r ) {
		$id = (int) $r['restrictions_id'];
		$t .= '<tr><td><input type="text" name="rows[' . $id . '][name]" value="' . rs_h( $r['restrictions'] ) . '" required></td><td>' . $sel( 'rows[' . $id . '][behaviour]', RecordSettings::BEHAVIOURS, $r['behaviour'] ) . '</td><td><input type="number" name="rows[' . $id . '][sort]" value="' . (int) $r['sort_order'] . '" style="width:5em"></td><td>' . $chk( 'rows[' . $id . '][active]', $r['active'] ) . '</td><td>' . (int) ( $counts[ $id ] ?? 0 ) . '</td><td>' . ( $id > 4 ? $delBtn( $id ) : '<small>' . _( "built-in" ) . '</small>' ) . '</td></tr>';
	}
	$t .= '<tr class="rs-new"><td><input type="text" name="new[name]" placeholder="' . _( "Add a restriction…" ) . '"></td><td>' . $sel( 'new[behaviour]', RecordSettings::BEHAVIOURS, 'proxy' ) . '</td><td><input type="number" name="new[sort]" value="99" style="width:5em"></td><td colspan="3"></td></tr></tbody></table></div>';
	makePluslet( _( "Access restrictions" ), $form . $t . '<p><button type="submit" class="pure-button pure-button-primary">' . _( "Save" ) . '</button></p></form>', "no_overflow" );
}

if ( $tab === 'statuses' ) {
	$counts = $conn->query( "SELECT record_status, COUNT(*) FROM location GROUP BY record_status" )->fetchAll( PDO::FETCH_KEY_PAIR );
	$t      = '<p>' . _( "Statuses appear on each record. <strong>Shown to public</strong> statuses keep the record in the A–Z list and subject lists; others hide it until it moves to a public status. The <strong>new records</strong> status is given to records when they are created. Which status can move to which, and who may do it, is set in" ) . ' <a href="workflow.php">' . _( "Workflow" ) . '</a>.</p>
	<div style="overflow-x:auto"><table class="pure-table pure-table-horizontal rs-table"><thead><tr><th>' . _( "Name" ) . '</th><th>' . _( "Stage" ) . '</th><th>' . _( "Shown to public" ) . '</th><th>' . _( "New records" ) . '</th><th>' . _( "Colour" ) . '</th><th>' . _( "Description" ) . '</th><th>' . _( "Order" ) . '</th><th>' . _( "Active" ) . '</th><th>' . _( "Records" ) . '</th><th></th></tr></thead><tbody>';
	foreach ( RecordSettings::statuses( true ) as $r ) {
		$id = (int) $r['status_id'];
		$t .= '<tr><td><input type="text" name="rows[' . $id . '][name]" value="' . rs_h( $r['name'] ) . '" required size="14"></td><td>' . $sel( 'rows[' . $id . '][stage]', RecordSettings::STAGES, $r['stage'] ) . '</td><td>' . $chk( 'rows[' . $id . '][public]', $r['is_public'] ) . '</td><td><input type="radio" name="initial" value="' . $id . '"' . ( $r['is_initial'] ? ' checked' : '' ) . '></td><td><input type="color" name="rows[' . $id . '][color]" value="' . rs_h( $r['color'] ) . '"></td><td><input type="text" name="rows[' . $id . '][description]" value="' . rs_h( $r['description'] ) . '" size="22"></td><td><input type="number" name="rows[' . $id . '][sort]" value="' . (int) $r['sort_order'] . '" style="width:4.5em"></td><td>' . $chk( 'rows[' . $id . '][active]', $r['active'] ) . '</td><td>' . (int) ( $counts[ $r['name'] ] ?? 0 ) . '</td><td>' . $delBtn( $id ) . '</td></tr>';
	}
	$t .= '<tr class="rs-new"><td><input type="text" name="new[name]" placeholder="' . _( "Add a status…" ) . '" size="14"></td><td>' . $sel( 'new[stage]', RecordSettings::STAGES, 'active' ) . '</td><td>' . $chk( 'new[public]', false ) . '</td><td></td><td><input type="color" name="new[color]" value="#777777"></td><td></td><td><input type="number" name="new[sort]" value="99" style="width:4.5em"></td><td colspan="3"></td></tr></tbody></table></div>';
	makePluslet( _( "Statuses" ), $form . $t . '<p><button type="submit" class="pure-button pure-button-primary">' . _( "Save" ) . '</button></p></form>', "no_overflow" );
}

if ( $tab === 'fields' ) {
	$counts = $conn->query( "SELECT field_id, COUNT(*) FROM record_field_value GROUP BY field_id" )->fetchAll( PDO::FETCH_KEY_PAIR );
	$fieldRow = function ( $prefix, array $r, $isNew = false ) use ( $sel, $chk ) {
		return '<div class="rs-field' . ( $isNew ? ' rs-new' : '' ) . '" style="border:1px solid #ddd;border-radius:6px;padding:.6em;margin-bottom:.8em">
			<div class="pure-g"><div class="pure-u-1 pure-u-md-1-3"><label>' . _( "Label" ) . '<br><input type="text" name="' . $prefix . '[label]" value="' . rs_h( $r['label'] ?? '' ) . '" class="pure-input-1"' . ( $isNew ? ' placeholder="' . _( "Add a field, e.g. Vendor contact" ) . '"' : ' required' ) . '></label></div>
			<div class="pure-u-1 pure-u-md-1-6"><label>' . _( "Type" ) . '<br>' . $sel( $prefix . '[type]', RecordSettings::FIELD_TYPES, $r['type'] ?? 'text' ) . '</label></div>
			<div class="pure-u-1 pure-u-md-1-3"><label>' . _( "Choices (dropdown/checkboxes: one per line)" ) . '<br><textarea name="' . $prefix . '[options]" rows="2" class="pure-input-1">' . rs_h( $r['options'] ?? '' ) . '</textarea></label></div>
			<div class="pure-u-1 pure-u-md-1-6"><label>' . _( "Currency (money)" ) . '<br><input type="text" name="' . $prefix . '[currency]" value="' . rs_h( $r['currency'] ?? 'INR' ) . '" size="4" maxlength="3"></label></div></div>
			<div class="pure-g"><div class="pure-u-1 pure-u-md-1-2"><label>' . _( "Help text" ) . '<br><input type="text" name="' . $prefix . '[help]" value="' . rs_h( $r['help'] ?? '' ) . '" class="pure-input-1"></label></div>
			<div class="pure-u-1 pure-u-md-1-2" style="padding-top:1.4em"><label>' . $chk( $prefix . '[required]', ! empty( $r['required'] ) ) . ' ' . _( "Required" ) . '</label> &nbsp;
			<label>' . $chk( $prefix . '[public]', ! empty( $r['is_public'] ) ) . ' ' . _( "Show to public (A–Z list)" ) . '</label> &nbsp;
			' . ( $isNew ? '' : '<label>' . $chk( $prefix . '[active]', ! empty( $r['active'] ) ) . ' ' . _( "Active" ) . '</label> &nbsp;' ) . '
			' . _( "Order" ) . ' <input type="number" name="' . $prefix . '[sort]" value="' . (int) ( $r['sort_order'] ?? 99 ) . '" style="width:4.5em"></div></div>';
	};
	$t = '<p>' . _( "Custom fields appear in an “Additional details” box on every record. Fields marked public are shown under the database in the A–Z list; everything else is for staff only (licence files are never public unless the field is)." ) . '</p>';
	foreach ( RecordSettings::fields( true ) as $r ) {
		$id = (int) $r['field_id'];
		$t .= $fieldRow( 'rows[' . $id . ']', $r ) . '<div style="margin:-.6em 0 1em;font-size:.85em">' . sprintf( _( "Used on %d records" ), (int) ( $counts[ $id ] ?? 0 ) ) . ' · ' . $delBtn( $id ) . ' <label><input type="checkbox" name="confirm_delete[' . $id . ']" value="1"> ' . _( "also delete the values" ) . '</label></div></div>';
	}
	$t .= '<h3>' . _( "Add a field" ) . '</h3>' . $fieldRow( 'new', array(), true ) . '</div>';
	makePluslet( _( "Custom fields" ), $form . $t . '<p><button type="submit" class="pure-button pure-button-primary">' . _( "Save" ) . '</button></p></form>', "no_overflow" );
}

print '</div></div>';
?>
<style>
.rs-table input[type=text]{min-width:8em}.rs-table td{vertical-align:middle}.rs-new{background:#f6f8fb}
.rs-error{background:#fdecea}.rs-del{background:#fff;color:#a00;border:1px solid #c88}.rs-tab{background:#fff;color:#333;border:1px solid #888}
</style>
<?php
include( "../includes/footer.php" );
