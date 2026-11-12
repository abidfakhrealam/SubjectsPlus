<!DOCTYPE html>
<html lang="<?php echo function_exists( 'sp_public_lang' ) ? sp_public_lang() : 'en'; ?>">
<head>
<meta charset="utf-8">
<title><?php print $page_title; ?></title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="Description" content="<?php if (isset($description)) {print $description;} ?>" />
<meta name="Keywords" content="<?php if (isset($keywords)) {print $keywords;} ?>" />
<meta name="Author" content="" />

<link type="text/css" media="screen" rel="stylesheet" href="<?php print $AssetPath; ?>css/shared/pure-min.css">
<link type="text/css" media="screen" rel="stylesheet" href="<?php print $AssetPath; ?>css/shared/grids-responsive-min.css">
<?php 
// see if we need to override the css; you, too, can do this via the Admin > Config Site page
if (isset($css_override)  && $css_override != "") { 
    // trim off .css in case someone included it
    $css_override = explode(".css", (string) $css_override);
    $our_base_css = $css_override[0] . ".css"; 
} else {
    $our_base_css = "cleanwhite.css";
}
?>
<link type="text/css" media="screen" rel="stylesheet" href="<?php print $AssetPath; ?>css/public/<?php print $our_base_css; ?>">
<link type="text/css" media="print" rel="stylesheet" href="<?php print $AssetPath; ?>css/public/print.css">
<link type="text/css" media="screen" rel="stylesheet" href="<?php print $AssetPath; ?>css/shared/font-awesome.min.css">
<link href='https://fonts.googleapis.com/css?family=Lato:400,400italic,700,700italic,900,900italic' rel='stylesheet' type='text/css'>

<?php 
// Load our jQuery libraries + some css
if (isset($use_jquery)) { print generatejQuery($use_jquery);
}

if (!isset ($noheadersearch)) { 
    
    $search_form = '
            <div class="autoC" id="autoC">
                <form id="sp_admin_search" class="pure-form" method="post" action="' . getSubjectsURL() . 'search.php">
                <input type="text" placeholder="' . sp__( "Search" ) . '" aria-label="' . sp__( "Search" ) . '" autocomplete="off" name="searchterm" size="" id="sp_search" class="ui-autocomplete-input autoC"><span role="status" aria-live="polite" class="ui-helper-hidden-accessible"></span>
                <input type="submit" alt="' . sp__( "Search" ) . '" name="submitsearch" id="topsearch_button" class="pure-button pure-button-topsearch" value="' . sp__( "Go" ) . '">
                </form>
            </div>    ';
} else {
    $search_form = '';
}

// We've got a variable for those who wish to keep the old styles
$v2styles = TRUE;

print sp_brand_head(); // library colours, favicon (config.php $sp_brand)
?>



</head>

<body>


<div id="wrap">

<div id="header"> 
    <div id="header_inner_wrap">
        <div class="pure-g">
            <div class="pure-u-1 pure-u-md-1-5">
                <?php print sp_brand_logo( $AssetPath . "images/public/logo.png", $PublicPath ); ?>
                
            </div>
            <div class="pure-u-1 pure-u-md-4-5">
                <?php print sp_lang_switcher(); // English | हिन्दी ?>
                <?php if (isset($v2styles)) { print "<h1>$page_title</h1>"; } ?>
            </div>
        </div>
    </div>
</div> <!--end #header-->

<div class="wrapper-full">
    <div class="pure-g">
        <div class="pure-u-1">
            <?php if (!isset($v2styles)) { print "<h1>$page_title</h1>"; } ?>
            <div id="content_roof"></div> <!-- end #content_roof -->
            <div id="shadowkiller"></div> <!--end #shadowkiller-->
        
            <div id="body_inner_wrap">
