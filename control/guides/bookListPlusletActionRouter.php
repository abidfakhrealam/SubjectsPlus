<?php
/**
 * Book List box helper for the guide editor (staff only). See includes/booklist_router.php.
 */
include_once( __DIR__ . "/../includes/config.php" );
include_once( __DIR__ . "/../includes/functions.php" );
require_once( __DIR__ . "/../includes/staff_only.php" );

$sp_booklist_is_staff = true;
require __DIR__ . "/../includes/booklist_router.php";
