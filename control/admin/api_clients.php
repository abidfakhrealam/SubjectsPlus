<?php
/**
 * @file api_clients.php
 * @brief Admin → API Clients: one key per application, each with its own rate limit and allowed services;
 *        usage of the last 30 days.
 * @since 4.7.1
 */

use SubjectsPlus\API\ApiClients;
use SubjectsPlus\Control\AuditLog;
use SubjectsPlus\Control\Schema;

$subsubcat  = "";
$subcat     = "admin";
$page_title = "API Clients";

include( "../includes/header.php" );

$csrf   = sp_csrf_token();
$msgs   = array();
$errs   = array();
$newKey = null;

if ( ! ApiClients::ready() ) {
	makePluslet( _( "API Clients" ), '<p>' . _( "Apply the database updates (Admin → Database Updates) to manage API keys." ) . '</p>', "no_overflow" );
	include( "../includes/footer.php" );
	exit;
}

if ( $_SERVER['REQUEST_METHOD'] === 'POST' ) {
	if ( ! sp_csrf_valid( $_POST['csrf'] ?? '' ) ) {
		$errs[] = _( "Your session has expired. Please reload the page and try again." );
	} elseif ( isset( $_POST['create'] ) ) {
		$name = trim( (string) ( $_POST['name'] ?? '' ) );
		if ( $name === '' ) {
			$errs[] = _( "Give the client a name (e.g. the application or web site that will use the key)." );
		} else {
			list( $id, $newKey ) = ApiClients::create( $name, (int) ( $_POST['rate_limit'] ?? 0 ), (array) ( $_POST['services'] ?? array() ), $_SESSION['staff_id'] ?? null );
			AuditLog::add( 'api.client.create', 'api_client', (string) $id, 'API key created for ' . $name );
			$msgs[] = _( "Key created. Copy it now: it is not shown again." );
		}
	} elseif ( isset( $_POST['toggle'] ) ) {
		$id = (int) $_POST['toggle'];
		Schema::conn()->prepare( "UPDATE api_client SET active = 1 - active WHERE client_id = ?" )->execute( array( $id ) );
		AuditLog::add( 'api.client.toggle', 'api_client', (string) $id, 'API key enabled / disabled' );
		$msgs[] = _( "Saved." );
	} elseif ( isset( $_POST['delete'] ) ) {
		$id = (int) $_POST['delete'];
		Schema::conn()->prepare( "DELETE FROM api_client WHERE client_id = ?" )->execute( array( $id ) );
		AuditLog::add( 'api.client.delete', 'api_client', (string) $id, 'API key deleted' );
		$msgs[] = _( "Key deleted. Applications using it can no longer call the API." );
	} elseif ( isset( $_POST['save'] ) ) {
		foreach ( (array) ( $_POST['limit'] ?? array() ) as $id => $lim ) {
			$svc = array_values( array_intersect( (array) ( $_POST['svc'][ $id ] ?? array() ), ApiClients::SERVICES ) );
			Schema::conn()->prepare( "UPDATE api_client SET rate_limit = ?, services = ? WHERE client_id = ?" )->execute( array( max( 0, (int) $lim ), implode( ',', $svc ), (int) $id ) );
		}
		AuditLog::add( 'api.client.update', 'api_client', '', 'API client limits / services saved' );
		$msgs[] = _( "Saved." );
	}
}

$out = '';
foreach ( $msgs as $m ) {
	$out .= '<div class="feedback" role="status">' . sp_h( $m ) . '</div>';
}
foreach ( $errs as $e ) {
	$out .= '<div class="feedback" role="alert" style="background:#fdecea">' . sp_h( $e ) . '</div>';
}
if ( $newKey ) {
	$out .= '<div class="api-newkey" role="alert"><strong>' . _( "New API key" ) . ':</strong> <code id="api-key">' . sp_h( $newKey ) . '</code>
		<p class="smallgrey">' . _( "Send it as an HTTP header: X-API-Key: <key> (or Authorization: Bearer <key>)." ) . '</p></div>';
}
$status = '<p>' . ( empty( $api_enabled ) ? '<strong>' . _( "The API is switched off" ) . '</strong> — ' . _( "turn it on in Admin → Config Site (API section)." ) : _( "The API is on." ) )
          . ' ' . sprintf( _( "Default limit: %d requests per minute per key (\$api_rate_limit); %d per minute per IP address for requests with a wrong key (\$api_rate_limit_failed)." ), (int) ( $api_rate_limit ?? 120 ), (int) ( $api_rate_limit_failed ?? 20 ) )
          . ' ' . ( ! empty( $api_key ) ? _( "The site key from Config Site also works (\"legacy key\"); replace it with one key per application." ) : '' ) . '</p>';

$svcBoxes = function ( $name, array $on ) {
	$h = '';
	foreach ( ApiClients::SERVICES as $s ) {
		$h .= '<label style="margin-right:.6em;white-space:nowrap"><input type="checkbox" name="' . $name . '[]" value="' . $s . '"' . ( in_array( $s, $on, true ) ? ' checked' : '' ) . '> ' . $s . '</label>';
	}

	return $h;
};

