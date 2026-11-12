<?php
/**
 * Book List box helper for public guide pages. See control/includes/booklist_router.php.
 */
include_once( __DIR__ . "/../control/includes/autoloader.php" );
include_once( __DIR__ . "/../control/includes/config.php" );
include_once( __DIR__ . "/../control/includes/functions.php" );

$sp_booklist_is_staff = false;
require __DIR__ . "/../control/includes/booklist_router.php";
