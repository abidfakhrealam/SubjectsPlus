<?php

/**
 *   @file save_guide.php
 *   @brief Save the layout of the guide columns.
 *   @description Basically, just updates the extra column for the guide
 *
 *   @author agdarby, dgonzalez
 *   @date updated dec 2012
 */

use SubjectsPlus\Control\Guide;

$subsubcat = "";
$subcat = "guides";
$page_title = "Save Layout include";
$header = "noshow";

include("../../includes/header.php");

\SubjectsPlus\Control\Guide\GuideAccess::requireGuide( (int) ( $_POST['subject_id'] ?? 0 ) );
$oSaveLayout = new Guide((int) ($_POST["subject_id"] ?? 0));


?>