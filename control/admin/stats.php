<?php
/**
 * @file stats.php
 * @brief Admin → Usage Statistics: guide views, database and link clicks (with vendor / publisher), tab use and
 *        API requests for any date range, with CSV downloads.
 *        (4.7.1: replaces the 2016 page, which called a method that did not exist and failed under ONLY_FULL_GROUP_BY.)
 */

use SubjectsPlus\API\ApiClients;
use SubjectsPlus\Control\Stats\UsageReport;

$subcat     = "admin";
$page_title = "Usage Statistics";

if ( isset( $_GET['export'] ) ) {
	$header = "noshow";
}
include( "../includes/header.php" );

$d = function ( $k, $def ) {
	$v = isset( $_GET[ $k ] ) && is_string( $_GET[ $k ] ) ? $_GET[ $k ] : '';

	return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) && strtotime( $v ) ? $v : $def;
};
$preset = isset( $_GET['range'] ) && is_string( $_GET['range'] ) ? $_GET['range'] : '30';
$to     = $d( 'to', date( 'Y-m-d' ) );
$from   = $d( 'from', date( 'Y-m-d', strtotime( '-' . ( in_array( $preset, array( '7', '30', '90', '365' ), true ) ? (int) $preset - 1 : 29 ) . ' days' ) ) );
if ( strtotime( $from ) > strtotime( $to ) ) {
	list( $from, $to ) = array( $to, $from );
}
if ( ( strtotime( $to ) - strtotime( $from ) ) / 86400 > 731 ) {
	$from = date( 'Y-m-d', strtotime( $to . ' -731 days' ) ); // keep the queries reasonable
}
$days   = (int) round( ( strtotime( $to ) - strtotime( $from ) ) / 86400 ) + 1;
$report = new UsageReport( $from, $to );

// ------------------------------------------------------------------ CSV
$sections = array(
	'guides'    => array( _( "Guides" ), function () use ( $report ) { return $report->topGuides( 1000 ); } ),
	'databases' => array( _( "Databases" ), function () use ( $report ) { return $report->topDatabases( 5000 ); } ),
	'vendors'   => array( _( "Vendors" ), function () use ( $report ) { return $report->byVendor(); } ),
	'links'     => array( _( "Links" ), function () use ( $report ) { return $report->topLinks( 5000 ); } ),
	'tabs'      => array( _( "Tabs" ), function () use ( $report ) { return $report->topTabs( 5000 ); } ),
	'daily'     => array( _( "Daily" ), function () use ( $report ) {
		$o = array();
		foreach ( $report->daily() as $day => $v ) {
			$o[] = array( 'day' => $day, 'views' => $v[0], 'clicks' => $v[1] );
		}

		return $o;
	} ),
);
if ( isset( $_GET['export'], $sections[ $_GET['export'] ] ) ) {
	$rows = call_user_func( $sections[ $_GET['export'] ][1] );
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="usage-' . $_GET['export'] . '-' . $from . '-to-' . $to . '.csv"' );
	header( 'Cache-Control: no-store' );
	$out = fopen( 'php://output', 'w' );
	fwrite( $out, "\xEF\xBB\xBF" );
	if ( $rows ) {
		fputcsv( $out, array_keys( $rows[0] ) );
	}
	foreach ( $rows as $r ) {
		fputcsv( $out, array_map( function ( $c ) { $c = html_entity_decode( (string) $c, ENT_QUOTES ); return preg_match( '/^[=+\-@\t\r]/', $c ) ? "'" . $c : $c; }, array_values( $r ) ) );
	}
	fclose( $out );
	exit;
}

// ------------------------------------------------------------------ page
$qs = function ( array $over ) use ( $from, $to ) {
	return 'stats.php?' . http_build_query( array_merge( array( 'from' => $from, 'to' => $to ), $over ) );
};
$csv = function ( $key ) use ( $qs ) {
	return ' <a class="stats-csv" href="' . sp_h( $qs( array( 'export' => $key ) ) ) . '">' . _( "CSV" ) . '</a>';
};
$num = function ( $n ) {
	return number_format( (int) $n );
};
$tbl = function ( array $head, array $rows, $empty ) {
	$h = '<div style="overflow-x:auto"><table class="pure-table pure-table-horizontal stats-table"><thead><tr>';
	foreach ( $head as $i => $c ) {
		$h .= '<th scope="col"' . ( $i ? ' class="num"' : '' ) . '>' . sp_h( $c ) . '</th>';
	}
	$h .= '</tr></thead><tbody>';
	foreach ( $rows as $r ) {
		$h .= '<tr>';
		foreach ( array_values( $r ) as $i => $c ) {
			$h .= $i ? '<td class="num">' . $c . '</td>' : '<td>' . $c . '</td>';
		}
		$h .= '</tr>';
	}
	if ( ! $rows ) {
		$h .= '<tr><td colspan="' . count( $head ) . '">' . sp_h( $empty ) . '</td></tr>';
	}

	return $h . '</tbody></table></div>';
};
$none = _( "No activity in this period." );

