<?php
/**
 * @file access.php
 * @brief manage off-campus access providers (EZproxy, OpenAthens, RemoteXs, RemoteLog, MyLOFT, custom)
 *        and which databases (by domain) use each one.
 */

use SubjectsPlus\Control\Querier;
use SubjectsPlus\Control\AccessLinks;

$subsubcat  = "";
$subcat     = "admin";
$page_title = "Off-campus Access";

include( "../includes/header.php" );
include( "../includes/autoloader.php" );

$db   = new Querier;
$conn = $db->getConnection();

function sp_access_h( $s ) {
	return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
}

// CSRF token for this page's forms
if ( empty( $_SESSION['sp_access_csrf'] ) ) {
	$_SESSION['sp_access_csrf'] = bin2hex( random_bytes( 32 ) );
}
$csrf = $_SESSION['sp_access_csrf'];

$feedback = '';
$errors   = array();

// Tables present?
$tablesReady = (bool) $conn->query( "SHOW TABLES LIKE 'access_provider'" )->fetch();

function sp_access_valid_template( $t ) {
	$t = trim( (string) $t );

	return $t === '' || preg_match( '#^https?://[^\s"<>]+$#i', $t );
}

function sp_access_save_rules( $conn, $providerId, $domainsText ) {
	$domains = array();
	foreach ( preg_split( '/[\r\n,;]+/', (string) $domainsText ) as $line ) {
		$d = AccessLinks::normaliseDomain( $line );
		if ( $d !== '' && preg_match( '/^[a-z0-9.-]+\.[a-z]{2,}$/', $d ) ) {
			$domains[ $d ] = true;
		}
	}
	$conn->prepare( "DELETE FROM access_rule WHERE provider_id = ?" )->execute( array( $providerId ) );
	$ins = $conn->prepare( "INSERT INTO access_rule (provider_id, domain) VALUES (?, ?)
		ON DUPLICATE KEY UPDATE provider_id = VALUES(provider_id)" ); // a domain belongs to one provider: move it here

	foreach ( array_keys( $domains ) as $d ) {
		$ins->execute( array( $providerId, $d ) );
	}

	return count( $domains );
}

if ( $tablesReady && $_SERVER['REQUEST_METHOD'] === 'POST' ) {
	if ( ! isset( $_POST['csrf'] ) || ! hash_equals( $csrf, (string) $_POST['csrf'] ) ) {
		$errors[] = _( "Your session has expired. Please reload the page and try again." );
	} else {
		$action = isset( $_POST['action'] ) ? $_POST['action'] : '';

		if ( $action === 'save' ) {
			$id       = isset( $_POST['provider_id'] ) ? (int) $_POST['provider_id'] : 0;
			$name     = trim( (string) ( $_POST['name'] ?? '' ) );
			$kind     = array_key_exists( $_POST['kind'] ?? '', AccessLinks::presets() ) ? $_POST['kind'] : 'custom';
			$template = trim( (string) ( $_POST['link_template'] ?? '' ) );
			$portal   = trim( (string) ( $_POST['portal_url'] ?? '' ) );
			$note     = trim( strip_tags( (string) ( $_POST['note'] ?? '' ) ) );
			$default  = ! empty( $_POST['is_default'] ) ? 1 : 0;
			$active   = ! empty( $_POST['active'] ) ? 1 : 0;

			if ( $name === '' ) {
				$errors[] = _( "Please give the provider a name." );
			}
			if ( ! sp_access_valid_template( $template ) ) {
				$errors[] = _( "The link template must start with http:// or https:// (or be left empty)." );
			}
			if ( $portal !== '' && ! sp_access_valid_template( $portal ) ) {
				$errors[] = _( "The portal address must start with http:// or https://." );
			}

			if ( ! $errors ) {
				if ( $default ) {
					$conn->exec( "UPDATE access_provider SET is_default = 0" );
				}
				if ( $id > 0 ) {
					$st = $conn->prepare( "UPDATE access_provider SET name=?, kind=?, link_template=?, portal_url=?, note=?, is_default=?, active=?, updated_at=NOW() WHERE provider_id=?" );
					$st->execute( array( $name, $kind, $template, $portal, $note, $default, $active, $id ) );
				} else {
					$st = $conn->prepare( "INSERT INTO access_provider (name, kind, link_template, portal_url, note, is_default, active, updated_at) VALUES (?,?,?,?,?,?,?,NOW())" );
					$st->execute( array( $name, $kind, $template, $portal, $note, $default, $active ) );
					$id = (int) $conn->lastInsertId();
				}
				$n        = sp_access_save_rules( $conn, $id, $_POST['domains'] ?? '' );
				$feedback = sprintf( _( "Saved %s (%d domain rules)." ), sp_access_h( $name ), $n );
			}
		} elseif ( $action === 'delete' ) {
			$id = (int) ( $_POST['provider_id'] ?? 0 );
			$conn->prepare( "DELETE FROM access_provider WHERE provider_id = ?" )->execute( array( $id ) );
			$feedback = _( "Provider deleted. Its domains now use the default provider." );
		}
		AccessLinks::reset();
	}
}

// ---------- Load data ----------
$providers = array();
$rulesBy   = array();
if ( $tablesReady ) {
	$providers = $conn->query( "SELECT * FROM access_provider ORDER BY is_default DESC, name" )->fetchAll( PDO::FETCH_ASSOC );
	foreach ( $conn->query( "SELECT provider_id, domain FROM access_rule ORDER BY domain" )->fetchAll( PDO::FETCH_ASSOC ) as $r ) {
		$rulesBy[ (int) $r['provider_id'] ][] = $r['domain'];
	}
}
$presets = AccessLinks::presets();

// ---------- Link tester ----------
$testUrl    = isset( $_GET['test_url'] ) ? trim( (string) $_GET['test_url'] ) : '';
$testResult = '';
if ( $testUrl !== '' ) {
	$p          = AccessLinks::providerFor( $testUrl );
	$out        = AccessLinks::url( $testUrl, AccessLinks::RESTRICTED );
	$testResult = "<p><strong>" . _( "Provider" ) . ":</strong> " . ( $p ? sp_access_h( $p['name'] ) : _( "none (legacy \$proxyURL or direct link)" ) ) . "</p>
		<p><strong>" . _( "Off-campus link" ) . ":</strong><br><code style=\"word-break:break-all\">" . sp_access_h( $out ) . "</code></p>"
	              . ( $p && $p['note'] !== '' ? "<p><strong>" . _( "Note shown" ) . ":</strong> " . sp_access_h( $p['note'] ) . "</p>" : '' );
}

// ---------- Render ----------
function sp_access_form( $p, $domains, $presets, $csrf ) {
	$isNew = empty( $p['provider_id'] );
	$id    = $isNew ? 0 : (int) $p['provider_id'];
	$opts  = '';
	foreach ( $presets as $key => $preset ) {
		$sel   = ( ( $p['kind'] ?? 'custom' ) === $key ) ? ' selected' : '';
		$opts .= '<option value="' . sp_access_h( $key ) . '" data-template="' . sp_access_h( $preset['template'] ) . '" data-note="' . sp_access_h( $preset['note'] ) . '" data-hint="' . sp_access_h( $preset['hint'] ?? '' ) . '"' . $sel . '>' . sp_access_h( $preset['label'] ) . '</option>';
	}
	$checkedDefault = ! empty( $p['is_default'] ) ? ' checked' : '';
	$checkedActive  = ( $isNew || ! empty( $p['active'] ) ) ? ' checked' : '';

	return '<form method="post" action="access.php" class="pure-form pure-form-stacked sp-access-form">
	<input type="hidden" name="csrf" value="' . sp_access_h( $csrf ) . '">
	<input type="hidden" name="action" value="save">
	<input type="hidden" name="provider_id" value="' . $id . '">
	<label>' . _( "Type" ) . '</label>
	<select name="kind" class="sp-access-kind"' . ( $isNew ? ' data-new="1"' : '' ) . '>' . $opts . '</select>
	<label>' . _( "Name shown to staff" ) . '</label>
	<input type="text" name="name" class="pure-input-1" maxlength="100" value="' . sp_access_h( $p['name'] ?? '' ) . '" required>
	<label>' . _( "Link template" ) . ' <small>' . _( "{url} = resource address, {url_enc} = encoded address. Leave empty to keep links unchanged (e.g. MyLOFT app)." ) . '</small></label>
	<input type="text" name="link_template" class="pure-input-1 sp-access-template" value="' . sp_access_h( $p['link_template'] ?? '' ) . '">
	<p class="sp-access-hint" style="margin:.2em 0 0;font-size:.85em;color:#555">' . sp_access_h( $presets[ $p['kind'] ?? 'custom' ]['hint'] ?? '' ) . '</p>
	<label>' . _( "Portal / login page (optional)" ) . '</label>
	<input type="text" name="portal_url" class="pure-input-1" value="' . sp_access_h( $p['portal_url'] ?? '' ) . '">
	<label>' . _( "Note for users (optional)" ) . '</label>
	<input type="text" name="note" class="pure-input-1 sp-access-note" maxlength="500" value="' . sp_access_h( $p['note'] ?? '' ) . '">
	<label>' . _( "Databases that use this provider — one domain per line (e.g. jstor.org)" ) . '</label>
	<textarea name="domains" rows="5" class="pure-input-1">' . sp_access_h( implode( "\n", $domains ) ) . '</textarea>
	<label><input type="checkbox" name="is_default" value="1"' . $checkedDefault . '> ' . _( "Default for all other restricted databases" ) . '</label>
	<label><input type="checkbox" name="active" value="1"' . $checkedActive . '> ' . _( "Active" ) . '</label>
	<button type="submit" class="pure-button pure-button-primary">' . ( $isNew ? _( "Add provider" ) : _( "Save" ) ) . '</button>
	</form>' . ( $isNew ? '' : '<form method="post" action="access.php" onsubmit="return confirm(\'' . _( "Delete this provider?" ) . '\');" style="margin-top:.5em">
	<input type="hidden" name="csrf" value="' . sp_access_h( $csrf ) . '"><input type="hidden" name="action" value="delete"><input type="hidden" name="provider_id" value="' . $id . '">
	<button type="submit" class="pure-button sp-access-secondary">' . _( "Delete" ) . '</button></form>' );
}

print '<div class="pure-g"><div class="pure-u-1">';
if ( $feedback ) {
	print '<div class="feedback">' . $feedback . '</div>';
}
foreach ( $errors as $e ) {
	print '<div class="feedback" style="background:#fdd">' . sp_access_h( $e ) . '</div>';
}
print '</div></div>';

if ( ! $tablesReady ) {
	makePluslet( _( "Off-campus Access" ), "<p>" . _( "Run <code>php tools/migrate.php</code> on the server to enable this page." ) . "</p>", "no_overflow" );
	include( "../includes/footer.php" );
	exit;
}

print '<div class="pure-g"><div class="pure-u-1-3">';

$intro = '<p>' . _( "Links to databases marked <em>Restricted</em> or <em>On Campus Only</em> are sent through one of these services so they work off campus. Databases marked <em>Rest--No Proxy</em> or <em>None</em> are linked directly." ) . '</p>
<p>' . _( "Each database uses the provider whose domain list matches it; anything else uses the default provider." ) . '</p>';
makePluslet( _( "How this works" ), $intro, "no_overflow" );

$tester = '<form method="get" action="access.php" class="pure-form pure-form-stacked">
<label>' . _( "Paste a database address" ) . '</label>
<input type="url" name="test_url" class="pure-input-1" placeholder="https://www.jstor.org/" value="' . sp_access_h( $testUrl ) . '">
<button type="submit" class="pure-button sp-access-secondary">' . _( "Show off-campus link" ) . '</button></form>' . $testResult;
makePluslet( _( "Test a link" ), $tester, "no_overflow" );

makePluslet( _( "Add a provider" ), sp_access_form( array(), array(), $presets, $csrf ), "no_overflow" );

print '</div><div class="pure-u-2-3">';

if ( ! $providers ) {
	global $proxyURL;
	makePluslet( _( "Providers" ), '<p>' . _( "No providers yet." ) . ' ' . ( $proxyURL ? sprintf( _( "Restricted links currently use the proxy prefix from config.php: %s" ), '<code>' . sp_access_h( $proxyURL ) . '</code>' ) : _( "Restricted links are currently not proxied." ) ) . '</p>', "no_overflow" );
}
foreach ( $providers as $p ) {
	$title = sp_access_h( $p['name'] ) . ( $p['is_default'] ? ' — ' . _( "default" ) : '' ) . ( $p['active'] ? '' : ' — ' . _( "inactive" ) );
	makePluslet( $title, sp_access_form( $p, $rulesBy[ (int) $p['provider_id'] ] ?? array(), $presets, $csrf ), "no_overflow" );
}

print '</div></div>';
?>
<style>.sp-access-secondary{background:#fff;color:#333;border:1px solid #888}.sp-access-secondary:hover{background:#eee}</style>
<script>
// Fill in the template and note from the chosen type when adding a provider
document.querySelectorAll('.sp-access-kind').forEach(function (sel) {
	sel.addEventListener('change', function () {
		var form = sel.form, opt = sel.options[sel.selectedIndex];
		var t = form.querySelector('.sp-access-template'), n = form.querySelector('.sp-access-note'), name = form.querySelector('[name=name]');
		if (sel.dataset.new || t.value === '') { t.value = opt.dataset.template || ''; }
		if (sel.dataset.new || n.value === '') { n.value = opt.dataset.note || ''; }
		if (name.value === '') { name.value = opt.textContent; }
		var h = form.querySelector('.sp-access-hint'); if (h) { h.textContent = opt.dataset.hint || ''; }
	});
});
</script>
<?php
include( "../includes/footer.php" );
