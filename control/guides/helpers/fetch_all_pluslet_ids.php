<?php
/**
 * Created by PhpStorm.
 * User: cbrownroberts
 * Date: 1/5/16
 * Time: 2:10 PM
 */
require_once("../../includes/autoloader.php");
require_once("../../includes/config.php");
require_once( __DIR__ . "/../../includes/staff_only.php" ); // staff only

use SubjectsPlus\Control\Querier;
use SubjectsPlus\Control\Guide\PlusletData;
header('Content-Type: application/json');

$db = new Querier;

$objPlusletData = new PlusletData($db);
$objPlusletData->fetchAllPlusletIds();

echo $objPlusletData->toJSON();