$form = '<form method="get" action="stats.php" class="pure-form stats-range"><fieldset><legend class="sp-sr-only">' . _( "Period" ) . '</legend>';
foreach ( array( '7' => _( "Last 7 days" ), '30' => _( "Last 30 days" ), '90' => _( "Last 90 days" ), '365' => _( "Last 12 months" ) ) as $k => $l ) {
	$form .= '<a class="pure-button' . ( ! isset( $_GET['from'] ) && $preset === (string) $k ? ' pure-button-primary' : '' ) . '" href="stats.php?range=' . $k . '">' . sp_h( $l ) . '</a> ';
}
$form .= ' <label for="st-from">' . _( "From" ) . '</label> <input type="date" id="st-from" name="from" value="' . sp_h( $from ) . '">
	<label for="st-to">' . _( "to" ) . '</label> <input type="date" id="st-to" name="to" value="' . sp_h( $to ) . '"> <button type="submit" class="pure-button">' . _( "Show" ) . '</button></fieldset></form>';

$t     = $report->totals();
$cards = '<div class="stats-cards">';
foreach ( array( array( $t['view'], _( "guide views" ) ), array( $t['guides'], _( "guides viewed" ) ), array( $t['database'] + $t['link'], _( "link and database clicks" ) ), array( $t['tab_click'], _( "tab clicks" ) ) ) as $c ) {
	$cards .= '<div class="stats-card"><span class="stats-n">' . $num( $c[0] ) . '</span><span class="stats-l">' . sp_h( $c[1] ) . '</span></div>';
}
$cards .= '</div>';

// daily chart (inline SVG, with the numbers in a table for screen readers)
$daily = $report->daily();
$max   = max( 1, max( array_map( function ( $v ) { return $v[0] + $v[1]; }, $daily ) ?: array( 1 ) ) );
$w     = max( 300, count( $daily ) * 8 );
$bw    = $w / max( 1, count( $daily ) );
$svg   = '<svg viewBox="0 0 ' . $w . ' 120" class="stats-chart" role="img" aria-labelledby="st-chart-t"><title id="st-chart-t">' . sp_h( sprintf( _( "Views and clicks per day, %s to %s" ), $from, $to ) ) . '</title>';
$i     = 0;
foreach ( $daily as $day => $v ) {
	$hv  = round( $v[0] / $max * 110, 1 );
	$hc  = round( $v[1] / $max * 110, 1 );
	$x   = round( $i * $bw, 1 );
	$svg .= '<g><title>' . sp_h( $day . ': ' . $v[0] . ' ' . _( "views" ) . ', ' . $v[1] . ' ' . _( "clicks" ) ) . '</title>'
	        . '<rect x="' . $x . '" y="' . ( 115 - $hv ) . '" width="' . max( 1, $bw - 1 ) . '" height="' . $hv . '" class="b-views"/>'
	        . '<rect x="' . $x . '" y="' . ( 115 - $hv - $hc ) . '" width="' . max( 1, $bw - 1 ) . '" height="' . $hc . '" class="b-clicks"/></g>';
	$i++;
}
$svg .= '</svg><p class="stats-legend"><span class="k-views"></span> ' . _( "views" ) . ' <span class="k-clicks"></span> ' . _( "clicks" ) . $csv( 'daily' ) . '</p>';
$dailyRows = array();
foreach ( array_reverse( $daily, true ) as $day => $v ) {
	if ( $v[0] || $v[1] ) {
		$dailyRows[] = array( sp_h( $day ), $num( $v[0] ), $num( $v[1] ) );
	}
}
$svg .= '<details><summary>' . _( "Numbers per day" ) . '</summary>' . $tbl( array( _( "Day" ), _( "Views" ), _( "Clicks" ) ), $dailyRows, $none ) . '</details>';

