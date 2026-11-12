<?php
/**
 * @file booklist_router.php
 * @brief Server side of the Book List box (assets/js/guides/bookList.js), shared by
 *        control/guides/bookListPlusletActionRouter.php (staff) and
 *        subjects/bookListPlusletActionRouter.php (public guide pages).
 *
 * 4.6.0 hardening. Upstream (public, no sign-in):
 *  - fetched any URL given in ?url= with cURL (file://, http://169.254.169.254/, intranet hosts …)
 *    and saved the response under assets/cache/, readable by anyone (SSRF + local file disclosure);
 *  - used ?isbn= unchecked in the cache file name (path traversal);
 *  - let anyone overwrite the cached title/author/link shown on public guides (content spoofing / XSS);
 *  - turned TLS certificate checks off for every outbound request.
 * Now: ISBNs are validated, outbound requests go only to the book-data services below over verified
 * TLS, covers must really be images, and public visitors can only fill an empty cache entry for an
 * ISBN that is actually in a Book List box.
 *
 * Callers set $sp_booklist_is_staff (bool) before including this file.
 */

use SubjectsPlus\Control\Querier;

const SP_BOOKLIST_COVER_HOSTS = array(
	'books.google.com', 'books.googleusercontent.com', 'googleusercontent.com', 'googleapis.com',
	'covers.openlibrary.org', 'openlibrary.org', 'archive.org', 'syndetics.com',
);

function sp_booklist_cache_dir() {
	return dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR;
}

/** ISBN as typed in the box (digits, X and hyphens), '' when it is not a 10/13-digit ISBN. */
function sp_booklist_isbn( $raw ) {
	$raw = trim( (string) $raw );
	if ( ! preg_match( '/^[0-9Xx-]{10,17}$/', $raw ) ) {
		return '';
	}

	return sp_isbn( $raw ) === '' ? '' : $raw;
}

/** Is this ISBN listed in at least one Book List box? (public cache writes only) */
function sp_booklist_isbn_in_use( $isbn ) {
	$db   = new Querier;
	$rows = $db->queryWithPreparedStatement(
		"SELECT pluslet_id FROM pluslet WHERE type = 'BookList' AND (body LIKE :a OR extra LIKE :b) LIMIT 1", NULL,
		array( ':a' => '%' . $isbn . '%', ':b' => '%' . $isbn . '%' ) );

	return ! empty( $rows );
}

function sp_booklist_http( $url, ?array $hosts = null ) {
	if ( $hosts === null ) {
		$hosts = array_merge( SP_BOOKLIST_COVER_HOSTS, array( 'exlibrisgroup.com' ) );
	}
	$body = sp_http_get( $url, 2097152, 10, $hosts );

	return $body === false ? '' : $body;
}

function validateSyndeticsClientCode() {
	global $syndetics_client_code;
	$url = "https://syndetics.com/index.aspx?isbn=9780605039070/xml.xml&client=" . rawurlencode( (string) $syndetics_client_code ) . "&type=rn12";
	libxml_use_internal_errors( true );
	$xml = simplexml_load_string( sp_booklist_http( $url ) );
	libxml_use_internal_errors( false );
	echo $xml ? 'true' : 'false';
}

function validateGoogleBooksAPIKey() {
	global $google_books_api_key;
	$url  = "https://www.googleapis.com/books/v1/volumes?key=" . rawurlencode( (string) $google_books_api_key ) . "&q=9780605039070";
	$json = json_decode( sp_booklist_http( $url ) );
	echo ( ! $json || isset( $json->error ) ) ? 'false' : 'true';
}

function validateSyndeticsImageExists() {
	global $syndetics_client_code;
	$isbn = sp_isbn( $_GET['isbn'] ?? '' );
	if ( $isbn === '' ) {
		echo '';

		return;
	}
	$url = 'https://syndetics.com/index.aspx?isbn=' . $isbn . '/xml.xml&client=' . rawurlencode( (string) $syndetics_client_code ) . '&type=rn12';
	libxml_use_internal_errors( true );
	$xml = simplexml_load_string( sp_booklist_http( $url ) );
	libxml_use_internal_errors( false );
	$out = '';
	if ( $xml ) {
		foreach ( array( 'LC', 'MC', 'SC' ) as $size ) {
			if ( isset( $xml->$size ) ) {
				$out = (string) $xml->$size;
				break;
			}
		}
	}
	echo preg_match( '#^https?://[^\s"\'<>]+$#i', $out ) ? $out : ''; // plain text for bookList.js
}

function isbn_in_primo() {
	global $booklist_primo_institution_code, $booklist_primo_api_key, $booklist_primo_domain, $booklist_primo_vid;
	$isbn = sp_isbn( $_GET['isbn'] ?? '' );
	if ( $isbn === '' ) {
		echo '';

		return;
	}
	$url      = "https://api-na.hosted.exlibrisgroup.com/primo/v1/pnxs?q=any,contains," . $isbn . '&lang=eng&view=brief&vid=' . rawurlencode( (string) $booklist_primo_institution_code ) . '&scope=default_scope&apikey=' . rawurlencode( (string) $booklist_primo_api_key );
	$response = json_decode( sp_booklist_http( $url ), true );
	$result   = '';
	if ( isset( $response['info']['total'] ) && $response['info']['total'] != 0 && isset( $response['docs'][0]['pnxId'] ) ) {
		$result = 'https://' . $booklist_primo_domain . '/primo-explore/fulldisplay?docid=' . rawurlencode( (string) $response['docs'][0]['pnxId'] )
			. '&context=L&vid=' . rawurlencode( (string) $booklist_primo_vid ) . '&institution=' . rawurlencode( (string) $booklist_primo_institution_code );
	}
	echo $result;
}

