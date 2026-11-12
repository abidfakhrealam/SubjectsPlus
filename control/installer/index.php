<?php
/**
 * @file control/installer/index.php
 * @brief SubjectsPlus installer wizard.
 *
 *   1 Install code      — proves the person installing has access to the server
 *   2 Requirements      — PHP, extensions, folders, HTTPS, web-server protection
 *   3 Database          — use an existing empty database, or create the database and its user
 *   4 Site & admin      — site address (proxy-aware, optional "always HTTPS"), administrator account
 *   5 Options           — email (with a test message), off-campus access, sample content
 *   6 Install           — tables, database updates, config.php (saved, or downloaded when the folder is read-only)
 *
 * The wizard answers 404 once SubjectsPlus is installed (control/includes/config.php exists).
 */

// ---------------------------------------------------------------- bootstrap
chdir( __DIR__ );
require_once dirname( __DIR__ ) . '/includes/functions.php'; // autoloader, security helpers, cookie settings
require_once dirname( __DIR__, 2 ) . '/tools/migrations.php';

use SubjectsPlus\Control\Install\ConfigWriter;
use SubjectsPlus\Control\Install\Database;
use SubjectsPlus\Control\Install\Environment;
use SubjectsPlus\Control\Install\Requirements;
use SubjectsPlus\Control\Install\SampleContent;
use SubjectsPlus\Control\Installer;

header( 'X-Frame-Options: DENY' );
header( 'X-Content-Type-Options: nosniff' );
header( 'Referrer-Policy: no-referrer' );
header( 'Cache-Control: no-store' );

if ( session_status() !== PHP_SESSION_ACTIVE ) {
	session_name( 'SPINSTALL' );
	session_start();
}
if ( ! isset( $_SESSION['spi'] ) || ! is_array( $_SESSION['spi'] ) ) {
	$_SESSION['spi'] = array();
}
$S = &$_SESSION['spi'];

$step = isset( $_GET['step'] ) ? preg_replace( '/[^a-z]/', '', (string) $_GET['step'] ) : '';

// Installed: nothing to do here — except the final page of the run that just finished.
if ( Environment::isInstalled() && ! ( $step === 'done' && ! empty( $S['finished'] ) ) && ! ( $step === 'config' && ! empty( $S['config_src'] ) ) ) {
	http_response_code( 404 );
	echo '<!doctype html><meta charset="utf-8"><title>Not found</title><p>SubjectsPlus is already installed.</p>';
	exit;
}

if ( empty( $S['csrf'] ) ) {
	$S['csrf'] = bin2hex( random_bytes( 32 ) );
}
$errors = array();
$notes  = array();

function spi_post_ok() {
	global $S;

	return $_SERVER['REQUEST_METHOD'] === 'POST' && sp_is_same_origin_request()
	       && isset( $_POST['csrf'] ) && is_string( $_POST['csrf'] ) && hash_equals( $S['csrf'], $_POST['csrf'] );
}

function spi_go( $step ) {
	header( 'Location: index.php?step=' . rawurlencode( $step ) );
	exit;
}

function spi_v( $k, $default = '' ) {
	global $S;

	return isset( $_POST[ $k ] ) && is_string( $_POST[ $k ] ) ? trim( $_POST[ $k ] ) : ( $S['form'][ $k ] ?? $default );
}

$order = array( 'code' => 'Install code', 'requirements' => 'Requirements', 'database' => 'Database', 'site' => 'Site & administrator',
                'options' => 'Options', 'install' => 'Install' );

// ---------------------------------------------------------------- routing / gates
if ( $step === '' ) {
	$step = empty( $S['unlocked'] ) ? 'code' : 'requirements';
}
if ( $step !== 'code' && empty( $S['unlocked'] ) ) {
	spi_go( 'code' );
}
$needs = array( 'database' => 'req_ok', 'site' => 'db', 'options' => 'site', 'install' => 'site', 'config' => 'config_src', 'done' => 'finished' );
if ( isset( $needs[ $step ] ) && empty( $S[ $needs[ $step ] ] ) ) {
	spi_go( 'requirements' );
}
if ( $_SERVER['REQUEST_METHOD'] === 'POST' && ! spi_post_ok() ) {
	$errors[] = 'Your session expired or the form was sent from another site. Please try again.';
	$_POST    = array();
}

// ---------------------------------------------------------------- 1. install code
if ( $step === 'code' && isset( $_POST['code'] ) ) {
	if ( Environment::codeMatches( (string) $_POST['code'] ) ) {
		session_regenerate_id( true );
		$S['unlocked'] = true;
		spi_go( 'requirements' );
	}
	usleep( 800000 ); // slow down guessing
	$errors[] = Environment::expectedCode() === '' ? 'No install code has been created yet (see below).' : 'That code is not correct.';
}

// ---------------------------------------------------------------- 2. requirements
$checks = array();
if ( $step === 'requirements' ) {
	$checks        = Requirements::run( Environment::detectBaseUrl() );
	$S['req_ok']   = ! Requirements::hasFailures( $checks );
	if ( isset( $_POST['next'] ) && $S['req_ok'] ) {
		spi_go( 'database' );
	}
}

