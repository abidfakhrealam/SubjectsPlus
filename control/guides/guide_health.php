<?php
/**
 * @file guide_health.php
 * @brief all guides at a glance — review due dates (with reminders) and accessibility problems.
 */

use SubjectsPlus\Control\Guide\GuideReviews;
use SubjectsPlus\Control\Guide\GuideA11y;
use SubjectsPlus\Control\Spreadsheet;

$subsubcat  = "";
$subcat     = "guides";
$page_title = "Guide Health";

if ( isset( $_GET['download'] ) ) {
	$header = "noshow";
}
include( "../includes/header.php" );

function gh_h( $s ) {
	return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
}

$isAdmin = ! empty( $_SESSION['admin'] );
if ( empty( $_SESSION['sp_gh_csrf'] ) ) {
	$_SESSION['sp_gh_csrf'] = bin2hex( random_bytes( 32 ) );
}
$csrf = $_SESSION['sp_gh_csrf'];
$tab  = ( $_GET['tab'] ?? 'reviews' ) === 'a11y' ? 'a11y' : 'reviews';
$vis  = array( 0 => _( "Hidden" ), 1 => _( "Public" ), 2 => _( "Suppressed" ) );
$stateLabel = array( 'overdue' => _( "Overdue" ), 'soon' => _( "Due within 30 days" ), 'ok' => _( "OK" ), 'off' => _( "No reviews" ) );

if ( isset( $_GET['download'] ) ) {
	if ( $_GET['download'] === 'a11y' ) {
		$rows = array( array( 'Guide', 'Tab', 'Box', 'Problem', 'Code' ) );
		foreach ( GuideA11y::checkGuides() as $p ) {
			$rows[] = array( $p['guide'], $p['tab'], $p['box'], $p['message'], $p['snippet'] );
		}
		Spreadsheet::send( 'guide-accessibility', array( array( 'name' => 'Accessibility', 'rows' => $rows ) ), 'xlsx' );
	}
	$rows = array( array( 'Guide', 'Visibility', 'Owners', 'Review every (months)', 'Last reviewed', 'Reviewed by', 'Last changed', 'Review due', 'State' ) );
	foreach ( GuideReviews::all() as $g ) {
		$rows[] = array( $g['guide'], $vis[ $g['active'] ] ?? $g['active'], implode( '; ', array_column( $g['owners'], 'name' ) ), $g['months'],
		                 $g['last_reviewed'] ? new DateTime( $g['last_reviewed'] ) : '', $g['reviewed_by'], $g['last_changed'] ? new DateTime( $g['last_changed'] ) : '',
		                 $g['due'] ? new DateTime( $g['due'] ) : '', $stateLabel[ $g['state'] ] );
	}
	Spreadsheet::send( 'guide-reviews', array( array( 'name' => 'Reviews', 'rows' => $rows ) ), 'xlsx' );
}

$msg = '';
if ( $_SERVER['REQUEST_METHOD'] === 'POST' ) {
	if ( ! hash_equals( $csrf, (string) ( $_POST['csrf'] ?? '' ) ) ) {
		$msg = _( "The form expired. Please try again." );
	} elseif ( ! GuideReviews::ready() ) {
		$msg = _( "Apply the database updates first (Admin → Database Updates)." );
	} elseif ( isset( $_POST['remind'] ) && $isAdmin ) {
		$log = GuideReviews::sendReminders( null, true );
		$msg = $log ? sprintf( _( "%d reminder(s) processed:" ), count( $log ) ) . ' ' . implode( '; ', $log ) : _( "No public guide is overdue — no reminders sent." );
	} elseif ( isset( $_POST['months'] ) && $isAdmin ) {
		foreach ( (array) $_POST['months'] as $sid => $m ) {
			GuideReviews::setMonths( (int) $sid, $m );
		}
		$msg = _( "Review intervals saved." );
	}
}