function book_metadata_from_open_library() {
	$isbn   = sp_isbn( $_GET['isbn'] ?? '' );
	$result = array( "isbn" => array() );
	if ( $isbn !== '' ) {
		$response = json_decode( sp_booklist_http( "https://openlibrary.org/api/volumes/brief/isbn/" . $isbn . ".json" ), true );
		if ( is_array( $response ) ) {
			foreach ( $response as $data ) {
				foreach ( (array) $data as $info ) {
					$d      = (array) ( $info['data'] ?? array() );
					$author = array();
					foreach ( (array) ( $d['authors'] ?? array() ) as $a ) {
						$author[] = (string) ( $a['name'] ?? '' );
					}
					$result = array( "isbn" => array( 'title' => (string) ( $d['title'] ?? '' ), 'isbn' => $isbn, 'author' => array( $author ), 'date' => (string) ( $d['publish_date'] ?? '' ) ) );
				}
				break;
			}
		}
	}
	header( 'Content-Type: application/json; charset=utf-8' );
	echo json_encode( $result );
}

function book_cover_from_open_library() {
	global $BaseURL;
	$isbn   = sp_isbn( $_GET['isbn'] ?? '' );
	$result = '';
	if ( $isbn !== '' ) {
		$response = json_decode( sp_booklist_http( "https://openlibrary.org/api/books?bibkeys=ISBN:" . $isbn . "&format=json" ), true );
		foreach ( (array) $response as $data ) {
			if ( ! empty( $data['thumbnail_url'] ) && preg_match( '#^https?://#i', $data['thumbnail_url'] ) ) {
				$result = str_replace( "-S.jpg", "-M.jpg", $data['thumbnail_url'] );
			}
		}
	}
	if ( $result === '' ) {
		$result = rtrim( (string) $BaseURL, '/' ) . '/assets/images/blank-cover.png';
	}
	echo preg_match( '#^https?://[^\s"\'<>]+$#i', $result ) ? $result : '';
}

function sp_booklist_may_write( $isbn, $file ) {
	global $sp_booklist_is_staff;
	if ( $isbn === '' ) {
		return false;
	}
	if ( ! empty( $sp_booklist_is_staff ) ) {
		return true;
	}

	// public visitors may only fill an empty cache entry, and only for ISBNs used in a Book List box
	return ! file_exists( $file ) && sp_booklist_isbn_in_use( $isbn );
}

function book_metadata_download() {
	global $booklist_primo_domain;
	$isbn = sp_booklist_isbn( $_GET['isbn'] ?? '' );
	$file = sp_booklist_cache_dir() . $isbn . ".bookmetadata";
	if ( ! sp_booklist_may_write( $isbn, $file ) ) {
		return;
	}
	$clean   = function ( $k ) {
		return mb_substr( trim( strip_tags( html_entity_decode( (string) ( $_GET[ $k ] ?? '' ), ENT_QUOTES, 'UTF-8' ) ) ), 0, 500 );
	};
	$primo   = (string) ( $_GET['primoUrl'] ?? '' );
	$primoOk = $primo !== '' && ! empty( $booklist_primo_domain ) && strpos( $primo, 'https://' . $booklist_primo_domain . '/' ) === 0;
	$data    = array(
		"isbn" => array(
			'title'    => $clean( 'title' ),
			'isbn'     => $isbn,
			'author'   => $clean( 'author' ),
			'date'     => $clean( 'date' ),
			'primoUrl' => $primoOk ? $primo : '',
		)
	);
	@file_put_contents( $file, json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ), LOCK_EX );
}

function book_cover_download() {
	$isbn = sp_booklist_isbn( $_GET['isbn'] ?? '' );
	$url  = (string) ( $_GET['url'] ?? '' );
	$file = sp_booklist_cache_dir() . $isbn . ".jpg";
	if ( ! sp_booklist_may_write( $isbn, $file ) ) {
		return;
	}
	if ( $url === '' || strpos( $url, 'blank-cover' ) !== false ) {
		$raw = @file_get_contents( dirname( __DIR__, 2 ) . '/assets/images/blank-cover.png' );
	} else {
		$raw = sp_booklist_http( html_entity_decode( $url, ENT_QUOTES, 'UTF-8' ), SP_BOOKLIST_COVER_HOSTS );
	}
	// only real images end up in the public cache
	if ( $raw !== '' && $raw !== false && @getimagesizefromstring( $raw ) !== false ) {
		@file_put_contents( $file, $raw, LOCK_EX );
	}
}

$sp_booklist_action = (string) ( $_GET['action'] ?? '' );
switch ( $sp_booklist_action ) {
	case 'validateSyndeticsClientCode':
		validateSyndeticsClientCode();
		break;
	case 'validateGoogleBooksAPIKey':
		validateGoogleBooksAPIKey();
		break;
	case 'validateSyndeticsImageExists':
		validateSyndeticsImageExists();
		break;
	case 'isbnPrimoCheck':
		isbn_in_primo();
		break;
	case 'bookMetadataFromOpenLibrary':
		book_metadata_from_open_library();
		break;
	case 'bookCoverFromOpenLibrary':
		book_cover_from_open_library();
		break;
	case 'bookMetadataDownload':
		book_metadata_download();
		break;
	case 'bookCoverDownload':
		book_cover_download();
		break;
	default:
		http_response_code( 400 );
}
