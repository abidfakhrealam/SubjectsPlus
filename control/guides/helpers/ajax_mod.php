<?php

/**
 *   @file ajax_mod.php
 *   @brief Handles jquery-ish moving and delete of information in manage_items.php.  (Called by that file)
 *
 *   @author adarby
 *   @date Sep 17, 2009
 */
$subsubcat = "";
$subcat = "guides";
$page_title = "Ajax mod include";
$header = "noshow";

use SubjectsPlus\Control\Querier;

include("../../includes/header.php");

//print_r($_REQUEST);

// list items (`rank`) belong to a guide: only its editors may change them (4.7.1)
use SubjectsPlus\Control\Guide\GuideAccess;
if ( ( $_POST['action'] ?? '' ) === 'delete_rank' ) {
  GuideAccess::requireFound( GuideAccess::guidesForRank( (int) ( $_POST['delete_id'] ?? 0 ) ) );
} elseif ( ( $_POST['action'] ?? '' ) === 'update_rank' ) {
  $sp_guides = array();
  foreach ( explode( '&', (string) ( $_POST['our_data'] ?? '' ) ) as $sp_pair ) {
    $sp_parts = explode( '=', $sp_pair );
    if ( isset( $sp_parts[1] ) ) {
      $sp_guides = array_merge( $sp_guides, GuideAccess::guidesForRank( (int) $sp_parts[1] ) );
    }
  }
  GuideAccess::requireFound( array_unique( $sp_guides ) );
}

switch ($_POST["action"] ?? '') {
  case "delete_rank":
    $q = "DELETE FROM `rank` WHERE rank_id = " . sp_int($_POST["delete_id"] ?? 0); // was pasted into SQL

    //print $q;
    $r = $db->exec($q);

    if ($r !== FALSE ) {
      print _("This item has been removed from your guide");
    } else {
      print _("There was a problem with the delete");
    }

    return;
    break;

  case "update_rank":

    $error = "";
    $our_data = explode("&", (string) $_POST["our_data"]);
    $this_sub_id = $_POST["subject_id"];


    // loop through our items
    // order them up from 0
    $count = 0;
    $source_id = "";
    $prev_source = "";

    foreach ($our_data as $value) {

      // check if we're still within the same subcat
      $ourvals = explode("=", (string) $value);

      $source_id = $ourvals[0];
      $rank_id = $ourvals[1];

      // If the source_id is new, reset the counter
      if ($source_id != $prev_source) {
        $count = 0;
      }

      $q = "UPDATE `rank` SET `rank` = " . (int) $count . " WHERE `rank`.rank_id = " . sp_int($rank_id ?? 0);

      $r = $db->exec($q);

      if ($r === FALSE) {
        $error .= $q . "<br />";
      }

      $count++;
      $prev_source = $source_id;
    }


    if ($r !== FALSE && $error == "") {
      print _("Thy will be done.  Ranks updated.");
    } else {
      print _("There was a problem with your update.");
      print $error;
    }
    break;
}
?>