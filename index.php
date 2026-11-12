<?php
/**
 * Site root: before installation, start the installer wizard; afterwards, go to the public pages.
 * (Set $guide_index_page in Admin → Config to use a guide as the home page; that is handled in subjects/.)
 */
$sp_config = __DIR__ . '/control/includes/config.php';
if ( ! is_file( $sp_config ) || filesize( $sp_config ) < 10 ) {
	header( 'Location: control/installer/', true, 302 );
} else {
	header( 'Location: subjects/', true, 302 );
}
exit;
