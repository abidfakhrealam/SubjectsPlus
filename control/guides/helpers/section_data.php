<?php

/**
 *   @file section_data.php
 *   @brief Create new section HTML
 *   @description
 *
 *   @author agdarby, dgonzalez
 *   @date updated April 2014
 */

use SubjectsPlus\Control\Guide;
use SubjectsPlus\Control\Querier;
use SubjectsPlus\Control\Guide\SectionData;

$subsubcat = "";
$subcat = "guides";
$page_title = "Section Functions";
$header = "noshow";

include("../../includes/header.php");

if ( ( $_POST['action'] ?? '' ) === 'delete' ) {
	\SubjectsPlus\Control\Guide\GuideAccess::requireFound( \SubjectsPlus\Control\Guide\GuideAccess::guidesForSection( (int) ( $_POST['section_id'] ?? 0 ) ) );
}
$sectionData = new SectionData($_POST);
$sectionData->processSection();