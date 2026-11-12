<?php
/**
 * @file health.php
 * @brief Admin → System Health: versions, database updates, PHP settings, folders, web-server protection,
 *        installer state, scheduled tasks, email, API and security settings in one list. Email test included.
 * @since 4.7.1
 */

use SubjectsPlus\Control\AuditLog;
use SubjectsPlus\Control\SystemHealth;

$subsubcat  = "";
$subcat     = "admin";
$page_title = "System Health";

include( "../includes/header.php" );

$csrf = sp_csrf_token();
$note = '';
if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['testmail'] ) ) {
	if ( ! sp_csrf_valid( $_POST['csrf'] ?? '' ) ) {
		$note = '<div class="feedback" role="alert">' . _( "Your session has expired. Please reload the page and try again." ) . '</div>';
	} else {
		try {
			$m = new \PHPMailer\PHPMailer\PHPMailer( true );
			$m->CharSet = 'UTF-8';
			if ( ! empty( $email_host ) ) {
				$m->isSMTP();
				$m->Host     = $email_host;
				$m->Port     = (int) ( $email_port ?: 25 );
				$m->SMTPAuth = ! empty( $email_smtp_auth );
				$m->Username = (string) ( $email_username ?? '' );
				$m->Password = (string) ( $email_password ?? '' );
				$m->Timeout  = 15;
			}
			$from = filter_var( $administrator_email ?? '', FILTER_VALIDATE_EMAIL ) ? $administrator_email : $_SESSION['email'];
			$m->setFrom( $from, (string) ( $administrator ?? 'SubjectsPlus' ) );
			$m->addAddress( $_SESSION['email'] );
			$m->Subject = 'SubjectsPlus test message';
			$m->Body    = "This message confirms that SubjectsPlus at " . ( $BaseURL ?? '' ) . " can send email.";
			$m->send();
			$note = '<div class="feedback" role="status">' . sprintf( _( "Test message sent to %s." ), sp_h( $_SESSION['email'] ) ) . '</div>';
			AuditLog::add( 'system.testmail', 'email', '', 'Test email sent' );
		} catch ( \Throwable $e ) {
			$note = '<div class="feedback" role="alert" style="background:#fdecea">' . _( "The message could not be sent:" ) . ' ' . sp_h( $e->getMessage() ) . '</div>';
		}
	}
}

$checks = SystemHealth::run();
$sum    = SystemHealth::summary( $checks );
$label  = array( 'ok' => _( "OK" ), 'warn' => _( "Check" ), 'fail' => _( "Fix" ), 'info' => _( "Info" ) );
$h      = $note . '<p class="hc-sum"><span class="hc-b hc-fail">' . sprintf( _( "%d to fix" ), $sum['fail'] ) . '</span> <span class="hc-b hc-warn">' . sprintf( _( "%d to check" ), $sum['warn'] ) . '</span> <span class="hc-b hc-ok">' . sprintf( _( "%d OK" ), $sum['ok'] ) . '</span></p>'
          . '<div style="overflow-x:auto"><table class="pure-table pure-table-horizontal hc-table"><thead><tr><th scope="col">' . _( "Check" ) . '</th><th scope="col">' . _( "Status" ) . '</th><th scope="col">' . _( "Details" ) . '</th></tr></thead><tbody>';
$grp = '';
foreach ( $checks as $c ) {
	if ( $c[0] !== $grp ) {
		$grp = $c[0];
		$h  .= '<tr><th colspan="3" scope="rowgroup" class="hc-g">' . sp_h( $grp ) . '</th></tr>';
	}
	$h .= '<tr><td>' . sp_h( $c[1] ) . '</td><td><span class="hc-b hc-' . $c[2] . '">' . $label[ $c[2] ] . '</span></td><td>' . sp_h( $c[3] )
	      . ( $c[4] !== '' ? '<br><span class="hc-fix">' . sp_h( $c[4] ) . '</span>' : '' ) . '</td></tr>';
}
$h .= '</tbody></table></div>';
$h .= '<form method="post" class="pure-form" style="margin-top:1em"><input type="hidden" name="csrf" value="' . sp_h( $csrf ) . '"><button type="submit" name="testmail" value="1" class="pure-button">'
      . sprintf( _( "Send a test email to %s" ), sp_h( $_SESSION['email'] ?? '' ) ) . '</button> <a class="pure-button" href="upgrade.php">' . _( "Upgrade / database updates" ) . '</a></form>';

print '<div class="pure-g"><div class="pure-u-1">';
makePluslet( _( "System Health" ), $h, "no_overflow" );
print '</div></div>';
?>
<style>
.hc-table td{vertical-align:top}.hc-g{background:#f3f5f9;text-align:left}.hc-fix{color:#555;font-size:.9em}
.hc-b{display:inline-block;padding:1px 9px;border-radius:10px;font-weight:600;font-size:.88em;white-space:nowrap}
.hc-ok{background:#e8f5e9;color:#1b5e20}.hc-warn{background:#fff8e1;color:#7a4f00}.hc-fail{background:#fdecea;color:#b71c1c}.hc-info{background:#e8eef7;color:#1d3c6b}
</style>
<?php
include( "../includes/footer.php" );