// ---------------------------------------------------------------- 3. database
$dbInfo = null;
if ( $step === 'database' && isset( $_POST['action'] ) ) {
	$mode = ( $_POST['mode'] ?? 'existing' ) === 'create' ? 'create' : 'existing';
	$db   = array(
		'host' => spi_v( 'db_host', '127.0.0.1' ), 'port' => (string) (int) spi_v( 'db_port', '3306' ),
		'name' => spi_v( 'db_name', 'subjectsplus' ), 'user' => spi_v( 'db_user', 'subjectsplus' ),
		'pass' => (string) ( $_POST['db_pass'] ?? '' ), 'mode' => $mode,
	);
	$S['form'] = array_merge( $S['form'] ?? array(), array( 'db_host' => $db['host'], 'db_port' => $db['port'], 'db_name' => $db['name'], 'db_user' => $db['user'], 'mode' => $mode ) );
	if ( ! preg_match( '/^[A-Za-z0-9.:\[\]_-]{1,255}$/', $db['host'] ) ) {
		$errors[] = 'The database host name is not valid.';
	} elseif ( ! Database::validName( $db['name'] ) || ! Database::validUser( $db['user'] ) ) {
		$errors[] = 'Database and user names may only contain letters, digits and _ (user names also . and -).';
	} else {
		try {
			if ( $mode === 'create' && $_POST['action'] === 'test' ) {
				// nothing exists yet: test the administrator account that will create it
				$apdo  = Database::connect( $db['host'], $db['port'], (string) ( $_POST['admin_user'] ?? '' ), (string) ( $_POST['admin_pass'] ?? '' ) );
				$ainfo = Database::serverInfo( $apdo );
				$have  = $apdo->prepare( 'SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = ?' );
				$have->execute( array( $db['name'] ) );
				$notes[] = "Signed in to {$ainfo['product']} {$ainfo['version']} as the administrator. Database `{$db['name']}` "
				           . ( (int) $have->fetchColumn() ? 'already exists (it will be reused if empty).' : 'will be created.' );
				foreach ( $ainfo['warnings'] as $w ) {
					$errors[] = $w;
				}
				throw new \LogicException( 'tested' );
			}
			if ( $mode === 'create' && $_POST['action'] === 'next' ) {
				$adminUser = (string) ( $_POST['admin_user'] ?? '' );
				$adminPass = (string) ( $_POST['admin_pass'] ?? '' );
				foreach ( Database::createDatabaseAndUser( $db['host'], $db['port'], array( $adminUser, $adminPass ), $db['name'], $db['user'], $db['pass'] ) as $l ) {
					$notes[] = $l;
				}
				$S['db_created'] = $notes;
			}
			$pdo    = Database::connect( $db['host'], $db['port'], $db['user'], $db['pass'], $db['name'] );
			$dbInfo = Database::serverInfo( $pdo );
			$tables = Database::existingTables( $pdo );
			if ( $_POST['action'] === 'test' ) {
				$notes[] = "Connected to {$dbInfo['product']} {$dbInfo['version']} as {$db['user']}." . ( $tables ? ' This database already contains SubjectsPlus tables.' : ' The database is empty.' );
				foreach ( $dbInfo['warnings'] as $w ) {
					$errors[] = $w;
				}
			} elseif ( $tables && empty( $_POST['reuse'] ) ) {
				$errors[]      = 'This database already contains SubjectsPlus tables. Use an empty database, or tick “Keep the existing data” to only write a new config.php for it.';
				$S['db_reuse'] = true;
			} else {
				$db['reuse'] = $tables > 0;
				$S['db']     = $db;
				spi_go( 'site' );
			}
		} catch ( \LogicException $e ) {
			// connection test finished
		} catch ( \PDOException $e ) { // (a RuntimeException too, so it must come first)
			$errors[] = 'Could not connect: ' . Database::shortError( $e ) . ( $mode === 'existing' ? ' — or choose “Create the database and user for me”.' : '' );
		} catch ( \RuntimeException $e ) {
			$errors[] = $e->getMessage();
		}
	}
}

// ---------------------------------------------------------------- 4. site & administrator
if ( $step === 'site' && isset( $_POST['next'] ) ) {
	$site = array(
		'base_url'    => Environment::cleanBaseUrl( spi_v( 'base_url' ) ),
		'force_https' => ! empty( $_POST['force_https'] ),
		'site_name'   => mb_substr( spi_v( 'site_name', 'Research Guides' ), 0, 100 ),
		'institution' => mb_substr( spi_v( 'institution' ), 0, 150 ),
		'fname'       => mb_substr( spi_v( 'fname' ), 0, 60 ),
		'lname'       => mb_substr( spi_v( 'lname' ), 0, 60 ),
		'email'       => strtolower( spi_v( 'email' ) ),
	);
	$S['form'] = array_merge( $S['form'] ?? array(), $site, array( 'base_url' => spi_v( 'base_url' ) ) );
	$pw        = (string) ( $_POST['password'] ?? '' );
	if ( $site['base_url'] === '' ) {
		$errors[] = 'Enter the site address as http(s)://host/path/ (the folder that contains control/ and subjects/).';
	}
	if ( $site['force_https'] && strpos( $site['base_url'], 'https://' ) !== 0 ) {
		$errors[] = '“Always use HTTPS” needs a site address that starts with https://.';
	}
	if ( ! filter_var( $site['email'], FILTER_VALIDATE_EMAIL ) ) {
		$errors[] = 'Enter a valid administrator email address (it is the sign-in name).';
	}
	if ( $site['fname'] === '' || $site['lname'] === '' || $site['site_name'] === '' ) {
		$errors[] = 'Fill in the site name and the administrator\'s first and last name.';
	}
	if ( strlen( $pw ) < 12 ) {
		$errors[] = 'The administrator password must have at least 12 characters.';
	} elseif ( $pw !== (string) ( $_POST['password2'] ?? '' ) ) {
		$errors[] = 'The two passwords are not the same.';
	} elseif ( strcasecmp( $pw, $site['email'] ) === 0 || strcasecmp( $pw, explode( '@', $site['email'] )[0] ) === 0 || stripos( $pw, $site['email'] ) !== false
	           || preg_match( '/^(.)\1+$/', $pw ) || preg_match( '/^(password|qwerty|123456|subjectsplus|letmein|welcome)[\d!@#.]*$/i', $pw ) ) {
		$errors[] = 'Choose a less guessable password.';
	}
	if ( ! $errors ) {
		$site['password_hash'] = password_hash( $pw, PASSWORD_DEFAULT );
		$S['site']             = $site;
		spi_go( 'options' );
	}
}

