<?php

namespace SubjectsPlus\Control;

/**
 * builds off-campus links for licensed resources.
 *
 * A library can set up any number of access providers (EZproxy, OpenAthens, RemoteXs, RemoteLog,
 * MyLOFT or anything else) in Admin > Off-campus Access. Each provider has a link template:
 *
 *   {url}      the resource address, unchanged          e.g. https://ezproxy.example.edu/login?url={url}
 *   {url_enc}  the resource address, percent-encoded    e.g. https://go.openathens.net/redirector/example.edu?url={url_enc}
 *   {host}     the resource's host name
 *
 * An empty template leaves the link unchanged (for app/extension-based tools such as MyLOFT).
 *
 * Which provider a resource uses:
 *   1. a domain rule matching the resource's host (most specific domain wins), else
 *   2. the provider marked as default, else
 *   3. the legacy $proxyURL from config.php (behaviour of SubjectsPlus 4.6), else no change.
 *
 * Only resources whose access restriction behaves as "proxy" or "campus" are rewritten (by default
 * "Restricted" and "On Campus Only"); "direct" and "noproxy" ones are linked directly. Behaviours are
 * set per restriction in Admin > Record Settings.
 */
#[\AllowDynamicProperties]
class AccessLinks {

	const RESTRICTED       = 2;
	const ON_CAMPUS_ONLY   = 3;

	/** Starting points offered in the admin page. */
	public static function presets() {
		return array(
			'ezproxy'    => array( 'label' => 'EZproxy', 'template' => 'https://ezproxy.example.edu/login?url={url}', 'note' => '', 'hint' => 'Replace ezproxy.example.edu with your EZproxy server.' ),
			'openathens' => array( 'label' => 'OpenAthens', 'template' => 'https://go.openathens.net/redirector/example.edu?url={url_enc}', 'note' => '', 'hint' => 'Replace example.edu with your OpenAthens domain (OpenAthens admin > Resources > Redirector link generator).' ),
			'remotexs'   => array( 'label' => 'RemoteXs', 'template' => 'https://example.remotexs.in/login?url={url}', 'note' => '', 'hint' => 'Example only: ask RemoteXs for your institution\'s login address and link format.' ),
			'remotelog'  => array( 'label' => 'RemoteLog', 'template' => 'https://example.remotlog.com/login?url={url}', 'note' => '', 'hint' => 'Example only: ask your RemoteLog administrator for your login address and link format.' ),
			'myloft'     => array( 'label' => 'MyLOFT', 'template' => '', 'note' => 'Off campus? Open this resource through the MyLOFT app or browser extension.', 'hint' => 'MyLOFT works through its app and browser extension, so links stay unchanged and users see the note.' ),
			'custom'     => array( 'label' => 'Other / custom', 'template' => '', 'note' => '', 'hint' => 'Use {url} or {url_enc} where the database address goes.' ),
		);
	}

	private static $providers = null;
	private static $rules     = null;

	/** Forget cached providers (after the admin page saves changes, and in tests). */
	public static function reset() {
		self::$providers = null;
		self::$rules     = null;
	}

	private static function load() {
		if ( self::$providers !== null ) {
			return;
		}
		self::$providers = array();
		self::$rules     = array();
		try {
			$db   = new Querier;
			$conn = $db->getConnection();
			if ( ! $conn ) {
				return;
			}
			$stmt = $conn->query( "SELECT provider_id, name, kind, link_template, portal_url, note, is_default FROM access_provider WHERE active = 1" );
			if ( $stmt ) {
				foreach ( $stmt->fetchAll( \PDO::FETCH_ASSOC ) as $row ) {
					self::$providers[ (int) $row['provider_id'] ] = $row;
				}
			}
			$stmt = $conn->query( "SELECT r.domain, r.provider_id FROM access_rule r JOIN access_provider p ON p.provider_id = r.provider_id WHERE p.active = 1" );
			if ( $stmt ) {
				foreach ( $stmt->fetchAll( \PDO::FETCH_ASSOC ) as $row ) {
					self::$rules[ strtolower( $row['domain'] ) ] = (int) $row['provider_id'];
				}
				// most specific (longest) domain first
				uksort( self::$rules, function ( $a, $b ) { return strlen( $b ) - strlen( $a ); } );
			}
		} catch ( \Throwable $e ) {
			// Tables not created yet (tools/migrate.php not run): fall back to $proxyURL.
			self::$providers = array();
			self::$rules     = array();
		}
	}

