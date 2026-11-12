<?php
namespace SubjectsPlus\API;
use SubjectsPlus\Control\Querier;
use SubjectsPlus\Control\RateLimiter;

/**
 * sp_WebService - This class handles all web services using RESTful
 *
 * @package SubjectsPlus API
 * @author dgonzalez
 * @copyright Copyright (c) 2012
 * @version $Id$
 * @date November 2012
 * @access public
 */

#[\AllowDynamicProperties] // PHP 8.2+ compatibility
class WebServiceHandler
{
	protected $mstrMethod = '';
	protected $mstrService = '';
	protected $mobjUrlParams = array();
	protected $mobjDBConnector = null;
	protected $mobjWebService = null;

	/**
	 * sp_WebServiceHandler::__construct() - setup properties
	 *
	 * @param string $uname
	 * @param string $pword
	 * @param string $dbName_SPlus
	 * @param string $hname
	 */
	function __construct($uname, $pword, $dbName_SPlus, $hname)
	{
		$this->mstrMethod = $_SERVER['REQUEST_METHOD'];

		$lobjTemp = explode('/', (string) $_SERVER['REQUEST_URI']);

		for($i = (count($lobjTemp) - 1); $i >= 0; $i--)
		{
			if(strtolower($lobjTemp[$i]) == 'api')
			{
				$this->mstrService = $lobjTemp[$i + 1];

				$lobjTemp = array_slice(explode('/', (string) $_SERVER['REQUEST_URI']), $i + 2);

				break;
			}
		}

		for($i = 0; $i < count($lobjTemp); $i = $i + 2)
		{
			if(isset($lobjTemp[$i + 1]))
			{
				$this->mobjUrlParams[strtolower($lobjTemp[$i])] = urldecode($lobjTemp[$i + 1]);
			}
		}

		try {
			$this->mobjDBConnector = new Querier();
		} catch (\Throwable $e) { // was the non-existent SubjectsPlus\API\Exception, and printed the error (paths, DB host)
			error_log('SubjectsPlus API: ' . $e->getMessage());
			$this->sendError(503, 'The database is not available.');
		}
	}

	/**
	 * sp_WebServiceHandler::doService() - execute web service, whether get, post, put, or delete
	 *
	 * This method determines what service is required and if service is not supported,
	 * documentation is provided.
	 *
	 * @return void
	 */
	public function doService()
	{
		global $api_enabled;
		global $api_key;
		global $api_rate_limit;          // requests per minute per client (default 120; 0 = no limit)
		global $api_rate_limit_failed;   // requests per minute per IP address with a missing or wrong key (default 20)

		$this->sendCorsHeaders();
		if ($this->mstrMethod === 'OPTIONS') {
			http_response_code(204);
			exit;
		}

		if( !isset( $api_enabled ) || $api_enabled === FALSE ){
			http_response_code(503);
            $this->displayNotEnabledMessage();
		    exit;
        }

		if($this->mstrService == '' )
		{
			$this->displayDocumentation();
			exit;
		}

		$lstrService = strtolower(preg_replace('/[^a-z]/i', '', (string) $this->mstrService));
		$lstrIp      = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

		// The key may come in a header (preferred: not written to web-server logs) or in the URL (…/key/xxx).
		$lstrKey = '';
		if (!empty($_SERVER['HTTP_X_API_KEY'])) {
			$lstrKey = trim((string) $_SERVER['HTTP_X_API_KEY']);
		} elseif (!empty($_SERVER['HTTP_AUTHORIZATION']) && preg_match('/^Bearer\s+(\S+)$/i', (string) $_SERVER['HTTP_AUTHORIZATION'], $m)) {
			$lstrKey = $m[1];
		} elseif (isset($this->mobjUrlParams['key']) && is_string($this->mobjUrlParams['key'])) {
			$lstrKey = $this->mobjUrlParams['key'];
		}
		unset($this->mobjUrlParams['key']);

		// a key must be configured and match exactly (upstream: loose != and an empty key matched an empty key)
		$lobjClient = null;
		if ($lstrKey !== '' && isset($api_key) && (string) $api_key !== '' && hash_equals((string) $api_key, $lstrKey)) {
			$lobjClient = array('client_id' => 0, 'name' => 'legacy key', 'rate_limit' => 0, 'services' => '');
		} elseif ($lstrKey !== '') {
			$lobjClient = ApiClients::find($lstrKey);
		}

		if (!$lobjClient) {
			list($lboolOk) = RateLimiter::hit('api:fail:' . $lstrIp, isset($api_rate_limit_failed) ? (int) $api_rate_limit_failed : 20, 60);
			ApiClients::logUsage(0, $lstrService, $lboolOk ? 401 : 429);
			if (!$lboolOk) {
				$this->sendError(429, 'Too many requests with a missing or wrong API key. Try again in a minute.', 60);
			}
			$this->displayDocumentation(TRUE);
			exit;
		}

		if ($lobjClient['services'] !== '' && !in_array($lstrService, explode(',', (string) $lobjClient['services']), true)) {
			ApiClients::logUsage($lobjClient['client_id'], $lstrService, 403);
			$this->sendError(403, 'This API key may not use the "' . $lstrService . '" service.');
		}

		$lintLimit = (int) $lobjClient['rate_limit'] > 0 ? (int) $lobjClient['rate_limit'] : (isset($api_rate_limit) ? (int) $api_rate_limit : 120);
		$lstrBucket = $lobjClient['client_id'] > 0 ? 'api:c:' . $lobjClient['client_id'] : 'api:legacy:' . $lstrIp;
		list($lboolOk, $lintRemaining, $lintReset) = RateLimiter::hit($lstrBucket, $lintLimit, 60);
		if ($lintLimit > 0) {
			header('X-RateLimit-Limit: ' . $lintLimit);
			header('X-RateLimit-Remaining: ' . $lintRemaining);
			header('X-RateLimit-Reset: ' . $lintReset);
		}
		if (!$lboolOk) {
			ApiClients::logUsage($lobjClient['client_id'], $lstrService, 429);
			$this->sendError(429, 'Rate limit of ' . $lintLimit . ' requests per minute reached. Try again in ' . $lintReset . ' seconds.', $lintReset);
		}

		$lstrClass = ucwords($lstrService) . 'WebService'; // no path characters in the file lookup below

		if(file_exists(dirname(__FILE__) . '/' . $lstrClass . '.php') && ! in_array($lstrClass, array('WebService', 'InterfaceWebService', 'WebServiceHandler'), true))
		{
			$lstrClass = "SubjectsPlus\\API\\" . $lstrClass;
			$lobjWebService = new $lstrClass($this->mobjUrlParams, $this->mobjDBConnector);
		}else{
			ApiClients::logUsage($lobjClient['client_id'], $lstrService, 404);
			$this->sendError(404, 'Unknown service "' . $lstrService . '". See ' . 'the API documentation (request /api/ without a service).');
		}

		switch($this->mstrMethod)
		{
			case "GET":
				$lobjWebService->setData();
				$lobjWebService->formatOutput();
				$this->mobjWebService = $lobjWebService;
				ApiClients::logUsage($lobjClient['client_id'], $lstrService, 200);
				ApiClients::touch($lobjClient['client_id']);
				break;
			default:
				header('Allow: GET, OPTIONS');
				$this->sendError(405, 'Only GET requests are supported.');
		}
	}