// ---------------------------------------------------------------- 5. options (email test, access, sample content)
if ( $step === 'options' && isset( $_POST['action'] ) ) {
	$opt = array(
		'smtp_host' => spi_v( 'smtp_host' ), 'smtp_port' => (string) (int) spi_v( 'smtp_port', '587' ), 'smtp_user' => spi_v( 'smtp_user' ),
		'smtp_pass' => isset( $_POST['smtp_pass'] ) && $_POST['smtp_pass'] !== '' ? (string) $_POST['smtp_pass'] : ( $S['options']['smtp_pass'] ?? '' ),
		'proxy_kind' => in_array( $_POST['proxy_kind'] ?? '', array( 'none', 'ezproxy', 'openathens', 'custom' ), true ) ? $_POST['proxy_kind'] : 'none',
		'proxy_template' => spi_v( 'proxy_template' ), 'sample' => ! empty( $_POST['sample'] ),
	);
	$S['form']    = array_merge( $S['form'] ?? array(), array_diff_key( $opt, array( 'smtp_pass' => 1 ) ) );
	$S['options'] = $opt;
	if ( $opt['smtp_host'] !== '' && ! preg_match( '/^[A-Za-z0-9.-]{1,255}$/', $opt['smtp_host'] ) ) {
		$errors[] = 'The mail server name is not valid.';
	}
	if ( $opt['proxy_kind'] !== 'none' && ( ! preg_match( '#^https://[^\s"<>]+$#', $opt['proxy_template'] ) ) ) {
		$errors[] = 'The off-campus link template must start with https:// (e.g. https://ezproxy.library.edu/login?url={url}).';
	}
	if ( ! $errors && $_POST['action'] === 'testmail' ) {
		if ( $opt['smtp_host'] === '' ) {
			$errors[] = 'Enter a mail server first.';
		} else {
			try {
				$m = new \PHPMailer\PHPMailer\PHPMailer( true );
				$m->isSMTP();
				$m->Host       = $opt['smtp_host'];
				$m->Port       = (int) $opt['smtp_port'];
				$m->SMTPSecure = (int) $opt['smtp_port'] === 465 ? 'ssl' : '';
				$m->SMTPAuth   = $opt['smtp_user'] !== '';
				$m->Username   = $opt['smtp_user'];
				$m->Password   = $opt['smtp_pass'];
				$m->Timeout    = 10;
				$m->CharSet    = 'UTF-8';
				$m->setFrom( $S['site']['email'], $S['site']['site_name'] );
				$m->addAddress( $S['site']['email'] );
				$m->Subject = 'SubjectsPlus test message';
				$m->Body    = "This message confirms that SubjectsPlus can send email through {$opt['smtp_host']}.";
				$m->send();
				$notes[] = 'Test message sent to ' . $S['site']['email'] . '.';
			} catch ( \Throwable $e ) {
				$errors[] = 'The test message could not be sent: ' . mb_substr( preg_replace( '/\s+/', ' ', $e->getMessage() ), 0, 250 );
			}
		}
	} elseif ( ! $errors && $_POST['action'] === 'next' ) {
		spi_go( 'install' );
	}
}

