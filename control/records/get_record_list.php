<?php
include ("../includes/autoloader.php");
include ("../includes/config.php");
require_once( __DIR__ . "/../includes/staff_only.php" ); // staff only

use SubjectsPlus\Control\Querier;
use SubjectsPlus\Control\AzRecord\AzRecordList;

$db = new Querier();

$record_list = new AzRecordList($db);

header('Content-Type: application/json');
echo $record_list->toJSON();