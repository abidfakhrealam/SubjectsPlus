<?php

//include subjectsplus config and functions files
include_once('../../control/includes/config.php');
include_once('../../control/includes/functions.php');

// save the ckeditor function number for callback
$funcNum = isset( $_GET['CKEditorFuncNum'] ) ? (int) $_GET['CKEditorFuncNum'] : 0; // was echoed unescaped into <script> (XSS)
// Optional: instance name (might be used to load a specific configuration file or anything else).
$CKEditor = isset( $_GET['CKEditor'] ) ? $_GET['CKEditor'] : '';
// Optional: might be used to provide localized messages.
$langCode = isset( $_GET['langCode'] ) ? $_GET['langCode'] : '';

//initialize the url (path to the uploaded file) and the message (error message log) variables
$lstrUrl = '';
$lstrMessage = '';

//define all functions for uploader

/**
 * makeValidFileName() - changes any non utf8 to ascii utf8 and removes any bad characters
 *
 * @param string $lstrFileName
 * @return string
 */
function makeValidFileName($lstrFileName)
{
	$lstrFileName = iconv('utf-8', "us-ascii//TRANSLIT", $lstrFileName);

	$lstrFileName = preg_replace('/[^a-zA-Z0-9-_\.]/', '', $lstrFileName);

	return $lstrFileName;
}

/**
 * validExtension() - determines whether the extension is a valid one
 *
 * @param string $lstrFileName
 * @return bool
 */
function validExtension($lstrFileName)
{
	global $upload_whitelist;

	$lobjTemp = explode('.', $lstrFileName);

	if(count($lobjTemp) < 2 || !in_array(strtolower(end($lobjTemp)), array_map('strtolower', (array) $upload_whitelist), true))
	{
		return false;
	}

	return true;
}

/**
 * getUserPath() - checks to see if session exists and then extracts username
 * 					and returns array with user assets folder path. On error
 * 					returns array with error message
 *
 * @return array
 */
function getUserPath()
{
	// start our session
	if ( session_status() !== PHP_SESSION_ACTIVE ) { session_start(); }

	//check to see if user is logged in (a full staff session, not just an email in the session)
	if(isset($_SESSION['email']) && checkSession() === 'ok' && !empty($_SESSION['staff_id']))
	{
		//extract user name from email; only safe file-name characters in the folder name
		$lobjTemp = explode( '@', $_SESSION['email']);
		$lstrUsername = sp_safe_filename($lobjTemp[0]);

		//check to see if the user has their assets folder
		$lstrPath = dirname(dirname(dirname(__FILE__))) . DIRECTORY_SEPARATOR . 'assets'
		. DIRECTORY_SEPARATOR . 'users' . DIRECTORY_SEPARATOR . '_' . $lstrUsername;

		if(!file_exists($lstrPath))
		{
			//folder does not exists, so lets try to create it
			if(!mkdir($lstrPath, 0755))
			{
				//return error message
				return array( false , "User assets directory does not exist and cannot be created!");
			}
		}

		//return the path of the user's assets folder
		return array ( true , $lstrPath);
	}else
	{
		//return error message
		return array( false, "Not currently logged in!");
	}

}


/**
 * getUserURL() - checks to see if session exists and then extracts username
 * 					and returnsuser assets folder url.
 *
 * @return string
 */
function getUserURL()
{
	// start our session
	if ( session_status() !== PHP_SESSION_ACTIVE ) { session_start(); }

	//check to see if user is logged in
	if(isset($_SESSION['email']))
	{
		$lstrURL = $_SERVER[ 'HTTP_HOST' ] . $_SERVER[ 'REQUEST_URI' ];

		//extract user name from email
		$lobjTemp = explode( '@', $_SESSION['email']);
		$lstrUsername = sp_safe_filename($lobjTemp[0]);

		$lobjSplit = explode( '/', $lstrURL );

		for( $i=(count($lobjSplit) - 1); $i >=0; $i-- )
		{
			if( $lobjSplit[$i] == 'ckeditor' )
			{
				unset($lobjSplit[$i]);
				$lstrURL = implode( '/' , $lobjSplit );
				break;
			}else
			{
				unset($lobjSplit[$i]);
			}
		}

		$protocol = !empty($_SERVER['HTTPS']) ? 'https://' : 'http://';

		global $enforce_https_upload_url;

		if (isset($enforce_https_upload_url)){
			if ($enforce_https_upload_url){
				$protocol = "https://";
			}
		}

        $lstrURL = $protocol . $lstrURL . '/assets/';

		//check to see if the user has their assets folder
		$lstrURL = $lstrURL . "users/_" . $lstrUsername;

		//return the url of the user's assets folder
		return $lstrURL;
	}else
	{
		//return error message
		return "Not currently logged in!";
	}

}

