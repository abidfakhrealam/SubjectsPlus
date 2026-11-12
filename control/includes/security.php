<?php
/**
 * @file security.php
 * @brief Shared security helpers: output escaping, same-origin (CSRF) checks, role checks,
 *        safe outbound HTTP (SSRF protection) and input normalisation.
 *
 * Loaded by functions.php, so every page that includes functions.php has these.
 */

if ( defined( 'SP_SECURITY_LOADED' ) ) {
	return;
}
define( 'SP_SECURITY_LOADED', true );

/* ------------------------------------------------------------------ output */

if ( ! function_exists( 'sp_h' ) ) {
	/** HTML-escape for element content and quoted attributes. Accepts null. */
	function sp_h( $value ) {
		return htmlspecialchars( (string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}

if ( ! function_exists( 'sp_js' ) ) {
	/** Encode a value for use inside an inline <script> block. */
	function sp_js( $value ) {
		return json_encode( $value, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR );
	}
}

/* ------------------------------------------------------------------ requests */

if ( ! function_exists( 'sp_request_host' ) ) {
	/** Host (and port) the browser used for this request, lower-case. */
	function sp_request_host() {
		return strtolower( (string) ( $_SERVER['HTTP_HOST'] ?? ( $_SERVER['SERVER_NAME'] ?? '' ) ) );
	}
}

if ( ! function_exists( 'sp_is_same_origin_request' ) ) {
	/**
	 * Did this request come from a SubjectsPlus page (same origin)?
	 *
	 * Uses Fetch Metadata (Sec-Fetch-Site) when the browser sends it, otherwise the Origin header,
	 * otherwise the Referer. Requests without any of these (command-line tools, very old browsers,
	 * privacy extensions that strip Referer) are allowed: a cross-site attacker cannot make a
	 * modern browser omit all three.
	 */
	function sp_is_same_origin_request() {
		$site = strtolower( (string) ( $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '' ) );
		if ( $site !== '' ) {
			return $site === 'same-origin' || $site === 'none';
		}
		$host = sp_request_host();
		foreach ( array( 'HTTP_ORIGIN', 'HTTP_REFERER' ) as $h ) {
			if ( ! empty( $_SERVER[ $h ] ) && $_SERVER[ $h ] !== 'null' ) {
				$p = parse_url( (string) $_SERVER[ $h ] );
				if ( ! $p || empty( $p['host'] ) ) {
					return false;
				}
				$origin = strtolower( $p['host'] . ( isset( $p['port'] ) ? ':' . $p['port'] : '' ) );

				// tolerate default ports being present on one side only
				return $origin === $host || preg_replace( '/:(80|443)$/', '', $origin ) === preg_replace( '/:(80|443)$/', '', $host );
			}
			if ( $h === 'HTTP_ORIGIN' && ( $_SERVER[ $h ] ?? '' ) === 'null' ) {
				return false;
			}
		}

		return true;
	}
}

if ( ! function_exists( 'sp_is_state_changing_request' ) ) {
	function sp_is_state_changing_request() {
		$m = strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) );

		return ! in_array( $m, array( 'GET', 'HEAD', 'OPTIONS' ), true );
	}
}

if ( ! function_exists( 'sp_deny' ) ) {
	/** Stop with an HTTP error (JSON for XHR callers, short text otherwise). */
	function sp_deny( $code = 403, $message = 'Forbidden' ) {
		if ( ! headers_sent() ) {
			http_response_code( (int) $code );
			if ( ( $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '' ) === 'XMLHttpRequest' || stripos( (string) ( $_SERVER['HTTP_ACCEPT'] ?? '' ), 'json' ) !== false ) {
				header( 'Content-Type: application/json; charset=utf-8' );
				echo json_encode( array( 'error' => $message ) );
				exit;
			}
			header( 'Content-Type: text/plain; charset=utf-8' );
		}
		echo $message;
		exit;
	}
}

if ( ! function_exists( 'sp_require_same_origin' ) ) {
	/** Refuse cross-site requests (CSRF). $all_methods = also check GET requests. */
	function sp_require_same_origin( $all_methods = false ) {
		if ( PHP_SAPI === 'cli' ) {
			return;
		}
		if ( ( $all_methods || sp_is_state_changing_request() ) && ! sp_is_same_origin_request() ) {
			error_log( 'SubjectsPlus: cross-site request refused for ' . ( $_SERVER['REQUEST_URI'] ?? '' ) );
			sp_deny( 403, 'Cross-site request refused.' );
		}
	}
}

if ( ! function_exists( 'sp_csrf_token' ) ) {
	/** Per-session token for forms: <input type="hidden" name="sp_csrf" value="<?= sp_csrf_token() ?>"> */
	function sp_csrf_token() {
		if ( session_status() !== PHP_SESSION_ACTIVE ) {
			session_start();
		}
		if ( empty( $_SESSION['sp_csrf'] ) ) {
			$_SESSION['sp_csrf'] = bin2hex( random_bytes( 32 ) );
		}

		return $_SESSION['sp_csrf'];
	}
}

if ( ! function_exists( 'sp_csrf_valid' ) ) {
	function sp_csrf_valid( $token = null ) {
		$token = $token ?? ( $_POST['sp_csrf'] ?? ( $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '' ) );

		return ! empty( $_SESSION['sp_csrf'] ) && is_string( $token ) && hash_equals( $_SESSION['sp_csrf'], $token );
	}
}

if ( ! function_exists( 'sp_safe_local_redirect' ) ) {
	/** Return $target if it is a path on this site ("/..."), else $fallback. Blocks //host and /\host. */
	function sp_safe_local_redirect( $target, $fallback ) {
		$target = (string) $target;
		if ( $target !== '' && preg_match( '#^/(?![/\\\\])[^\r\n\x00]*$#', $target ) ) {
			return $target;
		}

		return $fallback;
	}
}

/* ------------------------------------------------------------------ roles */

if ( ! function_exists( 'sp_has_role' ) ) {
	/** Does the signed-in staff member hold one of these permission tags (admin always passes)? */
	function sp_has_role( ...$roles ) {
		if ( ! empty( $_SESSION['admin'] ) && (int) $_SESSION['admin'] === 1 ) {
			return true;
		}
		foreach ( $roles as $r ) {
			if ( ! empty( $_SESSION[ $r ] ) && (int) $_SESSION[ $r ] === 1 ) {
				return true;
			}
		}

		return false;
	}
}

if ( ! function_exists( 'sp_require_role' ) ) {
	function sp_require_role( ...$roles ) {
		if ( ! sp_has_role( ...$roles ) ) {
			sp_deny( 403, 'You do not have permission to do that.' );
		}
	}
}

/* ------------------------------------------------------------------ input */

if ( ! function_exists( 'sp_int' ) ) {
	/** Integer from user input; non-numeric values become $default (never a TypeError). */
	function sp_int( $value, $default = 0 ) {
		if ( is_int( $value ) ) {
			return $value;
		}
		if ( is_float( $value ) || ( is_string( $value ) && is_numeric( trim( $value ) ) ) ) {
			return (int) round( (float) $value );
		}

		return $default;
	}
}

if ( ! function_exists( 'sp_int_list' ) ) {
	/** "1,2,x,3" -> "1,2,3" (safe for SQL IN (...)); '' when nothing valid. */
	function sp_int_list( $value ) {
		$out = array();
		foreach ( is_array( $value ) ? $value : explode( ',', (string) $value ) as $v ) {
			if ( is_numeric( trim( (string) $v ) ) ) {
				$out[] = (int) $v;
			}
		}

		return implode( ',', array_unique( $out ) );
	}
}

if ( ! function_exists( 'sp_isbn' ) ) {
	/** Digits and X only; '' unless it is a 10- or 13-character ISBN. */
	function sp_isbn( $value ) {
		$v = strtoupper( preg_replace( '/[^0-9Xx]/', '', (string) $value ) );

		return ( strlen( $v ) === 10 || strlen( $v ) === 13 ) ? $v : '';
	}
}

if ( ! function_exists( 'sp_safe_filename' ) ) {
	/** Basename limited to [A-Za-z0-9._-], no leading dot. */
	function sp_safe_filename( $name ) {
		$name = basename( str_replace( '\\', '/', (string) $name ) );
		$name = preg_replace( '/[^A-Za-z0-9._-]/', '', $name );

		return ltrim( (string) $name, '.' );
	}
}

/* ------------------------------------------------------------------ outbound HTTP (SSRF) */

if ( ! function_exists( 'sp_ip_is_public' ) ) {
	function sp_ip_is_public( $ip ) {
		return (bool) filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
	}
}

if ( ! function_exists( 'sp_resolve_public_url' ) ) {
	/**
	 * Validate an outbound URL: http(s) only, no credentials, host resolves only to public addresses.
	 * Returns array(host, port, ip) or false.
	 */
	function sp_resolve_public_url( $url, ?array $allowed_hosts = null ) {
		$p = parse_url( (string) $url );
		if ( ! $p || empty( $p['scheme'] ) || empty( $p['host'] ) || isset( $p['user'] ) || isset( $p['pass'] ) ) {
			return false;
		}
		$scheme = strtolower( $p['scheme'] );
		if ( $scheme !== 'http' && $scheme !== 'https' ) {
			return false;
		}
		$host = strtolower( rtrim( $p['host'], '.' ) );
		if ( $allowed_hosts !== null ) {
			$ok = false;
			foreach ( $allowed_hosts as $a ) {
				$a = strtolower( $a );
				if ( $host === $a || substr( $host, - strlen( '.' . $a ) ) === '.' . $a ) {
					$ok = true;
					break;
				}
			}
			if ( ! $ok ) {
				return false;
			}
		}
		$port = isset( $p['port'] ) ? (int) $p['port'] : ( $scheme === 'https' ? 443 : 80 );
		$bare = trim( $host, '[]' );
		if ( filter_var( $bare, FILTER_VALIDATE_IP ) ) {
			$ips = array( $bare );
		} else {
			$ips = array();
			foreach ( @dns_get_record( $host, DNS_A | DNS_AAAA ) ?: array() as $r ) {
				$ips[] = $r['ip'] ?? ( $r['ipv6'] ?? '' );
			}
			if ( ! $ips ) {
				$ips = @gethostbynamel( $host ) ?: array();
			}
		}
		$ips = array_filter( $ips );
		if ( ! $ips ) {
			return false;
		}
		foreach ( $ips as $ip ) {
			if ( ! sp_ip_is_public( $ip ) ) {
				return false;
			}
		}

		return array( $host, $port, reset( $ips ) );
	}
}

if ( ! function_exists( 'sp_http_get' ) ) {
	/**
	 * GET a public http(s) URL with SSRF protection (private/loopback/link-local addresses refused,
	 * DNS pinned, redirects re-checked), a timeout and a size limit.
	 *
	 * @return string|false body, or false on any failure
	 */
	function sp_http_get( $url, $max_bytes = 2097152, $timeout = 10, ?array $allowed_hosts = null, &$content_type = null ) {
		if ( ! function_exists( 'curl_init' ) ) {
			return false;
		}
		for ( $hop = 0; $hop < 4; $hop ++ ) {
			$r = sp_resolve_public_url( $url, $allowed_hosts );
			if ( ! $r ) {
				return false;
			}
			list( $host, $port, $ip ) = $r;
			$body = '';
			$ch   = curl_init();
			curl_setopt_array( $ch, array(
				CURLOPT_URL            => $url,
				CURLOPT_RETURNTRANSFER => false,
				CURLOPT_FOLLOWLOCATION => false,
				CURLOPT_CONNECTTIMEOUT => 5,
				CURLOPT_TIMEOUT        => (int) $timeout,
				CURLOPT_USERAGENT      => 'SubjectsPlus/4.6',
				CURLOPT_RESOLVE        => array( $host . ':' . $port . ':' . ( strpos( $ip, ':' ) !== false ? '[' . $ip . ']' : $ip ) ),
				CURLOPT_HEADER         => false,
				CURLOPT_WRITEFUNCTION  => function ( $ch, $chunk ) use ( &$body, $max_bytes ) {
					$body .= $chunk;

					return strlen( $body ) > $max_bytes ? 0 : strlen( $chunk );
				},
			) );
			if ( defined( 'CURLOPT_PROTOCOLS_STR' ) ) {
				curl_setopt( $ch, CURLOPT_PROTOCOLS_STR, 'http,https' );
			} elseif ( defined( 'CURLOPT_PROTOCOLS' ) ) {
				curl_setopt( $ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS );
			}
			$ok           = curl_exec( $ch );
			$code         = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
			$location     = (string) curl_getinfo( $ch, CURLINFO_REDIRECT_URL );
			$content_type = (string) curl_getinfo( $ch, CURLINFO_CONTENT_TYPE );
			curl_close( $ch );
			if ( $code >= 300 && $code < 400 && $location !== '' ) {
				$url = $location;
				continue;
			}
			if ( $ok === false || $code < 200 || $code >= 300 ) {
				return false;
			}

			return $body;
		}

		return false;
	}
}