print '<div class="pure-g"><div class="pure-u-1"><p class="gh-tabs">'
	. ( $tab === 'reviews' ? '<strong class="pure-button pure-button-primary">' . _( "Reviews" ) . '</strong>' : '<a class="pure-button gh-tab" href="guide_health.php">' . _( "Reviews" ) . '</a>' ) . ' '
	. ( $tab === 'a11y' ? '<strong class="pure-button pure-button-primary">' . _( "Accessibility" ) . '</strong>' : '<a class="pure-button gh-tab" href="guide_health.php?tab=a11y">' . _( "Accessibility" ) . '</a>' )
	. '</p></div></div>';
if ( $msg !== '' ) {
	print '<p class="gh-msg" role="status">' . gh_h( $msg ) . '</p>';
}

if ( $tab === 'reviews' ) {
	$all    = GuideReviews::all();
	$counts = array_count_values( array_column( $all, 'state' ) );
	$body   = '<p>' . sprintf( _( "Guides are due for review every %d months by default (set \$sp_guide_review_months in config.php; per guide below). Owners of public guides get a weekly email while a review is overdue, from the daily task tools/workflow-daily.php. Editors mark a guide as reviewed in the guide editor → Check." ), GuideReviews::defaultMonths() ) . '</p>'
		. '<p><span class="gh-badge gh-overdue">' . sprintf( _( "%d overdue" ), $counts['overdue'] ?? 0 ) . '</span> <span class="gh-badge gh-soon">' . sprintf( _( "%d due within 30 days" ), $counts['soon'] ?? 0 ) . '</span> '
		. '<span class="gh-badge gh-ok">' . sprintf( _( "%d up to date" ), $counts['ok'] ?? 0 ) . '</span> <a href="guide_health.php?download=reviews">' . _( "Download as Excel" ) . '</a></p>';
	if ( ! GuideReviews::ready() ) {
		$body .= '<p class="gh-msg">' . _( "Apply the database updates (Admin → Database Updates) to record reviews." ) . '</p>';
	}
	$body .= '<form method="post"><input type="hidden" name="csrf" value="' . gh_h( $csrf ) . '"><table class="pure-table pure-table-horizontal gh-table"><thead><tr><th>' . _( "Guide" ) . '</th><th>' . _( "Owners" ) . '</th><th>' . _( "Last reviewed" ) . '</th><th>' . _( "Review due" ) . '</th><th>' . _( "Every" ) . '</th></tr></thead><tbody>';
	uasort( $all, function ( $a, $b ) {
		$o = array( 'overdue' => 0, 'soon' => 1, 'ok' => 2, 'off' => 3 );

		return $o[ $a['state'] ] <=> $o[ $b['state'] ] ?: strcmp( (string) $a['due'], (string) $b['due'] );
	} );
	foreach ( $all as $g ) {
		$opts = '<option value="">' . sprintf( _( "default (%d)" ), GuideReviews::defaultMonths() ) . '</option>';
		foreach ( array( 3, 6, 12, 24, 0 ) as $m ) {
			$opts .= '<option value="' . $m . '"' . ( $g['own_months'] !== null && (int) $g['own_months'] === $m ? ' selected' : '' ) . '>' . ( $m ? sprintf( _( "%d months" ), $m ) : _( "never" ) ) . '</option>';
		}
		$body .= '<tr class="gh-' . $g['state'] . '"><td><a href="guide.php?subject_id=' . $g['subject_id'] . '">' . gh_h( $g['guide'] ) . '</a> <small>' . gh_h( $vis[ $g['active'] ] ?? '' ) . '</small></td>'
			. '<td>' . gh_h( implode( ', ', array_column( $g['owners'], 'name' ) ) ) . '</td>'
			. '<td>' . ( $g['last_reviewed'] ? gh_h( date( 'j M Y', strtotime( $g['last_reviewed'] ) ) . ( $g['reviewed_by'] ? ' · ' . $g['reviewed_by'] : '' ) ) : '<em>' . _( "never" ) . '</em>' ) . '</td>'
			. '<td><span class="gh-badge gh-' . $g['state'] . '">' . ( $g['due'] ? gh_h( date( 'j M Y', strtotime( $g['due'] ) ) ) : '—' ) . '</span></td>'
			. '<td>' . ( $isAdmin ? '<select name="months[' . $g['subject_id'] . ']" aria-label="' . gh_h( sprintf( _( "Review interval for %s" ), $g['guide'] ) ) . '">' . $opts . '</select>' : gh_h( $g['months'] ? sprintf( _( "%d months" ), $g['months'] ) : _( "never" ) ) ) . '</td></tr>';
	}
	$body .= '</tbody></table>';
	if ( $isAdmin ) {
		$body .= '<p><button class="pure-button pure-button-primary" type="submit">' . _( "Save intervals" ) . '</button> '
			. '<button class="pure-button gh-tab" type="submit" name="remind" value="1">' . _( "Send reminders for overdue guides now" ) . '</button></p>';
	}
	$body .= '</form>';
	print '<div class="pure-g"><div class="pure-u-1">';
	makePluslet( _( "Guide reviews" ), $body, "no_overflow" );
	print '</div></div>';
} else {
	$problems = GuideA11y::checkGuides();
	$byGuide  = array();
	foreach ( $problems as $p ) {
		$byGuide[ $p['subject_id'] ]['guide']    = $p['guide'];
		$byGuide[ $p['subject_id'] ]['items'][] = $p;
	}
	$body = '<p>' . _( "Common accessibility problems in guide boxes (WCAG 2.1 AA): images without descriptions, unclear link text such as \"click here\", headings out of order, tables without headers, low-contrast text colours, untitled embedded content." ) . '</p>'
		. '<p><strong>' . sprintf( _( "%1\$d problem(s) in %2\$d guide(s)." ), count( $problems ), count( $byGuide ) ) . '</strong> <a href="guide_health.php?download=a11y">' . _( "Download as Excel" ) . '</a></p>';
	foreach ( $byGuide as $sid => $g ) {
		$body .= '<h3 class="gh-h"><a href="guide.php?subject_id=' . (int) $sid . '">' . gh_h( $g['guide'] ) . '</a> <small>(' . count( $g['items'] ) . ')</small></h3><ul class="gh-list">';
		foreach ( $g['items'] as $p ) {
			$body .= '<li><strong>' . gh_h( $p['tab'] . ' › ' . $p['box'] ) . '</strong> — ' . gh_h( $p['message'] ) . '<br><code>' . gh_h( $p['snippet'] ) . '</code></li>';
		}
		$body .= '</ul>';
	}
	print '<div class="pure-g"><div class="pure-u-1">';
	makePluslet( _( "Accessibility of guides" ), $body, "no_overflow" );
	print '</div></div>';
}
?>
<style>
.gh-tab{background:#fff;color:#333;border:1px solid #888}
.gh-msg{background:#fff4e5;border-left:4px solid #e07b00;padding:8px 12px}
.gh-table{width:100%;font-size:.93em}.gh-table td{vertical-align:top}
.gh-badge{display:inline-block;padding:1px 8px;border-radius:10px;font-weight:600;font-size:.9em}
.gh-overdue .gh-badge,.gh-badge.gh-overdue{background:#fdecea;color:#b71c1c}
.gh-soon .gh-badge,.gh-badge.gh-soon{background:#fff8e1;color:#8a5a00}
.gh-ok .gh-badge,.gh-badge.gh-ok{background:#e8f5e9;color:#1b5e20}
.gh-list li{margin-bottom:8px}.gh-list code{color:#555;font-size:.85em;word-break:break-all}
.gh-h{margin:16px 0 4px}
</style>
<?php
include( "../includes/footer.php" );
