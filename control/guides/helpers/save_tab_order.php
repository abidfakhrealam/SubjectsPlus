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

$db = new Querier;

$tabs = new TabData($db);
$tabs->saveTabOrder($_POST);
