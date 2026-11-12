<!DOCTYPE html>
<html lang="<?php echo function_exists( 'sp_public_lang' ) ? sp_public_lang() : 'en'; ?>">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, user-scalable=no, shrink-to-fit=no">

    <title><?php print $page_title; ?></title>

    <meta name="description" content="<?php if (isset($description)) {print $description;} ?>">
    <meta name="author" content="SubjectsPlus">
    <meta name="keywords" content="<?php if (isset($keywords)) {print $keywords;} ?>">

    <!-- SP CSS -->
    <link rel="stylesheet" href="<?php print $AssetPath; ?>css/public/sp-lux-default.css" type="text/css">

    <!-- Google Analytics and Tag Manager -->
    <?php
    global $google_analytics_ua;
    global $google_tag_manager;
    if( (isset($google_analytics_ua)) && (( !empty($google_analytics_ua))) ) {

        echo "<div id='google-analytics-ua' style='visibility: hidden;' data-uacode='{$google_analytics_ua}'></div>";
        echo "<div id='google_tag_manager' style='visibility: hidden;' data-tag-manager='{$google_tag_manager}'></div>";

        if( file_exists('includes/google-analytics-tag-manager.php') ) {
            include_once ('google-analytics-tag-manager.php');
        }
    }
    ?>
<?php print sp_brand_head(); ?>
</head>
<body>

<!-- Vendor Scripts-->
<script src="<?php print $AssetPath; ?>jquery/jquery-3.7.1.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/4.3.1/js/bootstrap.bundle.min.js"></script>

<!-- Google Tag Manager (noscript) -->
<noscript>
    <iframe src="https://www.googletagmanager.com/ns.html?id=<?php print $google_tag_manager; ?>"
            height="0" width="0" style="display:none;visibility:hidden"></iframe>
</noscript>
<!-- End Google Tag Manager (noscript) -->

<!--SP jQuery legacy-->
<?php
// Load our jQuery libraries + some css
if (isset($use_jquery)) { print generatejQuery($use_jquery); }

// We've got a variable for those who wish to keep the old styles
$v2styles = TRUE;
?>

<div class="c-offcanvas-content-wrap">

    <!-- Load Jekyll-built site header (UM Sliver, Site Header)-->
    <?php include("themes/splux/site-header.php"); ?>
    <div class="container"><?php print sp_lang_switcher(); // English | हिन्दी ?></div>

    <!-- Main Content-->
    <div class="body-slim">
        <main>