	/** JSON error response (and stop). */
	protected function sendError($lintStatus, $lstrMessage, $lintRetryAfter = 0)
	{
		http_response_code((int) $lintStatus);
		if ($lintRetryAfter > 0) {
			header('Retry-After: ' . (int) $lintRetryAfter);
		}
		header('Content-Type: application/json; charset=utf-8');
		header('Cache-Control: no-store');
		echo json_encode(array('error' => array('status' => (int) $lintStatus, 'message' => $lstrMessage)));
		exit;
	}

	/** Cross-origin access for the origins listed in $api_cors_origins (config.php), e.g. array('https://www.library.edu'). */
	protected function sendCorsHeaders()
	{
		global $api_cors_origins;
		$lstrOrigin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
		if ($lstrOrigin === '' || empty($api_cors_origins)) {
			return;
		}
		$lobjAllowed = array_map(function ($o) { return rtrim(strtolower((string) $o), '/'); }, (array) $api_cors_origins);
		if (in_array('*', $lobjAllowed, true) || in_array(strtolower($lstrOrigin), $lobjAllowed, true)) {
			header('Access-Control-Allow-Origin: ' . (in_array('*', $lobjAllowed, true) ? '*' : $lstrOrigin));
			header('Access-Control-Allow-Methods: GET, OPTIONS');
			header('Access-Control-Allow-Headers: X-API-Key, Authorization');
			header('Access-Control-Expose-Headers: X-RateLimit-Limit, X-RateLimit-Remaining, X-RateLimit-Reset, Retry-After');
			header('Access-Control-Max-Age: 600');
			header('Vary: Origin');
		}
	}

	/**
	 * sp_WebServiceHandler::displayOutput() - echo outs web service output and
	 * determine appropriate header for the format type
	 *
	 * @return void
	 */
	public function displayOutput()
	{
		$lstrFormat = $this->mobjWebService->getFormat();

		switch($lstrFormat)
		{
			case "xml":
				header('Content-type: text/xml');
				echo $this->mobjWebService->getOutput();
				break;
			case "json":
				header('Content-type: application/json');
				echo $this->mobjWebService->getOutput();
				break;
			default:
				header('Content-type: application/json');
				echo $this->mobjWebService->getOutput();
				break;
		}
	}

