<?php
/**
 *   @file admin/profile.php
 *   @brief Update staff information for individual
 *   @description
 *
 *   @author adarby
 *   @date Jan 2011
 *   @todo
 */
    
use SubjectsPlus\Control\Staff;
use SubjectsPlus\Control\Querier;
  
$page_title = "Admin::Users";

include("../includes/header.php");

// init
$feedback = "";
$ok_record_id = $_SESSION["staff_id"];

//////////////////
// Record View
//////////////////

if (isset($_POST["submit_record"])) {

    // Profile = the signed-in person's own record only. Upstream trusted the posted staff_id,
    // ptags, user_type_id and active fields, so any staff member could make themselves an admin
    // or rewrite another person's account (privilege escalation / IDOR). Keep those from the database.
    $sp_self = (new Querier)->queryWithPreparedStatement(
        "SELECT staff_id, email, ptags, user_type_id, active, supervisor_id FROM staff WHERE staff_id = :id", PDO::FETCH_ASSOC,
        array(':id' => (int) $_SESSION["staff_id"]));
    if (!$sp_self) {
        sp_deny(403, 'Unknown staff member.');
    }
    $_POST["staff_id"]     = (string) $sp_self[0]["staff_id"];
    $_POST["email"]        = (string) $sp_self[0]["email"];
    $_POST["ptags"]        = (string) $sp_self[0]["ptags"];
    $_POST["user_type_id"] = (string) $sp_self[0]["user_type_id"];
    $_POST["active"]       = (string) $sp_self[0]["active"];
    if (!isset($_POST["supervisor_id"])) {
        $_POST["supervisor_id"] = (string) $sp_self[0]["supervisor_id"];
    }

    $record = new Staff($_POST["staff_id"], "post", TRUE);

    //////////////////////////////////
    // Is this an insert or an update?
    //////////////////////////////////

    $record->updateRecord("brief"); // never an insert from the profile page

    // Show feedback
    $feedback = $record->getMessage();

    // See query?
    //$record->deBug();
} else {

/////////////////////
// Start the record display
////////////////////

$record = new Staff($ok_record_id, '', TRUE);

// show feedback if it isn't already set
if (!$feedback) {
    $feedback = $record->getMessage();
}

echo "<div class=\"feedback\">$feedback</div><br /><br />";
}

global $stats_encryption_enabled;
if ($stats_encryption_enabled){
	$record = new Staff($ok_record_id, '', TRUE);
}

$record->outputSelfEditForm();


//$record->deBug();

include("../includes/footer.php");
?>

<script type="text/javascript">

    var headshot_location = "<?php print $record->getHeadshotLoc(); ?>";

    $(function (){

        ////////////////
        // Check Submit
        // When the form has been submitted, check required fields
        ////////////////

        $("#new_record").submit( function () {

            // If a required field is empty, set zonk to 1, and change the bg colour
            // of the offending field
            var alerter = 0;

            $("*[class*=required_field]").each(function() {
                // get contents of string, trim off whitespace
                var our_contents = $(this).val();
                var our_contents  = jQuery.trim(our_contents );

                if (our_contents  == '') {
                    $(this).attr("style", "background-color:#FFDFDF");
                    alerter = 1;
                } else {
                    $(this).attr("style", "background-color:none");
                }

                return alerter;

            });

            // Popup warning if required fields not complete
            if (alerter == 1) {
                alert("<?php print _("You must complete all required form fields."); ?>");
                return false;
            }

        });

    	//////////////////
    	// Add red star to labels of required inputs
    	//////////////////

    	$("*[class*=required_field]").prevUntil('div', 'span').append('<span style="color: red;">*</span>');

        //////////////////
        // Make sure that delete was intentional
        //////////////////
        $(document).on('click', '.delete_button', function(event) {

            $(this).after('<div class="rec_delete_confirm"><?php print $rusure; ?>  <a id="confirm-yes-<?php print $ok_record_id; ?>"><?php print $textyes; ?></a> | <a id="confirm-no"><?php print $textno; ?></a></div>');

            return false;
        });


        $(document).on('click', 'a[id*=confirm-yes]', function(event) {
            var this_record_id = $(this).attr("id").split("-");
            var delete_url = "user.php?staff_id=" + this_record_id[2] + "&delete_record=true";
            window.location.replace(delete_url);
            return false;

        });

        $(document).on('click', 'a[id*=confirm-no]', function(event) {

            $(this).parent().remove();

            return false;
        });



    });

</script>
