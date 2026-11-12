<?php
include ("../includes/autoloader.php");
include ("../includes/config.php");
require_once( __DIR__ . "/../includes/staff_only.php" ); // staff only

use SubjectsPlus\Control\Querier;
use SubjectsPlus\Control\AzRecord\AzRecord;

$db = new Querier();

$record = new AzRecord($db);
$record->getRecord((int) $_GET['id']);
header('Content-Type: application/json');
echo $record->toJSON();