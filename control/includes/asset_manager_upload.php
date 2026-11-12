<?php
/**
 * Guide editor asset manager (dropzone in flyouts/asset_manager.php): upload an image into the
 * signed-in staff member's assets/users/_<name>/ folder.
 *
 * 4.6.0 hardening: upstream accepted uploads from anyone (no sign-in check), put the posted
 * staff_id straight into SQL (SQL injection) and wrote into any staff member's folder. Now the
 * caller must be signed in, the folder is always the caller's own, and only images are accepted.
 */
require_once __DIR__ . "/autoloader.php";
require_once __DIR__ . "/config.php";
require_once __DIR__ . "/functions.php";
require_once __DIR__ . "/staff_only.php";
require_once __DIR__ . "/upload/class.upload.php";

use SubjectsPlus\Control\Querier;

if ( empty( $_FILES['file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['file']['tmp_name'] ) ) {
	sp_deny( 400, 'No file was uploaded.' );
}
if ( @getimagesize( $_FILES['file']['tmp_name'] ) === false ) {
	sp_deny( 415, 'Only image files can be uploaded here.' );
}

$db      = new Querier;
$staffer = $db->queryWithPreparedStatement( "SELECT email FROM staff WHERE staff_id = :id", NULL, array( ':id' => (int) $_SESSION['staff_id'] ) );
if ( ! $staffer ) {
	sp_deny( 403, 'Unknown staff member.' );
}
$truncated_email = explode( "@", (string) $staffer[0]["email"] );
$dir_dest        = dirname( __DIR__, 2 ) . "/assets/users/_" . sp_safe_filename( $truncated_email[0] );

$handle = new upload( $_FILES['file'] );

if ( $handle->uploaded ) {
	$handle->allowed        = array( 'image/*' );
	$handle->image_resize   = false;
	$handle->image_ratio_y  = false;
	$handle->image_convert  = 'jpg';
	$handle->file_overwrite = true;
	$handle->dir_auto_chmod = true;
	$handle->Process( $dir_dest );
	if ( ! $handle->processed ) {
		http_response_code( 422 );
		echo sp_h( $handle->error );
	}
	$handle->Clean();
} else {
	http_response_code( 500 );
}