// ---------------------------------------------------------------- 6. install
if ( $step === 'install' && isset( $_POST['install'] ) && empty( $errors ) ) {
	@set_time_limit( 300 );
	$db   = $S['db'];
	$site = $S['site'];
	$opt  = $S['options'] ?? array( 'sample' => false, 'proxy_kind' => 'none', 'smtp_host' => '' );
	$log  = array();

	// the classes below read the classic config globals
	$GLOBALS['hname']          = $db['host'];
	$GLOBALS['uname']          = $db['user'];
	$GLOBALS['pword']          = $db['pass'];
	$GLOBALS['dbName_SPlus']   = $db['name'];
	$GLOBALS['db_port']        = $db['port'];
	$GLOBALS['sp_db_sql_mode'] = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

	try {
		$pdo = Database::connect( $db['host'], $db['port'], $db['user'], $db['pass'], $db['name'] );
		if ( empty( $db['reuse'] ) ) {
			ob_start();
			$ok  = ( new Installer() )->install();
			$out = ob_get_clean();
			if ( ! $ok ) {
				throw new \RuntimeException( 'Creating the tables failed: ' . trim( strip_tags( $out ) ) );
			}
			$log[] = 'Tables created.';
			$n     = sp_migrate_apply( sp_migrate_connect( $db['host'], $db['user'], $db['pass'], $db['name'], $db['port'] ), function () {} );
			$log[] = "$n database update(s) applied.";

			$pdo->prepare( "UPDATE staff SET email = ?, fname = ?, lname = ?, password = ?, title = 'Administrator', active = 1 WHERE staff_id = 1" )
			    ->execute( array( $site['email'], $site['fname'], $site['lname'], $site['password_hash'] ) );
			$log[] = 'Administrator account ' . $site['email'] . ' created with the password you chose.';

			if ( $opt['proxy_kind'] !== 'none' ) {
				$names = array( 'ezproxy' => 'EZproxy', 'openathens' => 'OpenAthens', 'custom' => 'Off-campus access' );
				$pdo->prepare( "INSERT INTO access_provider (name, kind, link_template, portal_url, note, is_default, active, updated_at) VALUES (?, ?, ?, '', '', 1, 1, NOW())" )
				    ->execute( array( $names[ $opt['proxy_kind'] ], $opt['proxy_kind'], $opt['proxy_template'] ) );
				$log[] = $names[ $opt['proxy_kind'] ] . ' set as the default off-campus access provider.';
			}
			if ( ! empty( $opt['sample'] ) ) {
				$log = array_merge( $log, SampleContent::install( $pdo, 1 ) );
			}
		} else {
			$log[] = 'Existing SubjectsPlus data kept; only a new config.php was written. Sign in with an existing account.';
		}

		// admin's own staff folder (headshot)
		$folder = Environment::root() . '/assets/users/_' . sp_safe_filename( explode( '@', $site['email'] )[0] );
		if ( ! is_dir( $folder ) && @mkdir( $folder, 0755 ) ) {
			@copy( Environment::root() . '/assets/images/headshot.jpg', $folder . '/headshot.jpg' );
			@copy( Environment::root() . '/assets/images/headshot.jpg', $folder . '/headshot_large.jpg' );
		}

		$path   = (string) parse_url( $site['base_url'], PHP_URL_PATH );
		$domain = substr( strrchr( $site['email'], '@' ), 0 );
		$values = array(
			'BaseURL'                  => $site['base_url'],
			'CKBasePath'               => $path . 'ckeditor/',
			'resource_name'            => $site['site_name'],
			'institution_name'         => $site['institution'],
			'administrator'            => trim( $site['fname'] . ' ' . $site['lname'] ),
			'administrator_email'      => $site['email'],
			'email_key'                => $domain,
			'hname'                    => $db['host'],
			'uname'                    => $db['user'],
			'pword'                    => $db['pass'],
			'db_port'                  => $db['port'],
			'dbName_SPlus'             => $db['name'],
			'salt'                     => bin2hex( random_bytes( 16 ) ),
			'sp_encryption_key'        => bin2hex( random_bytes( 32 ) ),
			'api_key'                  => bin2hex( random_bytes( 20 ) ),
			'enforce_https_upload_url' => (bool) $site['force_https'],
			'sp_force_https'           => (bool) $site['force_https'],
			'email_host'               => $opt['smtp_host'] ?? '',
			'email_port'               => ( $opt['smtp_host'] ?? '' ) !== '' ? $opt['smtp_port'] : '',
			'email_smtp_auth'          => ( $opt['smtp_user'] ?? '' ) !== '' ? 'true' : '',
			'email_username'           => $opt['smtp_user'] ?? '',
			'email_password'           => $opt['smtp_pass'] ?? '',
		);
		if ( ! empty( $db['reuse'] ) ) {
			// keep the old site's secrets? Not known here: new ones only affect existing sessions and the API key.
			$log[] = 'New salt, encryption and API keys were generated: re-enter staff personal data that was encrypted with the old key, if any.';
		}
		$src = ConfigWriter::build( $values );

		$S['result'] = array( 'log' => $log, 'base_url' => $site['base_url'], 'email' => $site['email'], 'sample' => ! empty( $opt['sample'] ) );
		unset( $S['db']['pass'], $S['site']['password_hash'], $S['options']['smtp_pass'] );

		$written = ConfigWriter::write( $src );
		if ( $written === true ) {
			$S['result']['log'][] = 'config.php saved in control/includes/.';
			$S['result']['config_written'] = true;
			Environment::writeLock( array( 'version' => $GLOBALS['sp_version'] ?? '' ) );
			Environment::removeCode();
			$S['finished'] = true;
			spi_go( 'done' );
		}
		$S['config_src']    = $src;
		$S['config_values'] = array( 'hname' => $values['hname'], 'dbName_SPlus' => $values['dbName_SPlus'], 'uname' => $values['uname'], 'salt' => $values['salt'] );
		spi_go( 'config' );
	} catch ( \Throwable $e ) {
		error_log( 'SubjectsPlus installer: ' . $e->getMessage() );
		$errors[] = $e instanceof \RuntimeException ? $e->getMessage() : 'Installation failed: ' . Database::shortError( $e );
	}
}

// ---------------------------------------------------------------- config.php download (read-only folder)
if ( $step === 'config' ) {
	if ( isset( $_GET['download'] ) && hash_equals( $S['csrf'], (string) ( $_GET['t'] ?? '' ) ) ) {
		header( 'Content-Type: application/x-httpd-php; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="config.php"' );
		echo $S['config_src'];
		exit;
	}
	if ( isset( $_POST['check'] ) ) {
		if ( ConfigWriter::uploadedMatches( $S['config_values'] ) ) {
			Environment::writeLock( array( 'version' => $GLOBALS['sp_version'] ?? '' ) );
			Environment::removeCode();
			$S['finished'] = true;
			unset( $S['config_src'] );
			spi_go( 'done' );
		}
		$errors[] = is_file( Environment::configPath() ) ? 'control/includes/config.php is there, but it is not the file generated here. Upload the downloaded file unchanged.'
			: 'control/includes/config.php was not found yet.';
	}
}