$create = '<form method="post" class="pure-form pure-form-stacked"><input type="hidden" name="csrf" value="' . sp_h( $csrf ) . '">
	<label for="api-name">' . _( "Name (application or web site)" ) . '</label><input type="text" id="api-name" name="name" maxlength="150" class="pure-input-1-2" required>
	<label for="api-limit">' . _( "Requests per minute (0 = site default)" ) . '</label><input type="number" id="api-limit" name="rate_limit" value="0" min="0" style="width:8em">
	<fieldset><legend>' . _( "Allowed services (none ticked = all)" ) . '</legend>' . $svcBoxes( 'services', array() ) . '</fieldset>
	<p><button type="submit" name="create" value="1" class="pure-button pure-button-primary">' . _( "Create key" ) . '</button></p></form>';

$list = '<form method="post" class="pure-form"><input type="hidden" name="csrf" value="' . sp_h( $csrf ) . '"><div style="overflow-x:auto"><table class="pure-table pure-table-horizontal"><thead><tr>
	<th scope="col">' . _( "Name" ) . '</th><th scope="col">' . _( "Key starts with" ) . '</th><th scope="col">' . _( "Per minute" ) . '</th><th scope="col">' . _( "Services" ) . '</th><th scope="col">' . _( "Last used" ) . '</th><th scope="col">' . _( "Status" ) . '</th><th></th></tr></thead><tbody>';
$clients = ApiClients::all();
foreach ( $clients as $c ) {
	$id    = (int) $c['client_id'];
	$list .= '<tr><td>' . sp_h( $c['name'] ) . '</td><td><code>' . sp_h( $c['key_prefix'] ) . '…</code></td>
		<td><input type="number" name="limit[' . $id . ']" value="' . (int) $c['rate_limit'] . '" min="0" style="width:6em" aria-label="' . sp_h( _( "Requests per minute" ) ) . '"></td>
		<td>' . $svcBoxes( 'svc[' . $id . ']', array_filter( explode( ',', (string) $c['services'] ) ) ) . '</td>
		<td>' . sp_h( $c['last_used_at'] ?: '—' ) . '</td><td>' . ( (int) $c['active'] ? _( "active" ) : '<strong>' . _( "disabled" ) . '</strong>' ) . '</td>
		<td><button type="submit" name="toggle" value="' . $id . '" class="pure-button">' . ( (int) $c['active'] ? _( "Disable" ) : _( "Enable" ) ) . '</button>
		<button type="submit" name="delete" value="' . $id . '" class="pure-button" style="color:#a00" data-confirm="' . sp_h( _( "Delete this key?" ) ) . '">' . _( "Delete" ) . '</button></td></tr>';
}
if ( ! $clients ) {
	$list .= '<tr><td colspan="7">' . _( "No keys yet." ) . '</td></tr>';
}
$list .= '</tbody></table></div>' . ( $clients ? '<p><button type="submit" name="save" value="1" class="pure-button pure-button-primary">' . _( "Save limits and services" ) . '</button></p>' : '' ) . '</form>';

$usage = '<div style="overflow-x:auto"><table class="pure-table pure-table-horizontal"><thead><tr><th scope="col">' . _( "Client" ) . '</th><th scope="col">' . _( "Service" ) . '</th><th scope="col">' . _( "Answered" ) . '</th><th scope="col">' . _( "Rate-limited" ) . '</th><th scope="col">' . _( "Refused / errors" ) . '</th></tr></thead><tbody>';
$rows  = ApiClients::usage( 30 );
foreach ( $rows as $u ) {
	$usage .= '<tr><td>' . sp_h( (int) $u['client_id'] === 0 ? _( "legacy key / no key" ) : $u['name'] ) . '</td><td>' . sp_h( $u['service'] ) . '</td><td>' . (int) $u['ok'] . '</td><td>' . (int) $u['limited'] . '</td><td>' . (int) $u['errors'] . '</td></tr>';
}
if ( ! $rows ) {
	$usage .= '<tr><td colspan="5">' . _( "No API requests in the last 30 days." ) . '</td></tr>';
}
$usage .= '</tbody></table></div>';

print '<div class="pure-g"><div class="pure-u-1">' . $out;
makePluslet( _( "API status" ), $status, "no_overflow" );
makePluslet( _( "Keys" ), $list, "no_overflow" );
makePluslet( _( "Create a key" ), $create, "no_overflow" );
makePluslet( _( "Usage, last 30 days" ), $usage, "no_overflow" );
print '</div></div><style>.api-newkey{background:#e8f5e9;border-left:5px solid #2e7d32;padding:.8em 1em;margin:.5em 0}.api-newkey code{font-size:1.1em;user-select:all}</style>';
print '<script src="' . sp_h( $AssetPath . 'js/admin/confirm.js?v=' . rawurlencode( sp_version() ) ) . '" defer></script>';
include( "../includes/footer.php" );