	/**
	 * sp_WebServiceHandler::displayDocumentation() - display documentation for web service
	 * and depending on parameter, if http code for bad request is added.
	 *
	 * @param boolean $lboolBadRequest
	 * @return void
	 */
	public function displayDocumentation($lboolBadRequest = false)
	{
		if($lboolBadRequest)
		{
			http_response_code(401);

			print "<h1>Missing or wrong API key. Here's how you use this thing.</h1>\n";
		}else
		{
			print "<h1>No service selected.  Here's how you use this thing.</h1>";
		}

		print "<pre><strong>You need to send an API key in order for the API to work.</strong>";
		print "\nAdministrators create keys under Admin &gt; API Clients (one per application, with its own rate limit), or use the site key from Admin &gt; Config Site.";
		print "\nSend the key in an HTTP header (recommended: URLs end up in server logs):\n  X-API-Key: your-key      or      Authorization: Bearer your-key";
		print "\nor at the end of the URL: /key/your-key (URL-encoded).";
		print "\nResponses carry X-RateLimit-Limit / X-RateLimit-Remaining / X-RateLimit-Reset headers; over the limit the API answers 429 with Retry-After.</pre>";
		print "<pre>You Can Query Like This:\n/sp/api/service/parameter-name/parameter-value/key/api-key\n\n";
		print "Results can be returned as xml or json (default).  E.g.:\nsp/api/staff/output/xml/key/api-key\n\n";
		print "staff\n  * enter email address to return results.  Separate multiple addresses with commas.  Examples:\n";
		print "  sp/api/staff/email/you@library.edu/key/api-key\n  sp/api/staff/email/you@library.edu,me@library.edu/key/api-key\n";
		print "  * select a department by id\n  sp/api/staff/department/99/key/api-key\n  * set a limit\n  sp/api/staff/department/99/max/5/key/api-key\n";
        print "  * show all personnel \n";
        print "  sp/api/staff/personnel/all/key/api-key \n\n";
		print "talkback\n  * show all talkbacks submitted between start date and end date\n  sp/api/talkback/startdate/2013-04-01/enddate/2013-04-30/key/api-key\n";
		print "  * show all talkbacks submitted on one date\n  sp/api/talkback/startdate/2013-04-01/key/api-key\n";
		print "  * enter max number of returns\n  sp/api/talkback/max/10/key/api-key\n\ndatabase\n  * Lots of options:\n";
		print "  sp/api/database/letter/A/key/api-key -- show items beginning with A\n  sp/api/database/letter/Num -- show numbers\n";
		print "  sp/api/database/search/Science/key/api-key -- show all items with Science in title\n";
		print "  sp/api/database/subject_id/10/key/api-key -- show all databases associated with that subject id\n";
		print "  sp/api/database/type/Reference/key/api-key -- show all items with that ctag\n";
		print "  sp/api/database/vendor/EBSCO/key/api-key -- show all items from that vendor / publisher\n\n";
		print "  * enter max number of returns\n  sp/api/database/type/Reference/max/10/key/api-key";
		print "\n\nguides\n* Lots of options:\n";
		print "  sp/api/guides/subject_id/22/key/api-key -- show all guides associated with that subject id\n";
		print "  sp/api/guides/shortform/Nursing/key/api-key -- show all guides associated with that shortform\n";
		print "  sp/api/guides/type/Subject/key/api-key -- show all guides of that type\n";
		print "  sp/api/guides/type/Subject/active/1/key/api-key -- show all public guides of that type\n";
		print "  sp/api/guides/type/Subject/collection/1/key/api-key -- show all collections of guides\n";
		print "  sp/api/guides/type/Subject/course_guide/1/key/api-key -- show all public course guides\n";
		print "  sp/api/guides/type/Subject/topic_guide/1/key/api-key -- show all public topic guides\n";
		print "\n  * enter max number of returns\n  sp/api/guides/type/Subject/max/10/key/api-key\n\nfaq\n  * coming soon";
		print "\n\n\n  * If web service is not working correctly, the most common problem is that the .htaccess file has the wrong 'RewriteBase' path.";
		print "\n    It should reflect the path that is after your websites url. E.g. if you have www.mywebsite.com/dir1/sp/api then .htaccess file should have 'RewriteBase' path of /dir1/sp/api/";
		print "</pre>";
	}

    /**
     * sp_WebServiceHandler::displayNotEnabledMessage() - display a message if the api is not enabled
     * @return void
     */
    public function displayNotEnabledMessage()
    {
        print "<h1>API not enabled</h1>\n";

        print "<pre><strong>You need to activate the API.</strong>";
        print "\n
        \nTo enable, go to Admin > Config Site in the Control Panel.";
        print "</pre>";
    }
}

?>