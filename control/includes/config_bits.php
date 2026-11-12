<?php

/**
 *   @file config_bits.php
 *   @brief updating some config info
 *
 *   @author adarby
 *   @date
 *   @todo scrub post vars
 */

    
    
$subsubcat = "";
$subcat = "";
$page_title = "Record Bits include";
$header = "noshow";


include("../includes/header.php");

// Connect to database


//print_r($_POST);

switch ($_POST["type"]) {
    case "set_css":
    // update the extra field or set a cookie or both
        $sp_css = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($_POST["css_file"] ?? '')); // theme file name only
        $extra_stuff = json_encode(array("css" => $sp_css));
        $q = "UPDATE staff SET extra = " . $db->quote($extra_stuff) . " WHERE staff_id = " . (int) $_SESSION["staff_id"];
        $r = $db->query($q);
        $_SESSION['css'] = $sp_css;
        print "<div class=\"feedback\" style=\"display: block;\">Background Updated</div>";
        break;
}
