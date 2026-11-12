<?php
namespace SubjectsPlus\Control;

use SubjectsPlus\Control\Install\Environment;
use SubjectsPlus\Control\Install\Requirements;

/**
 * Admin → System Health: everything an administrator should check after installing or upgrading, in one list.
 * Each check: [group, name, state ok|warn|fail|info, detail, how to fix].
 */
class SystemHealth {

	/** PHP branches that still get security fixes (https://www.php.net/supported-versions.php) */
	const PHP_SECURITY_UNTIL = array( '8.1' => '2025-12-31', '8.2' => '2026-12-31', '8.3' => '2027-12-31', '8.4' => '2028-12-31', '8.5' => '2029-12-31' );

	public static function run() {
		$c    = array();
		$root = Environment::root();
		$conn = Schema::conn();

		// ---------------------------------------------------------------- versions
		$c[] = array( 'Versions', 'SubjectsPlus', 'info', (string) ( $GLOBALS['sp_version'] ?? '?' ), '' );
		$br  = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
		$eol = self::PHP_SECURITY_UNTIL[ $br ] ?? null;
		$c[] = array( 'Versions', 'PHP', $eol === null ? 'warn' : ( strtotime( $eol ) < time() ? 'warn' : 'ok' ), PHP_VERSION . ( $eol ? ' (security fixes until ' . $eol . ')' : '' ),
			$eol !== null && strtotime( $eol ) >= time() ? '' : 'This PHP version no longer gets security fixes: move to PHP 8.3 or 8.4.' );
		$db  = (string) $conn->query( 'SELECT VERSION()' )->fetchColumn();
		$c[] = array( 'Versions', 'Database server', 'info', $db, '' );
		$mode = (string) $conn->query( 'SELECT @@SESSION.sql_mode' )->fetchColumn();
		$c[]  = array( 'Versions', 'SQL mode (this connection)', 'info', $mode !== '' ? $mode : '(none)', '' );

		// ---------------------------------------------------------------- database
		require_once $root . '/tools/migrations.php';
		$pending = 0;
		try {
			$pending = sp_migrate_pending_count( $conn );
		} catch ( \Throwable $e ) {
		}
		$c[] = array( 'Database', 'Database updates', $pending ? 'fail' : 'ok', $pending ? $pending . ' not applied' : 'all applied',
			$pending ? 'Admin → Upgrade (or php tools/migrate.php).' : '' );
		$size = $conn->query( "SELECT ROUND(SUM(data_length + index_length) / 1048576, 1) FROM information_schema.tables WHERE table_schema = DATABASE()" )->fetchColumn();
		$c[]  = array( 'Database', 'Size', 'info', $size . ' MB', '' );
		foreach ( array( 'stats' => 'usage statistics rows', 'audit_log' => 'audit log entries', 'login_attempt' => 'sign-in attempts' ) as $t => $l ) {
			if ( Schema::hasTable( $t ) ) {
				$n   = (int) $conn->query( "SELECT COUNT(*) FROM `$t`" )->fetchColumn();
				$c[] = array( 'Database', ucfirst( $l ), 'info', number_format( $n ), '' );
			}
		}
		$tz = $conn->query( "SELECT TIMESTAMPDIFF(MINUTE, UTC_TIMESTAMP(), NOW())" )->fetchColumn();
		$pz = (int) round( ( new \DateTime() )->getOffset() / 60 );
		$c[] = array( 'Database', 'Time zone matches PHP', abs( (int) $tz - $pz ) <= 1 ? 'ok' : 'warn', 'database UTC' . sprintf( '%+d', (int) $tz / 60 ) . ', PHP ' . date_default_timezone_get(),
			abs( (int) $tz - $pz ) <= 1 ? '' : 'Set date.timezone in php.ini to the database server\'s time zone (or the other way round): dates in reports and reminders shift otherwise.' );

		// ---------------------------------------------------------------- PHP / folders / web server (same checks as the installer)
		foreach ( Requirements::run( (string) ( $GLOBALS['BaseURL'] ?? '' ) ) as $r ) {
			if ( $r[1] === 'control/includes writable' ) {
				$r = array( 'Folders', 'control/includes read-only', $r[2] === 'warn' ? 'warn' : 'ok',
					$r[2] === 'warn' ? 'The web server can change config.php.' : 'yes', $r[2] === 'warn' ? 'chown -R root:root control/includes; chown root:www-data control/includes/config.php; chmod 640 control/includes/config.php' : '' );
			}
			if ( $r[1] === 'HTTPS' ) {
				$https = stripos( (string) ( $GLOBALS['BaseURL'] ?? '' ), 'https://' ) === 0;
				$r     = array( 'Web server', 'Site address uses HTTPS', $https ? 'ok' : 'warn', (string) ( $GLOBALS['BaseURL'] ?? '' ),
					$https ? ( empty( $GLOBALS['sp_force_https'] ) ? 'Optional: set $sp_force_https = TRUE so plain-HTTP visits are redirected.' : '' ) : 'Use HTTPS on a live site and update the site address in Admin → Config Site.' );
			}
			$c[] = $r;
		}
		$free = @disk_free_space( $root );
		if ( $free !== false ) {
			$c[] = array( 'Folders', 'Free disk space', $free < 500 * 1048576 ? 'warn' : 'ok', round( $free / 1073741824, 1 ) . ' GB', $free < 500 * 1048576 ? 'Less than 500 MB free.' : '' );
		}

		// ---------------------------------------------------------------- installation
		$left = array();
		foreach ( array( 'control/setup-config.php', 'control/install.php', 'control/includes/install-code.php' ) as $f ) {
			if ( file_exists( $root . '/' . $f ) ) {
				$left[] = $f;
			}
		}
		$c[] = array( 'Installation', 'Installer', Environment::isInstalled() ? 'ok' : 'fail', Environment::isInstalled() ? 'switched off (installation complete)' : 'not locked', '' );
		$c[] = array( 'Installation', 'Leftover files', $left ? 'warn' : 'ok', $left ? implode( ', ', $left ) : 'none', $left ? 'rm ' . implode( ' ', $left ) : '' );
		$c[] = array( 'Installation', 'Debug output', ! empty( $GLOBALS['sp_debug'] ) ? 'warn' : 'ok', ! empty( $GLOBALS['sp_debug'] ) ? '$sp_debug is on' : 'off',
			! empty( $GLOBALS['sp_debug'] ) ? 'Set $sp_debug = FALSE in config.php on a live site.' : '' );
		$secret = (string) ( $GLOBALS['salt'] ?? '' );
		$shipped = $secret === 'a5h&vv5$#45'; // the value in config-default.php
		$c[] = array( 'Installation', 'Secret salt', $shipped ? 'fail' : ( strlen( $secret ) >= 16 ? 'ok' : 'warn' ), $shipped ? 'still the published default' : ( strlen( $secret ) >= 16 ? 'set' : 'short or missing' ),
			strlen( $secret ) >= 16 && ! $shipped ? '' : 'Set a long random $salt in config.php (or the SP_SALT environment variable); staff sign in again afterwards.' );

		// ---------------------------------------------------------------- scheduled tasks
		$last = SystemState::updatedAt( 'cron.daily' );
		$age  = $last ? ( time() - strtotime( $last ) ) / 3600 : null;
		$c[]  = array( 'Scheduled tasks', 'Daily task (tools/workflow-daily.php)', $age === null ? 'warn' : ( $age > 36 ? 'warn' : 'ok' ),
			$last ? 'last run ' . $last : 'has not run since 4.7.1',
			$age === null || $age > 36 ? 'Add to cron: 15 6 * * * www-data php ' . $root . '/tools/workflow-daily.php' : '' );
		$lc = SystemState::get( 'linkcheck.last_run' );
		$c[] = array( 'Scheduled tasks', 'Link checker', $lc ? 'ok' : 'info', $lc ? 'last run ' . substr( (string) $lc['at'], 0, 16 ) . ', ' . (int) $lc['checked'] . ' links checked' : 'not run yet', '' );

		// ---------------------------------------------------------------- email / API / security
		$mail = (string) ( $GLOBALS['email_host'] ?? '' );
		$c[]  = array( 'Email', 'Mail server', $mail !== '' ? 'ok' : 'warn', $mail !== '' ? $mail . ':' . ( $GLOBALS['email_port'] ?? '' ) : 'not set',
			$mail !== '' ? '' : 'Set it in Admin → Config Site: TalkBack, workflow and review reminders cannot send email.' );
		$adm = (string) ( $GLOBALS['administrator_email'] ?? '' );
		$c[] = array( 'Email', 'Administrator email', filter_var( $adm, FILTER_VALIDATE_EMAIL ) ? 'ok' : 'warn', $adm !== '' ? $adm : 'not set', filter_var( $adm, FILTER_VALIDATE_EMAIL ) ? '' : 'Set $administrator_email in Admin → Config Site.' );
		$api = ! empty( $GLOBALS['api_enabled'] );
		$c[] = array( 'API', 'REST API', 'info', $api ? 'on' : 'off', '' );
		if ( $api ) {
			$legacy = (string) ( $GLOBALS['api_key'] ?? '' ) !== '';
			$c[]    = array( 'API', 'Keys', 'info', count( \SubjectsPlus\API\ApiClients::all() ) . ' client key(s)' . ( $legacy ? ' + site key' : '' ), $legacy ? 'Prefer one key per application (Admin → API Clients) so a leaked key can be revoked alone.' : '' );
		}
		$csp = (string) ( $GLOBALS['sp_csp'] ?? 'baseline' );
		$c[] = array( 'Security', 'Content-Security-Policy', in_array( $csp, array( 'strict', 'report-only' ), true ) ? 'ok' : 'info', $csp,
			$csp === 'baseline' ? 'Try $sp_csp = "report-only", check the browser console on public and staff pages, then "strict".' : '' );
		$c[] = array( 'Security', 'Session cookie', ini_get( 'session.cookie_httponly' ) ? 'ok' : 'warn', 'HttpOnly ' . ( ini_get( 'session.cookie_httponly' ) ? 'on' : 'off' ) . ', Secure ' . ( ini_get( 'session.cookie_secure' ) ? 'on' : 'off' ) . ', SameSite ' . ( ini_get( 'session.cookie_samesite' ) ?: '-' ), '' );
		$admins = (int) $conn->query( "SELECT COUNT(*) FROM staff WHERE active = 1 AND ptags LIKE '%admin%'" )->fetchColumn();
		$c[]    = array( 'Security', 'Active administrators', $admins > 5 ? 'warn' : 'ok', (string) $admins, $admins > 5 ? 'Give the admin permission to as few people as possible.' : '' );

		return $c;
	}

	public static function summary( array $checks ) {
		$s = array( 'ok' => 0, 'warn' => 0, 'fail' => 0, 'info' => 0 );
		foreach ( $checks as $c ) {
			$s[ $c[2] ]++;
		}

		return $s;
	}
}
