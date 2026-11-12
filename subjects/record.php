<?php
/**
 *   @file record.php
 *   @brief Redirects to URL provided in the record
 *
 *   @author rxd702
 *   @date jun 2021
 */

use SubjectsPlus\Control\Querier;
use SubjectsPlus\Control\CompleteMe;
use SubjectsPlus\Control\DbHandler;    

include("../control/includes/config.php");
include("../control/includes/functions.php");
include("../control/includes/autoloader.php");

$this_fname = "record.php";
$that_fname = theme_file($this_fname, $subjects_theme);
if ( $this_fname != $that_fname ) { include($that_fname); exit; }

if (isset($_GET["id"])) {
  // scrub the id
  $_GET["id"] = scrubData($_GET["id"], "integer");

  // Retrieve the title, location, and access restrictions of the id
  $query = "SELECT t.title, l.location, l.access_restrictions 
  FROM title AS t, location_title AS lt, location AS l 
  WHERE t.title_id = :id
  AND lt.title_id = :id
  AND l.location_id = lt.location_id";

  $db = new Querier;
  $connection = $db->getConnection();
  $statement = $connection->prepare($query);
  $statement->bindParam(":id", $_GET["id"]);
  $statement->execute();
  $result = $statement->fetch();
  
  if ($result) { // fetch() returns false (not NULL) when there is no such record
    $link = html_entity_decode( (string) $result['location'], ENT_QUOTES, 'UTF-8' ); // stored HTML-escaped (&amp;)

    // Use proxy with limited access restrictions
    if (\SubjectsPlus\Control\RecordSettings::usesProxy( $result['access_restrictions'] )) { // behaviour-based
      $link = sp_access_url( $link, $result['access_restrictions'] );
    }
    
    // Redirect to the link
    header("Location:" . $link);
  } else {
    // Redirect to 404 page
    header("Location:" . $PublicPath . "blank-404.php");
  }
} else {
  // Redirect to 404 page
  header("Location:" . $PublicPath . "blank-404.php");
}