/**
 * moveFile() - tries to move uploaded file to desired path and returns the uploaded files url
 *
 * @param string $lstrDesiredPath
 * @return string
 */
function moveFile( $lstrDesiredPath )
{
	if ( move_uploaded_file( $_FILES['upload']['tmp_name'] , $lstrDesiredPath ) )
	{
		$lobjTemp = explode( DIRECTORY_SEPARATOR , $lstrDesiredPath );

		return getRewriteBase("ckeditor") . 'assets/users/' . $lobjTemp[count( $lobjTemp ) - 2] . '/' . $lobjTemp[count( $lobjTemp ) - 1];

	} else {

		return false;

	}
}

// uploads come only from SubjectsPlus' own editor (CSRF)
sp_require_same_origin();

//begin by converting any non utf8 character to uft8 chracter and removing any bad characters
$lstrFile = ltrim( (string) makeValidFileName($_FILES['upload']['name'] ?? ''), '.' );
$lstrExt  = strtolower( pathinfo( $lstrFile, PATHINFO_EXTENSION ) );

if ( empty( $_FILES['upload']['tmp_name'] ) || ! is_uploaded_file( $_FILES['upload']['tmp_name'] ) )
{
	$lstrMessage = _('No file was uploaded.');
}
// files that claim to be images must really be images (no HTML/script disguised as .jpg)
elseif ( in_array( $lstrExt, array( 'jpg', 'jpeg', 'png', 'gif', 'webp' ), true ) && @getimagesize( $_FILES['upload']['tmp_name'] ) === false )
{
	$lstrMessage = _('The file is not a valid image.');
}
// never accept anything a web server might execute or render as active content
elseif ( preg_match( '/\.(php\d?|phtml|phar|pht|shtml?|cgi|pl|py|sh|asp|aspx|jsp|html?|xhtml|svg|js|htaccess)(\.|$)/i', $lstrFile ) )
{
	$lstrMessage = _('The extension is not supported.');
}
//determine whether the file extension is valid
elseif(validExtension($lstrFile))
{
	//get the user's assets folder path
	$lobjPath = getUserPath();

	//if no errors (first element is boolean determining whether there is a error
	if($lobjPath[0])
	{
		//second element is user assets folder path
		$lstrPath = $lobjPath[1];

		//move uploaded file to user assets folder path and store url of uploaded to temp string
		if($lstrTemp = moveFile($lstrPath . DIRECTORY_SEPARATOR . $lstrFile))
		{
			//if no error, store as url
			$lstrUrl = getUserURL() . "/" . $lstrFile;
		}else
		{
			//create error message
			$lstrMessage = _('Could not upload file to user\\\'s assets folder.');
		}
	}else
	{
		//create error message
		$lstrMessage = _($lobjPath[1]);
	}
	//move from temp to permanent if not error return url using $BasePath from config and smile
}else
{
	//create error message
	$lstrMessage = _('The extension is not supported.');
}

//echo javascript to communicate with ckeditor
$lstrMessage = str_replace( "\\'", "'", $lstrMessage );
$jsonFlags   = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES;
echo "<script type='text/javascript'>window.parent.CKEDITOR.tools.callFunction(" . $funcNum . ", " . json_encode( $lstrUrl, $jsonFlags ) . ", " . json_encode( $lstrMessage, $jsonFlags ) . ");</script>";
?>
