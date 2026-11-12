<?php

namespace SubjectsPlus\Control\Auth;

/**
 * minimal SIP2 client for checking a library patron's barcode + PIN against the ILS
 * (Koha, Alma, Symphony, Sierra, Polaris, ... — anything with a SIP2 server).
 *
 * Sends 93 (Login, if a SIP account is configured) and 63 (Patron Information), and reports
 * whether the patron is valid (BL=Y) and the PIN is correct (CQ=Y), plus the email (BE) and name (AE).
 */
#[\AllowDynamicProperties]
class Sip2Client {

	private $host;
	private $port;
	private $timeout;
	private $tls;
	private $sipLogin;
	private $sipPassword;
	private $location;
	private $institution;
	private $socket = null;
	private $seq = 0;
	public $lastError = '';

	public function __construct( array $cfg ) {
		$this->host        = (string) ( $cfg['host'] ?? '' );
		$this->port        = (int) ( $cfg['port'] ?? 6001 );
		$this->timeout     = (int) ( $cfg['timeout'] ?? 10 );
		$this->tls         = ! empty( $cfg['tls'] );
		$this->sipLogin    = (string) ( $cfg['sip_login'] ?? '' );
		$this->sipPassword = (string) ( $cfg['sip_password'] ?? '' );
		$this->location    = (string) ( $cfg['location'] ?? '' );
		$this->institution = (string) ( $cfg['institution'] ?? '' );
	}

	/** SIP2 checksum: two's complement of the byte sum, as 4 upper-case hex digits. */
	public static function checksum( $msg ) {
		$sum = 0;
		$len = strlen( $msg );
		for ( $i = 0; $i < $len; $i++ ) {
			$sum += ord( $msg[ $i ] );
		}

		return sprintf( '%04X', ( -$sum ) & 0xFFFF );
	}

	private function frame( $body ) {
		$msg = $body . 'AY' . ( $this->seq++ % 10 ) . 'AZ';

		return $msg . self::checksum( $msg ) . "\r";
	}

	/** SIP2 forbids '|' inside field values. */
	private static function clean( $v ) {
		return str_replace( array( '|', "\r", "\n" ), '', (string) $v );
	}

	private function connect() {
		$target = ( $this->tls ? 'tls://' : 'tcp://' ) . $this->host . ':' . $this->port;
		$errno  = 0;
		$errstr = '';
		$this->socket = @stream_socket_client( $target, $errno, $errstr, $this->timeout );
		if ( ! $this->socket ) {
			$this->lastError = "Cannot reach SIP2 server ($errstr)";

			return false;
		}
		stream_set_timeout( $this->socket, $this->timeout );

		return true;
	}

	private function send( $body ) {
		fwrite( $this->socket, $this->frame( $body ) );
		$resp = '';
		while ( ! feof( $this->socket ) ) {
			$c = fgetc( $this->socket );
			if ( $c === false ) {
				break;
			}
			if ( $c === "\r" ) {
				break;
			}
			$resp .= $c;
		}

		return trim( $resp, "\n\r\0" );
	}

	/** Split the variable-length part of a SIP2 response into a field => value map. */
	public static function fields( $resp, $fixedLength ) {
		$out = array();
		foreach ( explode( '|', substr( $resp, $fixedLength ) ) as $f ) {
			if ( strlen( $f ) >= 2 && ! isset( $out[ substr( $f, 0, 2 ) ] ) ) {
				$out[ substr( $f, 0, 2 ) ] = substr( $f, 2 );
			}
		}

		return $out;
	}

	/**
	 * @return array|false  ['valid' => bool, 'email' => string, 'name' => string, 'patron' => string] or false on error
	 */
	public function checkPatron( $barcode, $pin ) {
		if ( $this->host === '' ) {
			$this->lastError = 'SIP2 host not configured';

			return false;
		}
		if ( ! $this->connect() ) {
			return false;
		}
		try {
			if ( $this->sipLogin !== '' ) {
				$r = $this->send( '9300CN' . self::clean( $this->sipLogin ) . '|CO' . self::clean( $this->sipPassword ) . '|CP' . self::clean( $this->location ) . '|' );
				if ( substr( $r, 0, 3 ) !== '941' ) {
					$this->lastError = 'SIP2 login to the ILS was refused';

					return false;
				}
			}
			$date = gmdate( 'Ymd' ) . '    ' . gmdate( 'His' );
			$r    = $this->send( '63001' . $date . '          ' . 'AO' . self::clean( $this->institution ) . '|AA' . self::clean( $barcode )
			                     . '|AC' . self::clean( $this->sipPassword ) . '|AD' . self::clean( $pin ) . '|' );
			if ( substr( $r, 0, 2 ) !== '64' ) {
				$this->lastError = 'Unexpected SIP2 response';

				return false;
			}
			// 64 + status(14) + language(3) + date(18) + 6 counts x 4 = 61 characters of fixed fields
			$f = self::fields( $r, 61 );

			return array(
				'valid'  => ( $f['BL'] ?? 'N' ) === 'Y' && ( $f['CQ'] ?? 'N' ) === 'Y',
				'email'  => strtolower( trim( $f['BE'] ?? '' ) ),
				'name'   => trim( $f['AE'] ?? '' ),
				'patron' => trim( $f['AA'] ?? $barcode ),
			);
		} finally {
			if ( $this->socket ) {
				fwrite( $this->socket, $this->frame( '35' . gmdate( 'Ymd' ) . '    ' . gmdate( 'His' ) . 'AO' . self::clean( $this->institution ) . '|AA' . self::clean( $barcode ) . '|' ) );
				fclose( $this->socket );
				$this->socket = null;
			}
		}
	}
}
