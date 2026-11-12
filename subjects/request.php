<?php
/**
 * "Suggest an e-resource" — public form that starts the Request & acquisition workflow.
 * The suggestion becomes a hidden record in the first "request" status and e-resource managers
 * and approvers are emailed.
 */

use SubjectsPlus\Control\RecordSettings;
use SubjectsPlus\Control\Workflow;
use SubjectsPlus\Control\Querier;

include( "../control/includes/config.php" );
include( "../control/includes/functions.php" );
include( "../control/includes/autoloader.php" );

if ( session_status() !== PHP_SESSION_ACTIVE ) {
	session_start();
}

$page_title  = sp__( "Suggest an e-resource" );
$use_jquery  = array( "colorbox" ); // the shared footer script needs jQuery + colorbox ("$ is not defined" before)
$description = sp__( "Suggest a database, e-journal or e-book collection for the library to consider." );
$h           = function ( $s ) {
	return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
};

$enabled = RecordSettings::ready();
$errors  = array();
$done    = false;
$v       = array( 'title' => '', 'url' => '', 'justification' => '', 'name' => '', 'email' => '', 'department' => '' );

if ( $enabled && $_SERVER['REQUEST_METHOD'] === 'POST' ) {
	foreach ( $v as $k => $_ ) {
		$v[ $k ] = trim( strip_tags( (string) ( $_POST[ $k ] ?? '' ) ) );
	}
	$started = (int) ( $_SESSION['sp_req_started'] ?? 0 );
	if ( empty( $_POST['csrf'] ) || empty( $_SESSION['sp_req_csrf'] ) || ! hash_equals( $_SESSION['sp_req_csrf'], (string) $_POST['csrf'] ) ) {
		$errors[] = sp__( "Your session expired. Please submit the form again." );
	} elseif ( ! empty( $_POST['website'] ) || ( $started && time() - $started < 3 ) ) {
		$errors[] = sp__( "Your request could not be accepted. Please try again." ); // honeypot / too fast: probably a bot
	}
	if ( mb_strlen( $v['title'] ) < 2 ) {
		$errors[] = sp__( "Please enter the name of the resource." );
	}
	if ( $v['url'] !== '' && ! preg_match( '#^https?://[^\s<>"]+$#i', $v['url'] ) ) {
		$errors[] = sp__( "Please enter a web address starting with http:// or https://, or leave it empty." );
	}
	if ( $v['name'] === '' ) {
		$errors[] = sp__( "Please enter your name." );
	}
	if ( ! filter_var( $v['email'], FILTER_VALIDATE_EMAIL ) ) {
		$errors[] = sp__( "Please enter a valid email address." );
	}
	if ( ! $errors ) {
		$db = new Querier;
		$st = $db->getConnection()->prepare( "SELECT COUNT(*) FROM resource_request WHERE email = ? AND created_at > NOW() - INTERVAL 1 DAY" );
		$st->execute( array( $v['email'] ) );
		if ( (int) $st->fetchColumn() >= 5 ) {
			$errors[] = sp__( "You have already sent several requests today. Please contact the library directly." );
		}
	}
	if ( ! $errors ) {
		try {
			Workflow::createRequest( array(
				'title'         => mb_substr( $v['title'], 0, 250 ),
				'url'           => $v['url'],
				'description'   => '',
				'justification' => mb_substr( $v['justification'], 0, 5000 ),
				'name'          => mb_substr( $v['name'], 0, 150 ),
				'email'         => $v['email'],
				'department'    => mb_substr( $v['department'], 0, 150 ),
			) );
			$done = true;
			unset( $_SESSION['sp_req_csrf'] );
		} catch ( \Throwable $e ) {
			error_log( 'SubjectsPlus request form: ' . $e->getMessage() );
			$errors[] = sp__( "Sorry, your request could not be saved. Please try again later." );
		}
	}
}

if ( empty( $_SESSION['sp_req_csrf'] ) ) {
	$_SESSION['sp_req_csrf'] = bin2hex( random_bytes( 32 ) );
}
$_SESSION['sp_req_started'] = time();

include( theme_file( "includes/header.php", $subjects_theme ) );
?>
<div class="pure-g">
	<div class="pure-u-1 pure-u-md-2-3">
		<div class="pluslet">
			<div class="titlebar"><div class="titlebar_text"><?php echo $h( $page_title ); ?></div></div>
			<div class="pluslet_body">
<?php if ( ! $enabled ) : ?>
				<p><?php echo sp__( "This form is not available yet." ); ?></p>
<?php elseif ( $done ) : ?>
				<p role="status"><strong><?php echo sp__( "Thank you — your suggestion has been sent to the library." ); ?></strong></p>
				<p><?php echo sp__( "A librarian will review it and may contact you by email." ); ?></p>
				<p><a href="request.php"><?php echo sp__( "Suggest another resource" ); ?></a> · <a href="databases.php"><?php echo sp__( "Back to the database list" ); ?></a></p>
<?php else : ?>
				<p><?php echo sp__( "Tell us about a database, e-journal package or e-book collection you would like the library to subscribe to." ); ?></p>
	<?php if ( $errors ) : ?>
				<div class="feedback" role="alert" style="background:#fdecea"><ul><li><?php echo implode( '</li><li>', array_map( $h, $errors ) ); ?></li></ul></div>
	<?php endif; ?>
				<form method="post" action="request.php" class="pure-form pure-form-stacked sp-request-form">
					<input type="hidden" name="csrf" value="<?php echo $h( $_SESSION['sp_req_csrf'] ); ?>">
					<div style="position:absolute;left:-10000px" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
					<fieldset>
						<legend><?php echo sp__( "The resource" ); ?></legend>
						<label for="r_title"><?php echo sp__( "Name of the resource" ); ?> *</label>
						<input id="r_title" name="title" type="text" class="pure-input-1" maxlength="250" required value="<?php echo $h( $v['title'] ); ?>">
						<label for="r_url"><?php echo sp__( "Web address (if known)" ); ?></label>
						<input id="r_url" name="url" type="url" class="pure-input-1" placeholder="https://" value="<?php echo $h( $v['url'] ); ?>">
						<label for="r_why"><?php echo sp__( "Why is it needed? (courses, research, who would use it)" ); ?></label>
						<textarea id="r_why" name="justification" rows="5" class="pure-input-1" maxlength="5000"><?php echo $h( $v['justification'] ); ?></textarea>
					</fieldset>
					<fieldset>
						<legend><?php echo sp__( "About you" ); ?></legend>
						<label for="r_name"><?php echo sp__( "Your name" ); ?> *</label>
						<input id="r_name" name="name" type="text" class="pure-input-1" maxlength="150" required autocomplete="name" value="<?php echo $h( $v['name'] ); ?>">
						<label for="r_email"><?php echo sp__( "Your email" ); ?> *</label>
						<input id="r_email" name="email" type="email" class="pure-input-1" required autocomplete="email" value="<?php echo $h( $v['email'] ); ?>">
						<label for="r_dept"><?php echo sp__( "School / department" ); ?></label>
						<input id="r_dept" name="department" type="text" class="pure-input-1" maxlength="150" value="<?php echo $h( $v['department'] ); ?>">
					</fieldset>
					<p><small><?php echo sp__( "Your name and email are used only to handle this request and are visible to library staff." ); ?></small></p>
					<button type="submit" class="pure-button pure-button-primary"><?php echo sp__( "Send suggestion" ); ?></button>
				</form>
<?php endif; ?>
			</div>
		</div>
	</div>
</div>
<?php
include( theme_file( "includes/footer.php", $subjects_theme ) );