	/** Normalise a domain typed by a librarian: "https://www.JSTOR.org/stable" -> "jstor.org" */
	public static function normaliseDomain( $domain ) {
		$domain = strtolower( trim( (string) $domain ) );
		if ( strpos( $domain, '://' ) !== false ) {
			$domain = (string) parse_url( $domain, PHP_URL_HOST );
		}
		$domain = preg_replace( '#[/:].*$#', '', $domain );
		$domain = preg_replace( '/^\*?\.|^www\./', '', $domain );

		return trim( $domain, '.' );
	}

	/** The provider that applies to this URL, or null. */
	public static function providerFor( $url ) {
		self::load();
		$host = strtolower( (string) parse_url( (string) $url, PHP_URL_HOST ) );
		if ( $host !== '' ) {
			foreach ( self::$rules as $domain => $providerId ) {
				if ( $host === $domain || substr( $host, - ( strlen( $domain ) + 1 ) ) === '.' . $domain ) {
					if ( isset( self::$providers[ $providerId ] ) ) {
						return self::$providers[ $providerId ];
					}
				}
			}
		}
		foreach ( self::$providers as $p ) {
			if ( (int) $p['is_default'] === 1 ) {
				return $p;
			}
		}

		return null;
	}

	/** Apply a link template to a URL. */
	public static function applyTemplate( $template, $url ) {
		$template = trim( (string) $template );
		if ( $template === '' ) {
			return $url;
		}
		$host = (string) parse_url( (string) $url, PHP_URL_HOST );
		if ( strpos( $template, '{url' ) === false ) {
			// A plain prefix such as "https://ezproxy.example.edu/login?url="
			return $template . $url;
		}

		return strtr( $template, array( '{url_enc}' => rawurlencode( $url ), '{url}' => $url, '{host}' => $host ) );
	}

	/** Does this access-restriction value need an off-campus link? */
	public static function needsProxy( $restriction ) {
		// decided by the restriction's behaviour (Admin > Record Settings), not fixed ids
		return RecordSettings::usesProxy( $restriction );
	}

	/**
	 * The link to show for a resource.
	 *
	 * @param string   $url          resource address from the record
	 * @param int|null $restriction  location.access_restrictions (1 none, 2 restricted, 3 on campus, 4 no proxy)
	 */
	public static function url( $url, $restriction ) {
		// record URLs are stored HTML-escaped by the record form ("&amp;"); work with the real address
		$url = html_entity_decode( trim( (string) $url ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		if ( $url === '' || ! self::needsProxy( $restriction ) ) {
			return $url;
		}
		// Already proxied (pasted with a prefix, or a relative/local link): leave alone.
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			return $url;
		}
		global $proxyURL;
		// Already wrapped by any configured provider (or the legacy prefix): never wrap twice.
		self::load();
		foreach ( self::$providers as $p ) {
			if ( self::isAlreadyProxied( $url, $p['link_template'] ) ) {
				return $url;
			}
		}
		if ( ! empty( $proxyURL ) && stripos( $url, $proxyURL ) === 0 ) {
			return $url;
		}

		$provider = self::providerFor( $url );
		if ( $provider !== null ) {
			return self::applyTemplate( $provider['link_template'], $url );
		}

		if ( ! empty( $proxyURL ) && strpos( $url, $proxyURL ) !== 0 ) {
			return $proxyURL . $url;
		}

		return $url;
	}

	private static function isAlreadyProxied( $url, $template ) {
		$template = trim( (string) $template );
		if ( $template === '' ) {
			return false;
		}
		$pos    = strpos( $template, '{' );
		$prefix = $pos === false ? $template : substr( $template, 0, $pos );

		return $prefix !== '' && stripos( $url, $prefix ) === 0;
	}

	/** Patron-facing note for this resource ("Off campus? Use MyLOFT…"), or ''. */
	public static function note( $url, $restriction ) {
		if ( ! self::needsProxy( $restriction ) ) {
			return '';
		}
		$p = self::providerFor( $url );

		return $p ? (string) $p['note'] : '';
	}
}
