<?php
namespace SubjectsPlus\Control\DataExchange;

use SubjectsPlus\Control\RecordSettings;
use SubjectsPlus\Control\Spreadsheet;

/**
 * turns a KBART title list (the standard holdings file publishers and aggregators such as
 * EBSCO, ProQuest, Springer, JSTOR, Taylor & Francis provide) into rows for RecordsExchange, so
 * the normal preview/import of records is used.
 */
class KbartImport {

	public static function columns() {
		return array(
			'publication_title'       => array( 'publication_title', 'Title' ),
			'print_identifier'        => array( 'print_identifier', 'ISSN', 'ISBN' ),
			'online_identifier'       => array( 'online_identifier', 'eISSN', 'eISBN' ),
			'date_first_issue_online' => array( 'date_first_issue_online' ),
			'num_first_vol_online'    => array( 'num_first_vol_online' ),
			'date_last_issue_online'  => array( 'date_last_issue_online' ),
			'num_last_vol_online'     => array( 'num_last_vol_online' ),
			'title_url'               => array( 'title_url', 'URL' ),
			'first_author'            => array( 'first_author' ),
			'embargo_info'            => array( 'embargo_info' ),
			'coverage_depth'          => array( 'coverage_depth' ),
			'notes'                   => array( 'notes' ),
			'publisher_name'          => array( 'publisher_name', 'Publisher' ),
			'publication_type'        => array( 'publication_type' ),
			'access_type'             => array( 'access_type' ),
			'date_monograph_published_online' => array( 'date_monograph_published_online' ),
		);
	}

	/**
	 * $opt: format, access, status, subjects, category (names as shown in SubjectsPlus), free_access
	 * (access name for titles marked access_type F). Returns rows in the records-import layout.
	 */
	public static function toRecordRows( array $sheetRows, array $opt, &$problem = null ) {
		$map = Spreadsheet::mapHeader( $sheetRows, self::columns() );
		if ( ! in_array( 'publication_title', $map['index'], true ) || ! in_array( 'title_url', $map['index'], true ) ) {
			$problem = _( "This does not look like a KBART file: it needs publication_title and title_url columns." );

			return array();
		}
		// custom fields that can take KBART values, matched by name
		$cf = array();
		foreach ( RecordSettings::fields() as $f ) {
			$l = mb_strtolower( $f['label'] );
			if ( in_array( $l, array( 'issn', 'print issn', 'isbn' ), true ) ) {
				$cf['print_identifier'] = $f['label'];
			} elseif ( in_array( $l, array( 'eissn', 'e-issn', 'online issn', 'eisbn' ), true ) ) {
				$cf['online_identifier'] = $f['label'];
			} elseif ( in_array( $l, array( 'publisher', 'vendor', 'provider' ), true ) ) {
				$cf['publisher_name'] = $f['label'];
			} elseif ( in_array( $l, array( 'coverage', 'holdings', 'coverage dates' ), true ) ) {
				$cf['coverage'] = $f['label'];
			}
		}
		$header = array_merge( array( 'Title', 'URL', 'Format', 'Access', 'Status', 'Subjects', 'Subject category', 'Description', 'Tags' ), array_values( $cf ) );
		$out    = array( $header );
		foreach ( $map['rows'] as $in ) {
			$cover = self::coverage( $in );
			$desc  = array();
			if ( ( $in['first_author'] ?? '' ) !== '' ) {
				$desc[] = sprintf( _( "By %s." ), $in['first_author'] );
			}
			if ( ( $in['publisher_name'] ?? '' ) !== '' ) {
				$desc[] = sprintf( _( "Publisher: %s." ), $in['publisher_name'] );
			}
			if ( $cover !== '' ) {
				$desc[] = sprintf( _( "Online coverage: %s." ), $cover );
			}
			if ( ( $in['embargo_info'] ?? '' ) !== '' ) {
				$desc[] = sprintf( _( "Embargo: %s." ), self::embargo( $in['embargo_info'] ) );
			}
			$ids = array_filter( array( $in['print_identifier'] ?? '', $in['online_identifier'] ?? '' ) );
			if ( $ids ) {
				$desc[] = ( stripos( $in['publication_type'] ?? '', 'mono' ) !== false ? 'ISBN ' : 'ISSN ' ) . implode( ' / ', $ids ) . '.';
			}
			$free = strtoupper( trim( $in['access_type'] ?? '' ) ) === 'F' && ! empty( $opt['free_access'] );
			$row  = array(
				$in['publication_title'], $in['title_url'], $opt['format'] ?? '', $free ? $opt['free_access'] : ( $opt['access'] ?? '' ),
				$opt['status'] ?? '', $opt['subjects'] ?? '', $opt['category'] ?? '', htmlspecialchars( implode( ' ', $desc ), ENT_QUOTES ),
				trim( ( $in['publication_type'] ?? '' ) . ( ( $in['coverage_depth'] ?? '' ) !== '' ? ' ' . $in['coverage_depth'] : '' ) ),
			);
			foreach ( $cf as $k => $label ) {
				$row[] = $k === 'coverage' ? $cover : ( $in[ $k ] ?? '' );
			}
			$out[] = $row;
		}

		return $out;
	}

	private static function coverage( array $in ) {
		$from = trim( ( $in['date_first_issue_online'] ?? '' ) );
		$to   = trim( ( $in['date_last_issue_online'] ?? '' ) );
		if ( $from === '' && $to === '' ) {
			return '';
		}
		$part = function ( $d, $vol ) {
			$d = substr( $d, 0, 4 );

			return $d . ( trim( (string) $vol ) !== '' ? ' (vol. ' . trim( $vol ) . ')' : '' );
		};

		return ( $from !== '' ? $part( $from, $in['num_first_vol_online'] ?? '' ) : '…' ) . ' – '
			. ( $to !== '' ? $part( $to, $in['num_last_vol_online'] ?? '' ) : _( "present" ) );
	}

	/** KBART embargo: R1Y = only the most recent year is available; P1Y = the most recent year is not available. */
	private static function embargo( $e ) {
		if ( preg_match( '/^([RP])(\d+)([DMY])$/i', trim( $e ), $m ) ) {
			$unit = array( 'D' => _( "day(s)" ), 'M' => _( "month(s)" ), 'Y' => _( "year(s)" ) )[ strtoupper( $m[3] ) ];

			return strtoupper( $m[1] ) === 'R'
				? sprintf( _( "only the most recent %1\$d %2\$s available" ), $m[2], $unit )
				: sprintf( _( "the most recent %1\$d %2\$s not available" ), $m[2], $unit );
		}

		return $e;
	}
}