$guides = array_map( function ( $r ) use ( $num ) {
	$name = html_entity_decode( (string) $r['guide'], ENT_QUOTES );

	return array( ( $r['subject_id'] ? '<a href="../guides/guide.php?subject_id=' . (int) $r['subject_id'] . '">' . sp_h( $name ) . '</a>' : sp_h( $name ) ) . ' <small class="smallgrey">' . sp_h( $r['shortform'] ) . '</small>',
	              $num( $r['views'] ), $num( $r['tabs'] ), $num( $r['clicks'] ) );
}, $report->topGuides( 25 ) );
$dbs = array_map( function ( $r ) use ( $num ) {
	return array( '<a href="../records/record.php?record_id=' . (int) $r['title_id'] . '">' . sp_h( html_entity_decode( (string) $r['title'], ENT_QUOTES ) ) . '</a>', sp_h( (string) $r['vendor'] ), $num( $r['clicks'] ), $num( $r['guides'] ) );
}, $report->topDatabases( 25 ) );
$vendors = array_map( function ( $r ) use ( $num ) {
	return array( $r['vendor'] === '' ? '<em>' . _( "(no vendor)" ) . '</em>' : sp_h( $r['vendor'] ), $num( $r['records'] ), $num( $r['clicks'] ) );
}, $report->byVendor() );
$links = array_map( function ( $r ) use ( $num ) {
	$u = (string) $r['link_url'];

	return array( '<span title="' . sp_h( $u ) . '">' . sp_h( $r['link_title'] !== '' && $r['link_title'] !== null ? html_entity_decode( (string) $r['link_title'], ENT_QUOTES ) : $u ) . '</span><br><small class="smallgrey">' . sp_h( mb_strimwidth( $u, 0, 80, '…' ) ) . '</small>', $num( $r['clicks'] ), $num( $r['guides'] ) );
}, $report->topLinks( 25 ) );
$tabs = array_map( function ( $r ) use ( $num ) {
	return array( sp_h( html_entity_decode( (string) $r['tab_name'], ENT_QUOTES ) ) . ' <small class="smallgrey">' . sp_h( $r['shortform'] ) . '</small>', $num( $r['clicks'] ) );
}, $report->topTabs( 15 ) );
$api = array_map( function ( $u ) use ( $num ) {
	return array( sp_h( (int) $u['client_id'] === 0 ? _( "legacy key / no key" ) : $u['name'] ), sp_h( $u['service'] ), $num( $u['ok'] ), $num( $u['limited'] ), $num( $u['errors'] ) );
}, ApiClients::usage( $days ) );

print '<div class="pure-g"><div class="pure-u-1">';
makePluslet( sprintf( _( "Usage, %s to %s" ), $from, $to ), $form . $cards . $svg, "no_overflow" );
print '</div><div class="pure-u-1 pure-u-lg-1-2"><div class="stats-col">';
makePluslet( _( "Guides" ) . $csv( 'guides' ), $tbl( array( _( "Guide" ), _( "Views" ), _( "Tab clicks" ), _( "Link clicks" ) ), $guides, $none ), "no_overflow" );
makePluslet( _( "Tabs" ) . $csv( 'tabs' ), $tbl( array( _( "Tab" ), _( "Clicks" ) ), $tabs, $none ), "no_overflow" );
print '</div></div><div class="pure-u-1 pure-u-lg-1-2"><div class="stats-col">';
makePluslet( _( "Databases" ) . $csv( 'databases' ), $tbl( array( _( "Database" ), _( "Vendor / Publisher" ), _( "Clicks" ), _( "Guides" ) ), $dbs, $none ), "no_overflow" );
makePluslet( _( "By vendor / publisher" ) . $csv( 'vendors' ), $tbl( array( _( "Vendor / Publisher" ), _( "Records used" ), _( "Clicks" ) ), $vendors, $none ), "no_overflow" );
makePluslet( _( "Other links" ) . $csv( 'links' ), $tbl( array( _( "Link" ), _( "Clicks" ), _( "Guides" ) ), $links, $none ), "no_overflow" );
makePluslet( _( "REST API requests" ), $tbl( array( _( "Client" ), _( "Service" ), _( "Answered" ), _( "Rate-limited" ), _( "Refused" ) ), $api, $none ), "no_overflow" );
print '</div></div></div>';
print '<p class="smallgrey" style="margin:0 1em 1em">' . _( "Visits by known crawlers are not counted and visitor IP addresses are shortened before they are stored (\$stats_skip_bots, \$stats_anonymise_ip). Monthly Excel reports per database: Records → Import & Export." ) . '</p>';
?>
<style>
.stats-range a.pure-button{margin-bottom:.3em}.stats-cards{display:flex;flex-wrap:wrap;gap:.8em;margin:1em 0}
.stats-card{flex:1 1 10em;border:1px solid #d6dbe4;border-radius:8px;padding:.7em 1em;background:#f8fafc}
.stats-n{display:block;font-size:1.8em;font-weight:700;color:#1d3557}.stats-l{color:#444}
.stats-chart{width:100%;height:140px;background:#fff;border-bottom:1px solid #999}.b-views{fill:#2f6db5}.b-clicks{fill:#e58f2a}
.stats-legend span.k-views,.stats-legend span.k-clicks{display:inline-block;width:.9em;height:.9em;vertical-align:middle;margin-left:.6em}
.k-views{background:#2f6db5}.k-clicks{background:#e58f2a}.stats-table .num{text-align:right;white-space:nowrap}.stats-col{padding:0 .4em}
.stats-csv{font-size:.8em;font-weight:400;margin-left:.5em}
</style>
<?php
include( "../includes/footer.php" );
