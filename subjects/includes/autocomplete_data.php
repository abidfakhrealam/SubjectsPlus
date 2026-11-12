<?php
header("Content-Type: application/json");
header("Expires: on, 01 Jan 1970 00:00:00 GMT");
header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
header("Cache-Control: no-store, no-cache, must-revalidate");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
use SubjectsPlus\Control\Querier;
use SubjectsPlus\Control\Autocomplete;
include_once(__DIR__ . '/../../control/includes/config.php');
include_once(__DIR__ . "/../../control/includes/autoloader.php");
include_once(__DIR__ . "/../../control/includes/functions.php");

$auto_complete = new Autocomplete();
$auto_complete->setSearchPage("public");

// Public pages only search public content. Upstream passed any collection through, so a visitor could
// list hidden guides, box contents of unpublished guides, staff accounts and unpublished TalkBack
// questions (collection=home / all_guides / pluslet / talkback …).
$sp_collection = (string) ( $_GET["collection"] ?? "guide" );
if ( ! in_array( $sp_collection, array( "guide", "guides", "azrecords" ), true ) ) {
  echo "[]";
  exit;
}
$auto_complete->setCollection( $sp_collection );
$auto_complete->setParam( (string) ( $_GET["term"] ?? "" ) );

if ( $sp_collection === "guide" ) {
  // boxes of one guide, and only when that guide is public
  $sp_sid  = (int) ( $_GET["subject_id"] ?? 0 );
  $sp_pub  = ( new Querier() )->queryWithPreparedStatement( "SELECT 1 FROM subject WHERE subject_id = :id AND active = 1", NULL, array( ":id" => $sp_sid ) );
  if ( ! $sp_pub ) {
    echo "[]";
    exit;
  }
  $auto_complete->setSubjectId( $sp_sid );
}

echo $auto_complete->search();

