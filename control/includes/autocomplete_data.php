<?php
header("Content-Type: application/json");
header("Expires: on, 01 Jan 1970 00:00:00 GMT");
header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
header("Cache-Control: no-store, no-cache, must-revalidate");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
include(__DIR__ . '/autoloader.php');
include(__DIR__ . '/config.php');
include_once(__DIR__ . '/functions.php');
// staff search: returns hidden guides, box contents and staff details, so sign-in is required
// (upstream answered anyone).
require_once(__DIR__ . '/staff_only.php');
use SubjectsPlus\Control\Autocomplete;
use SubjectsPlus\Control\Search;

$auto_complete = new Autocomplete();

$auto_complete->setParam((string) ($_GET["term"] ?? ""));
$auto_complete->setCollection((string) ($_GET["collection"] ?? ""));
$auto_complete->setSearchPage("control");

if (isset($_GET["subject_id"])) {

    $auto_complete->setSubjectId((int) $_GET["subject_id"]);

}


if (isset($_GET["staff_id"])) {

    $auto_complete->setStaffId((int) $_GET["staff_id"]);

}



echo $auto_complete->search();