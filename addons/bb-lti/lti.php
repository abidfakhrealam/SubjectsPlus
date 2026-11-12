<?php
try {
    include("../../control/includes/config.php");
    global $lti_enabled;
    $guide_path = $PublicPath;

    if (isset($lti_enabled) && $lti_enabled) {
        if (required_indexes_exist()) {
            include("../../control/includes/autoloader.php"); // need to use this if header not loaded yet
            include_once('../../control/includes/functions.php');
            require_once '../../lib/ims-blti/blti.php';
            require_once('../../control/lti_controller/LTICourseController.php');

            global $lti_blackboard_consumer_key;

            $request_consumer_key = scrubData($_REQUEST["oauth_consumer_key"]);

            global $lti_secret;
            $lti = null;
            // the OAuth signature must verify: upstream created the BLTI object but never looked at
            // $lti->valid, so anyone who knew the (non-secret) consumer key was accepted.
            if ((string) $lti_blackboard_consumer_key !== '' && (string) $lti_secret !== ''
                && hash_equals((string) $lti_blackboard_consumer_key, (string) ($_REQUEST["oauth_consumer_key"] ?? ''))) {
                $lti = new BLTI($lti_secret, false, false);
            }

            if ($lti !== null && $lti->valid) {


                $course_label = scrubData($_REQUEST["context_label"]);

                $courses_code = new LTICourseController('bb_course_code', 'bb_course_instructor');

                $courses_code->processCourseCode($course_label, $guide_path);

            } else {
                header("Location: " . $guide_path . "?invalid_lti_call=1"); /* Redirect browser */
            }
        }

    } else {
        header("Location: " . $guide_path . "?no_lti_enabled=1"); /* Redirect browser */
    }
} catch (Exception $e) {
    error_log('SubjectsPlus LTI error: ' . $e->getMessage());
    http_response_code(500);
    echo 'The library guide could not be opened.';
    exit();
}

function required_indexes_exist()
{
    if (isset($_REQUEST["lti_message_type"]) and isset($_REQUEST["lti_version"]) and isset($_REQUEST["resource_link_id"])) {
        return (true);
    }

    return false;
}

