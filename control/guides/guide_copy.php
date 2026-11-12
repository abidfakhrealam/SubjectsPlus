<?php
/**
 * @file guide_copy.php
 * @brief This page takes a subject id and creates a copy of the that guide. It includes a form for selecting which guide to copy.
 * @author little9 (Jamie Little)
 * @date July 2015
 */
global $AssetPath;

use SubjectsPlus\Control\Querier;
use SubjectsPlus\Control\Guide\GuideList;
use SubjectsPlus\Control\Guide\CopyGuide;

include( "../includes/autoloader.php" );
include( "../includes/config.php" );

if (isset($_POST['subject_id'])) {
	require_once( __DIR__ . "/../includes/staff_only.php" ); // upstream let anyone copy guides
	if ( ( $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '' ) !== 'XMLHttpRequest' ) { // only from this page's script
		http_response_code( 400 );
		exit;
	}
	$db = new Querier;

	$subject_id = (int) $_REQUEST['subject_id'];
	$guide_base = new CopyGuide($_SESSION['staff_id'], $subject_id, $db);

	if( isset($_GET['tabs']) && $_GET['tabs'] != '' ) {
		$ids =  "{$_GET['tabs']}";
		$tab_ids = $guide_base->convertTabsToTabIds($ids);
	} else {
		$tab_ids = array();
	}

	$new_guide = $guide_base->copyGuideTransaction($_SESSION['staff_id'], $subject_id, $tab_ids);
	echo $new_guide;

	exit;
} else {
	$page_title = _( 'Copy Guide' );
	include( "../includes/header.php" );

}


$db                = new Querier;
// all guides, including hidden ones (templates are normally hidden)
$guide_list_output = $db->getConnection()->query( "SELECT subject_id, subject, active, extra FROM subject ORDER BY subject" )->fetchAll( PDO::FETCH_ASSOC );

?>
<style>
    .metadata-url {
        display: none;
    }
</style>
<div class="pure-g">
    <div class="pure-u-1 pure-u-lg-1-2">
        <div class="pluslet">
            <div class="titlebar">
                <div class="titlebar_text"><?php echo _( "New guide from a template or copy" ); ?></div>
                <div class="titlebar_options"></div>
            </div>
            <div class="pluslet_body">
                <p><?php echo _( "Pick a template (or any guide). A copy is made with all its tabs, sections and boxes; boxes that are linked in the original stay linked. You then rename it in the guide settings." ); ?></p>
                <select id="guides" class="guide-select">
					<?php
					// templates first
					$sp_tpl = $sp_rest = '';
					foreach ( $guide_list_output as $guide ) {
						$subject_id = (int) $guide["subject_id"];
						$subject    = htmlspecialchars( html_entity_decode( (string) $guide["subject"], ENT_QUOTES ), ENT_QUOTES );
						$status     = (int) $guide["active"];
						$status_text = $status === 0 ? " - Hidden" : ( $status === 2 ? " - Suppressed" : " - Published" );
						$extra      = json_decode( (string) ( $guide["extra"] ?? '' ), true );
						$opt        = "<option class='guide-option' value='{$subject_id}'>{$subject}  $status_text</option>";
						if ( is_array( $extra ) && ! empty( $extra['template'] ) ) {
							$sp_tpl .= $opt;
						} else {
							$sp_rest .= $opt;
						}
					}
					if ( $sp_tpl !== '' ) {
						echo '<optgroup label="' . htmlspecialchars( _( "Guide templates" ) ) . '">' . $sp_tpl . '</optgroup><optgroup label="' . htmlspecialchars( _( "Other guides" ) ) . '">' . $sp_rest . '</optgroup>';
					} else {
						echo $sp_rest;
					}
					?>
                </select>
                <div class="copy-output">
                    <a class="metadata-url">Click here to edit your metadata</a>
                </div>


                <button class="button pure-button pure-button-primary create-guide"> <?php echo _( 'Create the new guide' ); ?></button>

            </div>
        </div>
    </div>
</div>
<script>
    $('.create-guide').on('click', function () {

        var selected_guide = $('.guide-option:selected').val();
        $.post("guide_copy.php", {subject_id: selected_guide}, function (data) {

            console.log(data);
            $('.metadata-url').show();
            $('.metadata-url').attr('href', "metadata.php?subject_id=" + data);

            window.location.href = "<?php echo getControlURL(); ?>" + "guides/metadata.php?subject_id=" + data;

        });

    });
</script>


<link rel="stylesheet" href="<?php echo $AssetPath; ?>js/select2/select2.css" type="text/css" media="all"/>

<script type="text/javascript" src="<?php echo $AssetPath; ?>/js/select2/select2.min.js"></script>
<style>
    .select2-container {
        width: 65%;
        margin-right: 3%;
    }

    .create-guide {
        margin-top: 1.5%;
        margin-left: 0.5%;
    }
</style>

<script>
    $(document).ready(function () {

        $('#guides').select2();

    });
</script>