<?php
namespace SubjectsPlus\Control\Stats;

use SubjectsPlus\Control\Querier;

use SubjectsPlus\Control\Interfaces\OutputInterface;


#[\AllowDynamicProperties] // PHP 8.2+ compatibility
class Stats implements OutputInterface {

	private $db;
	private $remote_address;
	private $http_referer;
	private $query_string;
	private $date;
	private $page_title;
	private $user_agent;
	private $subject_short_form;
	private $event_type;
	private $tab_name;
	private $link_url;
	private $link_title;
	private $in_tab;
	private $in_pluslet;


	public function __construct(Querier $db) {
		$this->db = $db;
		$this->date = time();
	}

	public function getPageTitle() {
		return $this->page_title;
	}
	public function setPageTitle($page_title) {
		$this->page_title = $page_title;
		return $this;
	}

	public function getRemoteAddress() {
		return $this->remote_address;
	}
	public function setRemoteAddress($remote_address) {
		$this->remote_address = $remote_address;
		return $this;
	}
	public function getHttpReferer() {
		return $this->http_referer;
	}
	public function setHttpReferer($http_referer) {
		$this->http_referer = $http_referer;
		return $this;
	}
	public function getQueryString() {
		return $this->query_string;
	}
	public function setQueryString($query_string) {
		$this->query_string = $query_string;
		return $this;
	}
	public function getUserAgent() {
		return $this->user_agent;
	}
	public function setUserAgent($user_agent) {
		$this->user_agent = $user_agent;
		return $this;
	}
	public function getSubjectShortForm() {
		return $this->subject_short_form;
	}
	public function setSubjectShortForm($subject_short_form) {
		$this->subject_short_form = $subject_short_form;
		return $this;
	}
	public function getEventType() {
		return $this->event_type;
	}
	public function setEventType($event_type) {
		$this->event_type = $event_type;
		return $this;
	}
	public function getTabName() {
		return $this->tab_name;
	}
	public function setTabName($tab_name) {
		$this->tab_name = $tab_name;
		return $this;
	}

	public function getLinkUrl() {
		return $this->link_url;
	}
	public function setLinkUrl($link_url) {
		$this->link_url = $link_url;
		return $this;
	}

	public function getLinkTitle() {
	return $this->link_url;
}
	public function setLinkTitle($link_title) {
		$this->link_title = $link_title;
		return $this;
	}

	public function getInTab() {
		return $this->in_tab;
	}
	public function setInTab($in_tab) {
		$this->in_tab = $in_tab;
		return $this;
	}

	public function getInPluslet() {
		return $this->in_pluslet;
	}
	public function setInPluslet($in_pluslet) {
		$this->in_pluslet = $in_pluslet;
		return $this;
	}

	public function loadStats($short_form) {
		$connection = $this->db->getConnection();
		$statement = $connection->prepare("SELECT * FROM stats WHERE subject_short_form = :short_form");
		$statement->bindParam(":short_form", $short_form);
		$statement->execute();
		$stats = $statement->fetchAll();

		return json_encode((object) $stats);


	}

	/** Known crawlers / monitoring tools (their visits are not counted when $stats_skip_bots is on). */
	public static function isBot( $userAgent ) {
		$ua = strtolower( (string) $userAgent );

		return $ua === '' || (bool) preg_match( '/bot|crawl|spider|slurp|bingpreview|facebookexternalhit|headless|python-requests|curl\/|wget|httpclient|monitor|uptime|pingdom|lighthouse|preview/', $ua );
	}

	/** 203.0.113.42 -> 203.0.113.0, 2001:db8:1:2:3:4:5:6 -> 2001:db8:1:: (no personal data kept). */
	public static function anonymiseIp( $ip ) {
		$ip = (string) $ip;
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			return preg_replace( '/\.\d+$/', '.0', $ip );
		}
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			$b = inet_pton( $ip );

			return inet_ntop( substr( $b, 0, 6 ) . str_repeat( "\0", 10 ) );
		}

		return '';
	}

	public function saveStats() {
		if ( ( $GLOBALS['stats_skip_bots'] ?? true ) && self::isBot( $this->user_agent ) ) {
			return; // 4.7.1: crawlers and uptime monitors inflated every report
		}
		if ( $GLOBALS['stats_anonymise_ip'] ?? true ) {
			$this->remote_address = self::anonymiseIp( $this->remote_address );
		}

		$connection = $this->db->getConnection();
		// the columns are 200 characters; longer values (e.g. off-campus links) made the insert
		// fail under strict SQL mode, so the click was lost. Keep the first 200 characters.
		foreach ( array( 'http_referer', 'remote_address', 'page_title', 'user_agent', 'subject_short_form', 'event_type', 'tab_name', 'link_url', 'link_title', 'in_tab', 'in_pluslet' ) as $k ) {
			$this->$k = $this->$k === null ? null : mb_substr( (string) $this->$k, 0, 200 );
		}
		// match clicks on database links to their e-resource record (for usage reports)
		static $hasTitleId = null;
		if ( $hasTitleId === null ) {
			$r          = $connection->query( "SHOW COLUMNS FROM stats LIKE 'title_id'" );
			$hasTitleId = $r && $r->fetch();
		}
		$titleId = null;
		if ( $hasTitleId && in_array( $this->event_type, array( 'link', 'database' ), true ) && (string) $this->link_url !== '' ) {
			$titleId = \SubjectsPlus\Control\DataExchange\ReportsExchange::titleIdForUrl( $this->link_url );
			$titleId = $titleId ?: 0;
		}
		$statement = $connection->prepare("INSERT INTO stats (http_referer, remote_address, date, page_title, user_agent, subject_short_form, event_type,tab_name,link_url, link_title, in_tab, in_pluslet" . ( $hasTitleId ? ", title_id" : "" ) . ") VALUES (:http_referer, :remote_address, :date, :page_title, :user_agent, :subject_short_form, :event_type, :tab_name, :link_url, :link_title, :in_tab, :in_pluslet" . ( $hasTitleId ? ", :title_id" : "" ) . ")");
		$statement->bindParam(":http_referer", $this->http_referer);
		$statement->bindParam(":remote_address", $this->remote_address);
		$statement->bindParam(":date", $this->date);
		$statement->bindParam(":page_title", $this->page_title);
		$statement->bindParam(":user_agent", $this->user_agent);
		$statement->bindParam(":subject_short_form", $this->subject_short_form);
		$statement->bindParam(":event_type", $this->event_type);
		$statement->bindParam(":tab_name", $this->tab_name);
		$statement->bindParam(":link_url", $this->link_url);
		$statement->bindParam(":link_title", $this->link_title);
		$statement->bindParam(":in_tab", $this->in_tab);
		$statement->bindParam(":in_pluslet", $this->in_pluslet);
		if ( $hasTitleId ) {
			$statement->bindValue( ":title_id", $titleId, $titleId === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT );
		}
		$statement->execute();

	}

	public function toArray() {
		return get_object_vars ( $this );
	}
	public function toJSON() {
		return json_encode ( get_object_vars ( $this ) );
	}






}