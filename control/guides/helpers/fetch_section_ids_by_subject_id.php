<?php
/**
 * Created by PhpStorm.
 * User: cbrownroberts
 * Date: 1/6/16
 * Time: 8:47 AM
 */

require_once("../../includes/autoloader.php");
require_once("../../includes/config.php");
require_once( __DIR__ . "/../../includes/staff_only.php" ); // staff only

use SubjectsPlus\Control\Querier;
use SubjectsPlus\Control\Guide\SectionService;
header('Content-Type: application/json');

$db = new Querier;

$objPlusletData = new SectionService($db);
$objPlusletData->fetchSectionIdsBySubjectId($_GET['subject_id']);

echo $objPlusletData->toJSON();

// 2142263024

// 2142263021