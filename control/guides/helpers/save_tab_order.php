<?php
/**
 * Created by PhpStorm.
 * User: cbrownroberts
 * Date: 11/10/15
 * Time: 1:25 PM
 */

require_once("../../includes/autoloader.php");
require_once("../../includes/config.php");
require_once( __DIR__ . "/../../includes/staff_only.php" ); // staff only

use SubjectsPlus\Control\Querier;
use SubjectsPlus\Control\Guide\TabData;


$subsubcat = "";
$subcat = "guides";
$page_title = "Save Tab Order";
$header = "noshow";

include("../../includes/header.php");

// every tab being re-ordered must belong to a guide this person may edit
parse_str( (string) ( $_POST['data'] ?? '' ), $sp_order );
$sp_guides = array();
foreach ( (array) ( $sp_order['item'] ?? array() ) as $sp_tab ) {
	$sp_guides = array_merge( $sp_guides, \SubjectsPlus\Control\Guide\GuideAccess::guidesForTab( (int) $sp_tab ) );
}
\SubjectsPlus\Control\Guide\GuideAccess::requireFound( array_unique( $sp_guides ), true );

$db = new Querier;

$tabs = new TabData($db);
$tabs->saveTabOrder($_POST);