// ---------------------------------------------------------------- view helpers
function spi_field( $name, $label, $value, $type = 'text', $help = '', $attrs = '' ) {
	return '<label for="f-' . sp_h( $name ) . '">' . sp_h( $label ) . '</label>'
	       . '<input id="f-' . sp_h( $name ) . '" name="' . sp_h( $name ) . '" type="' . sp_h( $type ) . '" value="' . sp_h( $value ) . '" ' . $attrs . '>'
	       . ( $help !== '' ? '<p class="help">' . $help . '</p>' : '' );
}

$csrfField = '<input type="hidden" name="csrf" value="' . sp_h( $S['csrf'] ) . '">';
$current   = $step === 'config' || $step === 'done' ? 'install' : $step;
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Install SubjectsPlus</title>
<style>
:root{--ink:#1d2433;--muted:#5b6475;--line:#d9dee7;--bg:#f4f6f9;--card:#fff;--accent:#2457a6;--ok:#1f7a3a;--warn:#9a6200;--fail:#b3261e}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
header{background:var(--ink);color:#fff;padding:14px 20px}header strong{font-size:18px}header span{color:#b9c2d3;margin-left:8px}
.wrap{max-width:860px;margin:0 auto;padding:20px 16px 60px}
ol.steps{list-style:none;padding:0;margin:0 0 18px;display:flex;flex-wrap:wrap;gap:6px}
ol.steps li{font-size:13px;padding:5px 10px;border-radius:14px;background:#e6eaf1;color:var(--muted)}
ol.steps li.on{background:var(--accent);color:#fff}ol.steps li.done{background:#d8ecdd;color:var(--ok)}
.card{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:22px;margin-bottom:16px}
h1{font-size:22px;margin:0 0 6px}h2{font-size:17px;margin:20px 0 8px}p.lead{color:var(--muted);margin-top:0}
label{display:block;font-weight:600;margin:14px 0 4px}input[type=text],input[type=email],input[type=password],input[type=url],input[type=number],select,textarea{width:100%;padding:9px 10px;border:1px solid #b9c1ce;border-radius:6px;font:inherit;background:#fff}
input:focus,select:focus,textarea:focus{outline:3px solid #9ab8e8;border-color:var(--accent)}
.help{font-size:13.5px;color:var(--muted);margin:4px 0 0}.row{display:grid;grid-template-columns:1fr 1fr;gap:0 16px}@media(max-width:620px){.row{grid-template-columns:1fr}}
button,.btn{display:inline-block;background:var(--accent);color:#fff;border:0;border-radius:6px;padding:10px 18px;font:inherit;font-weight:600;cursor:pointer;text-decoration:none;margin:16px 8px 0 0}
button.secondary,.btn.secondary{background:#e6eaf1;color:var(--ink)}button[disabled]{opacity:.5;cursor:not-allowed}
.msg{border-radius:8px;padding:10px 14px;margin:0 0 14px}.msg.err{background:#fdecea;color:var(--fail)}.msg.ok{background:#e7f4ea;color:var(--ok)}
table{width:100%;border-collapse:collapse;font-size:14.5px}td,th{text-align:left;padding:7px 6px;border-bottom:1px solid var(--line);vertical-align:top}
.st{font-weight:700;white-space:nowrap}.st.ok{color:var(--ok)}.st.warn{color:var(--warn)}.st.fail{color:var(--fail)}.st.info{color:var(--muted)}
code,pre{font:13.5px ui-monospace,Menlo,Consolas,monospace;background:#eef1f6;border-radius:4px;padding:1px 5px}pre{padding:10px;overflow:auto;white-space:pre-wrap;word-break:break-all}
.choice{border:1px solid var(--line);border-radius:8px;padding:10px 12px;margin-top:10px}.choice label{display:inline;font-weight:600;margin:0}
.meter{height:6px;border-radius:3px;background:#e6eaf1;margin-top:6px;overflow:hidden}.meter i{display:block;height:100%;width:0;background:var(--fail);transition:width .2s}
.inline{display:flex;gap:8px;align-items:center}.inline input{flex:1}.inline button{margin:0}
</style>
</head>
<body>
<header><strong>SubjectsPlus</strong><span>installer</span></header>
<div class="wrap">
<ol class="steps">
<?php
$reached = true;
foreach ( $order as $k => $label ) {
	$cls = $k === $current ? 'on' : ( $reached ? 'done' : '' );
	if ( $k === $current ) {
		$reached = false;
	}
	echo '<li class="' . $cls . '">' . sp_h( $label ) . '</li>';
}
?>
</ol>
<?php foreach ( $errors as $e ) { echo '<div class="msg err" role="alert">' . sp_h( $e ) . '</div>'; } ?>
<?php foreach ( $notes as $n ) { echo '<div class="msg ok">' . sp_h( $n ) . '</div>'; } ?>

<?php if ( $step === 'code' ) : ?>
<div class="card">
<h1>Install code</h1>
<p class="lead">To make sure that only someone with access to this server can install SubjectsPlus, enter the one-time install code.</p>
<form method="post" action="index.php?step=code"><?php echo $csrfField; ?>
<?php echo spi_field( 'code', 'Install code', '', 'text', '', 'autocomplete="off" autofocus required placeholder="XXXX-XXXX-XXXX-XXXX"' ); ?>
<button type="submit">Continue</button>
</form>
<h2>Where do I get the code?</h2>
<p>On the server, in the SubjectsPlus folder, run as the web-server user:</p>
<pre>sudo -u www-data php tools/install-code.php</pre>
<p class="help">Windows / WampServer: <code>C:\wamp64\bin\php\php8.x\php.exe tools\install-code.php</code>. No shell access? Create the file
<code>control/includes/install-code.php</code> containing <code>&lt;?php return 'a-phrase-of-at-least-12-characters';</code> and enter that phrase.
Containers can set the environment variable <code>SP_INSTALL_CODE</code>. The code is deleted when installation finishes.</p>
</div>

<?php elseif ( $step === 'requirements' ) : ?>
<div class="card">
<h1>Requirements</h1>
<p class="lead">Red items must be fixed before installing; amber items are recommendations.</p>
<table><thead><tr><th>Check</th><th>Status</th><th>Details</th></tr></thead><tbody>
<?php
$grp = '';
foreach ( $checks as $ch ) {
	if ( $ch[0] !== $grp ) {
		$grp = $ch[0];
		echo '<tr><th colspan="3" style="background:#f7f8fb">' . sp_h( $grp ) . '</th></tr>';
	}
	$label = array( 'ok' => 'OK', 'warn' => 'Check', 'fail' => 'Fix', 'info' => 'Optional' )[ $ch[2] ];
	echo '<tr><td>' . sp_h( $ch[1] ) . '</td><td class="st ' . $ch[2] . '">' . $label . '</td><td>' . sp_h( $ch[3] )
	     . ( $ch[4] !== '' ? '<br><span class="help">' . sp_h( $ch[4] ) . '</span>' : '' ) . '</td></tr>';
}
?>
</tbody></table>
<form method="post" action="index.php?step=requirements"><?php echo $csrfField; ?>
<button type="submit" name="next" value="1"<?php echo empty( $S['req_ok'] ) ? ' disabled' : ''; ?>>Continue</button>
<a class="btn secondary" href="index.php?step=requirements">Check again</a>
</form>
</div>

<?php elseif ( $step === 'database' ) : $mode = spi_v( 'mode', 'existing' ); ?>
<div class="card">
<h1>Database</h1>
<p class="lead">SubjectsPlus needs a MariaDB 10.11+ or MySQL 8.0+ database of its own, with its own database user.</p>
<form method="post" action="index.php?step=database" autocomplete="off"><?php echo $csrfField; ?>
<div class="choice"><input type="radio" id="m-existing" name="mode" value="existing"<?php echo $mode !== 'create' ? ' checked' : ''; ?>>
 <label for="m-existing">I have already created an empty database and a user</label></div>
<div class="choice"><input type="radio" id="m-create" name="mode" value="create"<?php echo $mode === 'create' ? ' checked' : ''; ?>>
 <label for="m-create">Create the database and user for me</label>
 <p class="help">Uses a database administrator account (e.g. <code>root</code>) once. It is not stored.</p>
 <div id="admin-box" class="row">
  <div><?php echo spi_field( 'admin_user', 'Database administrator', spi_v( 'admin_user', 'root' ), 'text', '', 'autocomplete="off"' ); ?></div>
  <div><?php echo spi_field( 'admin_pass', 'Administrator password', '', 'password', 'MariaDB on Linux often signs root in by socket only: create an admin user with a password, or use option 1.', 'autocomplete="new-password"' ); ?></div>
 </div>
</div>
<div class="row">
 <div><?php echo spi_field( 'db_host', 'Database host', spi_v( 'db_host', '127.0.0.1' ), 'text', 'Usually 127.0.0.1 or localhost.' ); ?></div>
 <div><?php echo spi_field( 'db_port', 'Port', spi_v( 'db_port', '3306' ), 'number', '', 'min="1" max="65535"' ); ?></div>
 <div><?php echo spi_field( 'db_name', 'Database name', spi_v( 'db_name', 'subjectsplus' ) ); ?></div>
 <div><?php echo spi_field( 'db_user', 'Database user for SubjectsPlus', spi_v( 'db_user', 'subjectsplus' ) ); ?></div>
</div>
<label for="f-db_pass">Password of that user</label>
<div class="inline"><input id="f-db_pass" name="db_pass" type="text" value="<?php echo sp_h( isset( $_POST['db_pass'] ) && is_string( $_POST['db_pass'] ) ? $_POST['db_pass'] : ( $mode === 'create' ? Database::randomPassword() : '' ) ); ?>" autocomplete="new-password">
<button type="button" class="secondary" id="gen">Generate</button></div>
<p class="help">When creating, a strong generated password is filled in; it is saved in config.php, you do not need to remember it.</p>
<?php if ( ! empty( $S['db_reuse'] ) ) : ?>
<div class="choice"><input type="checkbox" id="f-reuse" name="reuse" value="1"> <label for="f-reuse">Keep the existing data and only write a new config.php</label></div>
<?php endif; ?>
<button type="submit" name="action" value="test" class="secondary">Test connection</button>
<button type="submit" name="action" value="next">Continue</button>
</form>
</div>
<script>
(function(){var g=document.getElementById('gen'),p=document.getElementById('f-db_pass'),b=document.getElementById('admin-box');
function r(){var c='ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789-_',a=new Uint32Array(24),s='';crypto.getRandomValues(a);a.forEach(function(x){s+=c[x%c.length]});return s}
g.addEventListener('click',function(){p.value=r()});
function t(){var c=document.getElementById('m-create').checked;b.style.display=c?'grid':'none';if(c&&!p.value)p.value=r()}
document.querySelectorAll('input[name=mode]').forEach(function(e){e.addEventListener('change',t)});t();})();
</script>

<?php elseif ( $step === 'site' ) : ?>
<div class="card">
<h1>Site &amp; administrator</h1>
<form method="post" action="index.php?step=site" autocomplete="off"><?php echo $csrfField; ?>
<?php echo spi_field( 'base_url', 'Site address', spi_v( 'base_url', Environment::detectBaseUrl() ), 'url',
	'Detected from this request' . ( Environment::fromTrustedProxy() && ! empty( $_SERVER['HTTP_X_FORWARDED_HOST'] ) ? ' (through your proxy)' : '' ) . '. The folder that contains <code>control/</code> and <code>subjects/</code>, ending with <code>/</code>. Change it if visitors use another address.', 'required' ); ?>
<div class="choice"><input type="checkbox" id="f-https" name="force_https" value="1"<?php echo ( spi_v( 'force_https', Environment::isHttps() ? '1' : '' ) ) ? ' checked' : ''; ?>>
 <label for="f-https">Always use HTTPS</label><p class="help">Redirects every http:// request to https:// and marks cookies secure. Tick it only when HTTPS already works.</p></div>
<div class="row">
 <div><?php echo spi_field( 'site_name', 'Name of the site', spi_v( 'site_name', 'Research Guides' ) ); ?></div>
 <div><?php echo spi_field( 'institution', 'Library / institution', spi_v( 'institution', 'University Library' ) ); ?></div>
</div>
<h2>Administrator account</h2>
<div class="row">
 <div><?php echo spi_field( 'fname', 'First name', spi_v( 'fname' ), 'text', '', 'required' ); ?></div>
 <div><?php echo spi_field( 'lname', 'Last name', spi_v( 'lname' ), 'text', '', 'required' ); ?></div>
</div>
<?php echo spi_field( 'email', 'Email (sign-in name)', spi_v( 'email' ), 'email', '', 'required' ); ?>
<div class="row">
 <div><?php echo spi_field( 'password', 'Password', '', 'password', 'At least 12 characters. A passphrase of four words works well.', 'required minlength="12" autocomplete="new-password"' ); ?><div class="meter"><i id="pw-meter"></i></div></div>
 <div><?php echo spi_field( 'password2', 'Repeat password', '', 'password', '', 'required minlength="12" autocomplete="new-password"' ); ?></div>
</div>
<button type="submit" name="next" value="1">Continue</button>
</form>
</div>
<script>
(function(){var p=document.getElementById('f-password'),m=document.getElementById('pw-meter');p.addEventListener('input',function(){var v=p.value,s=0;
if(v.length>=12)s++;if(v.length>=16)s++;if(/[a-z]/.test(v)&&/[A-Z]/.test(v))s++;if(/\d/.test(v))s++;if(/[^A-Za-z0-9]/.test(v))s++;
m.style.width=(s*20)+'%';m.style.background=s<3?'#b3261e':(s<4?'#9a6200':'#1f7a3a');});})();
</script>

<?php elseif ( $step === 'options' ) : $o = $S['form'] ?? array(); ?>
<div class="card">
<h1>Options</h1>
<p class="lead">All optional; everything can be changed later in the admin area.</p>
<form method="post" action="index.php?step=options" autocomplete="off"><?php echo $csrfField; ?>
<h2>Email (for workflow reminders and TalkBack)</h2>
<div class="row">
 <div><?php echo spi_field( 'smtp_host', 'Mail server (SMTP)', spi_v( 'smtp_host' ), 'text', 'e.g. smtp.office365.com, smtp.gmail.com — leave empty to set up later.' ); ?></div>
 <div><?php echo spi_field( 'smtp_port', 'Port', spi_v( 'smtp_port', '587' ), 'number', '587 (STARTTLS) or 465 (TLS).' ); ?></div>
 <div><?php echo spi_field( 'smtp_user', 'User name', spi_v( 'smtp_user' ), 'text', 'Leave empty if the server needs no sign-in.' ); ?></div>
 <div><?php echo spi_field( 'smtp_pass', 'Password', '', 'password', ! empty( $S['options']['smtp_pass'] ) ? 'Saved; leave empty to keep it.' : '', 'autocomplete="new-password"' ); ?></div>
</div>
<button type="submit" name="action" value="testmail" class="secondary">Send a test message to <?php echo sp_h( $S['site']['email'] ?? '' ); ?></button>

<h2>Off-campus access</h2>
<?php $pk = spi_v( 'proxy_kind', 'none' ); ?>
<label for="f-proxy_kind">Service</label>
<select id="f-proxy_kind" name="proxy_kind">
<?php foreach ( array( 'none' => 'None / set up later', 'ezproxy' => 'EZproxy', 'openathens' => 'OpenAthens', 'custom' => 'Other proxy (link template)' ) as $k => $l ) {
	echo '<option value="' . $k . '"' . ( $pk === $k ? ' selected' : '' ) . '>' . sp_h( $l ) . '</option>';
} ?>
</select>
<?php echo spi_field( 'proxy_template', 'Link template', spi_v( 'proxy_template' ), 'text',
	'<code>{url}</code> = database address, <code>{url_enc}</code> = encoded. EZproxy: <code>https://ezproxy.library.edu/login?url={url}</code> · OpenAthens: <code>https://go.openathens.net/redirector/library.edu?url={url_enc}</code>' ); ?>

<h2>Sample content</h2>
<div class="choice"><input type="checkbox" id="f-sample" name="sample" value="1"<?php echo ( ! isset( $o['sample'] ) || $o['sample'] ) ? ' checked' : ''; ?>>
 <label for="f-sample">Add sample content</label>
 <p class="help">A public guide “Getting Started with Research”, a hidden course-guide template, three open-access databases on the A–Z list and an FAQ. Edit or delete them later.</p></div>
<button type="submit" name="action" value="next">Continue</button>
</form>
</div>
<script>
(function(){var k=document.getElementById('f-proxy_kind'),t=document.getElementById('f-proxy_template');
var d={ezproxy:'https://ezproxy.library.edu/login?url={url}',openathens:'https://go.openathens.net/redirector/library.edu?url={url_enc}',custom:'https://'};
k.addEventListener('change',function(){if(d[k.value]&&(!t.value||/library\.edu|^https:\/\/$/.test(t.value)))t.value=d[k.value];t.parentNode&&(t.disabled=k.value==='none')});t.disabled=k.value==='none';})();
</script>

<?php elseif ( $step === 'install' ) : $d = $S['db']; $s = $S['site']; $o = $S['options'] ?? array(); ?>
<div class="card">
<h1>Ready to install</h1>
<table>
<tr><th>Site address</th><td><?php echo sp_h( $s['base_url'] ) . ( $s['force_https'] ? ' (always HTTPS)' : '' ); ?></td></tr>
<tr><th>Site name</th><td><?php echo sp_h( $s['site_name'] . ( $s['institution'] !== '' ? ' — ' . $s['institution'] : '' ) ); ?></td></tr>
<tr><th>Database</th><td><?php echo sp_h( $d['user'] . '@' . $d['host'] . ':' . $d['port'] . ' / ' . $d['name'] ) . ( ! empty( $d['reuse'] ) ? ' — <strong>existing data kept</strong>' : '' ); ?></td></tr>
<tr><th>Administrator</th><td><?php echo sp_h( $s['fname'] . ' ' . $s['lname'] . ' <' . $s['email'] . '>' ); ?></td></tr>
<tr><th>Email</th><td><?php echo sp_h( ( $o['smtp_host'] ?? '' ) !== '' ? $o['smtp_host'] . ':' . $o['smtp_port'] : 'set up later' ); ?></td></tr>
<tr><th>Off-campus access</th><td><?php echo sp_h( ( $o['proxy_kind'] ?? 'none' ) !== 'none' ? $o['proxy_template'] : 'set up later' ); ?></td></tr>
<tr><th>Sample content</th><td><?php echo ! empty( $o['sample'] ) ? 'yes' : 'no'; ?></td></tr>
<tr><th>config.php</th><td><?php echo is_writable( Environment::root() . '/control/includes' ) ? 'will be saved in control/includes/' : 'control/includes/ is read-only: you will download config.php and upload it'; ?></td></tr>
</table>
<form method="post" action="index.php?step=install"><?php echo $csrfField; ?>
<a class="btn secondary" href="index.php?step=options">Back</a>
<button type="submit" name="install" value="1">Install SubjectsPlus</button>
</form>
</div>

<?php elseif ( $step === 'config' ) : ?>
<div class="card">
<h1>Upload config.php</h1>
<p class="lead">The database is ready. Because <code>control/includes/</code> is read-only (good), save the configuration file yourself:</p>
<ol>
<li><a class="btn" style="margin:0" href="index.php?step=config&amp;download=1&amp;t=<?php echo sp_h( $S['csrf'] ); ?>">Download config.php</a></li>
<li>Copy it to <code>control/includes/config.php</code> on the server, e.g.<br><code>scp config.php server:<?php echo sp_h( Environment::root() ); ?>/control/includes/</code></li>
<li>Make it readable by the web server only: <code>chown root:www-data config.php &amp;&amp; chmod 640 config.php</code></li>
</ol>
<p class="help">The file contains the database password and secret keys: do not email it or keep copies in shared folders.</p>
<form method="post" action="index.php?step=config"><?php echo $csrfField; ?>
<button type="submit" name="check" value="1">I have uploaded it — continue</button>
</form>
</div>

<?php elseif ( $step === 'done' ) : $r = $S['result'] ?? array(); ?>
<div class="card">
<h1>SubjectsPlus is installed</h1>
<div class="msg ok">Sign in with <strong><?php echo sp_h( $r['email'] ?? '' ); ?></strong> and the password you chose.</div>
<ul><?php foreach ( (array) ( $r['log'] ?? array() ) as $l ) { echo '<li>' . sp_h( $l ) . '</li>'; } ?></ul>
<a class="btn" href="<?php echo sp_h( ( $r['base_url'] ?? '../../' ) . 'control/login.php' ); ?>">Sign in</a>
<a class="btn secondary" href="<?php echo sp_h( ( $r['base_url'] ?? '../../' ) . 'subjects/' ); ?>">View the public site</a>
<h2>Next steps</h2>
<ol>
<li>Remove the installer: <code>rm -r control/installer</code> (it no longer runs, but less code is less risk).</li>
<?php if ( is_file( Environment::codePath() ) ) : ?><li><strong>Delete the install code</strong> (the folder was read-only, so the installer could not): <code>rm control/includes/install-code.php</code></li><?php endif; ?>
<li>Make <code>control/includes/</code> read-only: <code>chown -R root:root control/includes &amp;&amp; chown root:www-data control/includes/config.php &amp;&amp; chmod 640 control/includes/config.php</code></li>
<li>Add the daily task: <code>15 6 * * * www-data php <?php echo sp_h( Environment::root() ); ?>/tools/workflow-daily.php</code></li>
<li>In the admin area: add staff (<em>Admin → Users</em>), off-campus access domains, and single sign-on if you use it (INSTALL.md §9).</li>
</ol>
</div>
<?php
	// the run is over: forget everything except that it finished (for a reload of this page)
	$S = array( 'finished' => true, 'result' => $r, 'csrf' => $S['csrf'] );
endif; ?>
</div>
</body>
</html>
