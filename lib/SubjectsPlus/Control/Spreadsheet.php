<?php
namespace SubjectsPlus\Control;

/**
 * read and write spreadsheets (Excel .xlsx and CSV) without extra PHP extensions.
 *
 * .xlsx files are ZIP archives of XML; this class writes and reads them with zlib only (no
 * ZipArchive, which is often switched off on Windows/WAMP). Old binary .xls files are not
 * supported: Excel can save them as .xlsx or CSV.
 *
 *   Spreadsheet::send( 'records', array( array( 'name' => 'Records', 'rows' => $rows ) ), 'xlsx' );
 *   $rows = Spreadsheet::read( $_FILES['f']['tmp_name'], $_FILES['f']['name'] );  // list of rows
 */
class Spreadsheet {

	const MAX_ROWS        = 50000;
	const MAX_UNZIPPED    = 104857600; // 100 MB of XML is far more than any library spreadsheet
	const DATE_STYLE      = 2;

	/* ------------------------------------------------------------------ writing */

	/**
	 * Sends a download and exits. $sheets: list of array('name' => ..., 'rows' => list of lists;
	 * the first row is the header). $format: 'xlsx' or 'csv' (CSV uses only the first sheet).
	 */
	public static function send( $basename, array $sheets, $format = 'xlsx' ) {
		$basename = preg_replace( '/[^A-Za-z0-9_.-]+/', '-', $basename ) . '-' . date( 'Y-m-d' );
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		if ( $format === 'csv' ) {
			$body = self::csvBytes( $sheets[0]['rows'] );
			header( 'Content-Type: text/csv; charset=UTF-8' );
			header( 'Content-Disposition: attachment; filename="' . $basename . '.csv"' );
		} else {
			$body = self::xlsxBytes( $sheets );
			header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
			header( 'Content-Disposition: attachment; filename="' . $basename . '.xlsx"' );
		}
		header( 'Content-Length: ' . strlen( $body ) );
		header( 'Cache-Control: no-store' );
		echo $body;
		exit;
	}

	/** CSV with a UTF-8 byte-order mark so Excel shows Indian-language and accented text correctly. */
	public static function csvBytes( array $rows ) {
		$fh = fopen( 'php://temp', 'w+' );
		fwrite( $fh, "\xEF\xBB\xBF" );
		foreach ( $rows as $row ) {
			$out = array();
			foreach ( $row as $v ) {
				$out[] = self::csvSafe( $v );
			}
			fputcsv( $fh, $out, ',', '"', '' );
		}
		rewind( $fh );
		$s = stream_get_contents( $fh );
		fclose( $fh );

		return $s;
	}

	/** Stops spreadsheet programs from running a cell as a formula (CSV injection). */
	private static function csvSafe( $v ) {
		if ( $v instanceof \DateTimeInterface ) {
			return $v->format( 'Y-m-d' );
		}
		$v = (string) $v;
		if ( $v !== '' && strpos( "=+-@\t\r", $v[0] ) !== false && ! is_numeric( $v ) ) {
			return "'" . $v;
		}

		return $v;
	}

	public static function xlsxBytes( array $sheets ) {
		$files = array();
		$wbSheets = $wbRels = $ctSheets = '';
		$names = array();
		foreach ( array_values( $sheets ) as $i => $sheet ) {
			$n    = $i + 1;
			$name = self::sheetName( isset( $sheet['name'] ) ? $sheet['name'] : 'Sheet' . $n, $names );
			$files[ "xl/worksheets/sheet$n.xml" ] = self::sheetXml( $sheet['rows'] );
			$wbSheets .= '<sheet name="' . self::x( $name ) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
			$wbRels   .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
			$ctSheets .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
		}
		$n = count( $sheets );
		$wbRels .= '<Relationship Id="rId' . ( $n + 1 ) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
		$x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
		$files['[Content_Types].xml'] = $x . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
			. '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
			. '<Default Extension="xml" ContentType="application/xml"/>'
			. '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
			. '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
			. $ctSheets . '</Types>';
		$files['_rels/.rels'] = $x . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
			. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>';
		$files['xl/workbook.xml'] = $x . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
			. '<sheets>' . $wbSheets . '</sheets></workbook>';
		$files['xl/_rels/workbook.xml.rels'] = $x . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $wbRels . '</Relationships>';
		$files['xl/styles.xml'] = $x . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
			. '<numFmts count="1"><numFmt numFmtId="164" formatCode="yyyy-mm-dd"/></numFmts>'
			. '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
			. '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
			. '<fill><patternFill patternType="solid"><fgColor rgb="FFE8E8E8"/><bgColor indexed="64"/></patternFill></fill></fills>'
			. '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
			. '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
			. '<cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
			. '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
			. '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs>'
			. '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';

		return self::zip( $files );
	}

	private static function sheetName( $name, array &$used ) {
		$name = trim( str_replace( array( '\\', '/', '?', '*', '[', ']', ':' ), ' ', (string) $name ) );
		$name = mb_substr( $name === '' ? 'Sheet' : $name, 0, 31 );
		$base = $name;
		$i    = 2;
		while ( in_array( mb_strtolower( $name ), $used, true ) ) {
			$name = mb_substr( $base, 0, 28 ) . ' ' . $i++;
		}
		$used[] = mb_strtolower( $name );

		return $name;
	}

	private static function sheetXml( array $rows ) {
		$widths = array();
		$data   = '';
		foreach ( array_values( $rows ) as $r => $row ) {
			$data .= '<row r="' . ( $r + 1 ) . '">';
			foreach ( array_values( $row ) as $c => $v ) {
				$ref = self::colName( $c ) . ( $r + 1 );
				$len = 0;
				if ( $v === null || $v === '' ) {
					continue;
				} elseif ( $v instanceof \DateTimeInterface ) {
					$data .= '<c r="' . $ref . '" s="' . self::DATE_STYLE . '"><v>' . self::excelDate( $v ) . '</v></c>';
					$len = 10;
				} elseif ( ( is_int( $v ) || is_float( $v ) ) && $r > 0 ) {
					$data .= '<c r="' . $ref . '"><v>' . $v . '</v></c>';
					$len = strlen( (string) $v );
				} else {
					$s     = self::clean( (string) $v );
					$style = $r === 0 ? ' s="1"' : '';
					$data .= '<c r="' . $ref . '" t="inlineStr"' . $style . '><is><t xml:space="preserve">' . self::x( $s ) . '</t></is></c>';
					$len = min( 60, mb_strlen( $s ) );
				}
				$widths[ $c ] = max( isset( $widths[ $c ] ) ? $widths[ $c ] : 8, $len + 2 );
			}
			$data .= '</row>';
		}
		$cols = '';
		foreach ( $widths as $c => $w ) {
			$cols .= '<col min="' . ( $c + 1 ) . '" max="' . ( $c + 1 ) . '" width="' . $w . '" customWidth="1"/>';
		}
		$ncols = $rows ? count( reset( $rows ) ) : 1;

		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
			. '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
			. '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
			. ( $cols ? '<cols>' . $cols . '</cols>' : '' )
			. '<sheetData>' . $data . '</sheetData>'
			. ( count( $rows ) > 1 ? '<autoFilter ref="A1:' . self::colName( max( 0, $ncols - 1 ) ) . count( $rows ) . '"/>' : '' )
			. '</worksheet>';
	}

	/** Removes characters that XML 1.0 does not allow (they make Excel refuse the file). */
	private static function clean( $s ) {
		if ( ! mb_check_encoding( $s, 'UTF-8' ) ) {
			$s = mb_convert_encoding( $s, 'UTF-8', 'Windows-1252' );
		}

		return preg_replace( '/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $s );
	}

	private static function x( $s ) {
		return htmlspecialchars( $s, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
	}

	public static function colName( $i ) {
		$s = '';
		for ( $i = $i + 1; $i > 0; $i = intdiv( $i - 1, 26 ) ) {
			$s = chr( 65 + ( $i - 1 ) % 26 ) . $s;
		}

		return $s;
	}

	private static function excelDate( \DateTimeInterface $d ) {
		$base = new \DateTime( '1899-12-30 00:00:00', $d->getTimezone() );
		$days = ( $d->getTimestamp() - $base->getTimestamp() + $d->getOffset() - $base->getOffset() ) / 86400;

		return round( $days, 6 );
	}

	/** Minimal ZIP writer (deflate), enough for Office documents. */
	private static function zip( array $files ) {
		$out = $central = '';
		$dosTime = ( ( (int) date( 'H' ) ) << 11 ) | ( ( (int) date( 'i' ) ) << 5 ) | ( (int) ( date( 's' ) / 2 ) );
		$dosDate = ( ( (int) date( 'Y' ) - 1980 ) << 9 ) | ( ( (int) date( 'n' ) ) << 5 ) | (int) date( 'j' );
		foreach ( $files as $name => $data ) {
			$crc  = crc32( $data );
			$comp = gzdeflate( $data, 6 );
			$head = pack( 'vvvvvVVVvv', 20, 0x0800, 8, $dosTime, $dosDate, $crc, strlen( $comp ), strlen( $data ), strlen( $name ), 0 );
			$offset = strlen( $out );
			$out .= "PK\x03\x04" . $head . $name . $comp;
			$central .= "PK\x01\x02" . pack( 'v', 20 ) . $head . pack( 'vvvVV', 0, 0, 0, 0, $offset ) . $name;
		}

		return $out . $central . "PK\x05\x06" . pack( 'vvvvVVv', 0, 0, count( $files ), count( $files ), strlen( $central ), strlen( $out ), 0 );
	}

	/* ------------------------------------------------------------------ reading */

	/**
	 * Reads the first sheet of an .xlsx file or a CSV/TSV file into a list of rows (lists of
	 * strings; dates as YYYY-MM-DD). Throws \RuntimeException with a message for the user.
	 */
	public static function read( $path, $originalName = '' ) {
		$head = (string) file_get_contents( $path, false, null, 0, 8 );
		if ( strncmp( $head, "PK\x03\x04", 4 ) === 0 ) {
			return self::readXlsx( $path );
		}
		if ( strncmp( $head, "\xD0\xCF\x11\xE0", 4 ) === 0 ) {
			throw new \RuntimeException( _( "This is an old-style Excel file (.xls). In Excel choose File → Save As → Excel Workbook (.xlsx) or CSV, and upload that." ) );
		}

		return self::readCsv( $path );
	}

	private static function readCsv( $path ) {
		$raw = (string) file_get_contents( $path );
		if ( strncmp( $raw, "\xFF\xFE", 2 ) === 0 ) {                       // Excel "Unicode text"
			$raw = mb_convert_encoding( substr( $raw, 2 ), 'UTF-8', 'UTF-16LE' );
		} elseif ( strncmp( $raw, "\xFE\xFF", 2 ) === 0 ) {
			$raw = mb_convert_encoding( substr( $raw, 2 ), 'UTF-8', 'UTF-16BE' );
		} elseif ( strncmp( $raw, "\xEF\xBB\xBF", 3 ) === 0 ) {
			$raw = substr( $raw, 3 );
		} elseif ( ! mb_check_encoding( $raw, 'UTF-8' ) ) {
			$raw = mb_convert_encoding( $raw, 'UTF-8', 'Windows-1252' );     // older Excel CSV on Windows
		}
		$first = strtok( $raw, "\n" );
		$delim = ',';
		$best  = 0;
		foreach ( array( ',', ';', "\t", '|' ) as $d ) {
			$n = substr_count( (string) $first, $d );
			if ( $n > $best ) {
				$best  = $n;
				$delim = $d;
			}
		}
		$fh = fopen( 'php://temp', 'w+' );
		fwrite( $fh, $raw );
		rewind( $fh );
		$rows = array();
		while ( ( $row = fgetcsv( $fh, 0, $delim, '"', '' ) ) !== false ) {
			if ( $row === array( null ) ) {
				continue;
			}
			$rows[] = array_map( array( __CLASS__, 'unCsvSafe' ), $row );
			if ( count( $rows ) > self::MAX_ROWS ) {
				throw new \RuntimeException( sprintf( _( "The file has more than %d rows. Split it into smaller files." ), self::MAX_ROWS ) );
			}
		}
		fclose( $fh );

		return $rows;
	}

	/** Undoes csvSafe() for files exported by SubjectsPlus. */
	private static function unCsvSafe( $v ) {
		$v = (string) $v;
		if ( strlen( $v ) > 1 && $v[0] === "'" && strpos( "=+-@", $v[1] ) !== false ) {
			return substr( $v, 1 );
		}

		return $v;
	}

	/** Reads the entries of a ZIP file (stored or deflated) into name => bytes. */
	private static function unzip( $path, ?array $wanted = null ) {
		$data = (string) file_get_contents( $path );
		$eocd = strrpos( $data, "PK\x05\x06" );
		if ( $eocd === false ) {
			throw new \RuntimeException( _( "The file looks damaged (not a complete Excel file)." ) );
		}
		$e      = unpack( 'vdisk/vcdisk/ventries/vtotal/Vsize/Voffset', substr( $data, $eocd + 4, 16 ) );
		$p      = $e['offset'];
		$out    = array();
		$total  = 0;
		for ( $i = 0; $i < $e['total']; $i++ ) {
			if ( substr( $data, $p, 4 ) !== "PK\x01\x02" ) {
				throw new \RuntimeException( _( "The file looks damaged (not a complete Excel file)." ) );
			}
			$c    = unpack( 'vmade/vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnlen/velen/vclen/vdisk/viattr/Veattr/Voffset', substr( $data, $p + 4, 42 ) );
			$name = substr( $data, $p + 46, $c['nlen'] );
			$p   += 46 + $c['nlen'] + $c['elen'] + $c['clen'];
			if ( $wanted !== null && ! in_array( $name, $wanted, true ) && strpos( $name, 'xl/worksheets/' ) !== 0 ) {
				continue;
			}
			$total += $c['usize'];
			if ( $total > self::MAX_UNZIPPED ) {
				throw new \RuntimeException( _( "The file is too large to import." ) );
			}
			$l     = unpack( 'vnlen/velen', substr( $data, $c['offset'] + 26, 4 ) );
			$start = $c['offset'] + 30 + $l['nlen'] + $l['elen'];
			$raw   = substr( $data, $start, $c['csize'] );
			if ( $c['method'] === 0 ) {
				$out[ $name ] = $raw;
			} elseif ( $c['method'] === 8 ) {
				$inf = @gzinflate( $raw, self::MAX_UNZIPPED );
				if ( $inf === false ) {
					throw new \RuntimeException( _( "The file looks damaged (could not unpack it)." ) );
				}
				$out[ $name ] = $inf;
			} else {
				throw new \RuntimeException( _( "This Excel file uses a compression method that is not supported. Save it again from Excel or as CSV." ) );
			}
		}

		return $out;
	}

	private static function xml( $s ) {
		$prev = libxml_use_internal_errors( true );
		$x    = simplexml_load_string( $s, 'SimpleXMLElement', LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE );
		libxml_use_internal_errors( $prev );
		if ( $x === false ) {
			throw new \RuntimeException( _( "The file looks damaged (unreadable XML inside)." ) );
		}

		return $x;
	}

	private static function readXlsx( $path ) {
		$z = self::unzip( $path, array( 'xl/workbook.xml', 'xl/_rels/workbook.xml.rels', 'xl/sharedStrings.xml', 'xl/styles.xml' ) );
		if ( ! isset( $z['xl/workbook.xml'] ) ) {
			throw new \RuntimeException( _( "This is not an Excel workbook (.xlsx)." ) );
		}
		// first sheet in workbook order
		$wb    = self::xml( $z['xl/workbook.xml'] );
		$sheet = 'xl/worksheets/sheet1.xml';
		$first = $wb->sheets->sheet[0];
		if ( $first !== null && isset( $z['xl/_rels/workbook.xml.rels'] ) ) {
			$rid  = (string) $first->attributes( 'http://schemas.openxmlformats.org/officeDocument/2006/relationships' )->id;
			$rels = self::xml( $z['xl/_rels/workbook.xml.rels'] );
			foreach ( $rels->Relationship as $r ) {
				if ( (string) $r['Id'] === $rid ) {
					$t     = ltrim( (string) $r['Target'], '/' );
					$sheet = strpos( $t, 'xl/' ) === 0 ? $t : 'xl/' . $t;
				}
			}
		}
		if ( ! isset( $z[ $sheet ] ) ) {
			throw new \RuntimeException( _( "Could not find the first sheet in this workbook." ) );
		}
		// shared strings
		$shared = array();
		if ( isset( $z['xl/sharedStrings.xml'] ) ) {
			foreach ( self::xml( $z['xl/sharedStrings.xml'] )->si as $si ) {
				$shared[] = self::richText( $si );
			}
		}
		// which styles are dates
		$dateStyles = array();
		if ( isset( $z['xl/styles.xml'] ) ) {
			$st     = self::xml( $z['xl/styles.xml'] );
			$custom = array();
			if ( isset( $st->numFmts ) ) {
				foreach ( $st->numFmts->numFmt as $f ) {
					$code = preg_replace( '/"[^"]*"|\[[^\]]*\]|\\\\./', '', (string) $f['formatCode'] );
					$custom[ (int) $f['numFmtId'] ] = (bool) preg_match( '/[dmy]/i', $code ) && ! preg_match( '/^[hms:.\s]*$/i', $code );
				}
			}
			$i = 0;
			if ( isset( $st->cellXfs ) ) {
				foreach ( $st->cellXfs->xf as $xf ) {
					$id = (int) $xf['numFmtId'];
					$dateStyles[ $i++ ] = ( $id >= 14 && $id <= 17 ) || $id === 22 || ( $id >= 27 && $id <= 36 ) || ( $id >= 50 && $id <= 58 ) || ! empty( $custom[ $id ] );
				}
			}
		}
		$rows = array();
		$ws   = self::xml( $z[ $sheet ] );
		foreach ( $ws->sheetData->row as $row ) {
			$r    = isset( $row['r'] ) ? (int) $row['r'] - 1 : count( $rows );
			$vals = array();
			$c    = 0;
			foreach ( $row->c as $cell ) {
				if ( isset( $cell['r'] ) && preg_match( '/^([A-Z]+)/', (string) $cell['r'], $m ) ) {
					$c = 0;
					foreach ( str_split( $m[1] ) as $ch ) {
						$c = $c * 26 + ord( $ch ) - 64;
					}
					$c--;
				}
				$t = (string) $cell['t'];
				$v = (string) $cell->v;
				if ( $t === 's' ) {
					$v = isset( $shared[ (int) $v ] ) ? $shared[ (int) $v ] : '';
				} elseif ( $t === 'inlineStr' ) {
					$v = self::richText( $cell->is );
				} elseif ( $t === 'b' ) {
					$v = $v === '1' ? 'TRUE' : 'FALSE';
				} elseif ( $t === 'e' ) {
					$v = '';
				} elseif ( $t === '' || $t === 'n' ) {
					$s = (int) $cell['s'];
					if ( $v !== '' && ! empty( $dateStyles[ $s ] ) && is_numeric( $v ) ) {
						$v = self::fromExcelDate( (float) $v );
					} elseif ( $v !== '' && is_numeric( $v ) && preg_match( '/^-?\d+\.\d{10,}$/', $v ) ) {
						$v = (string) round( (float) $v, 9 );  // 0.1+0.2 style binary noise
					}
				}
				$vals[ $c ] = $v;
				$c++;
			}
			if ( $vals ) {
				$line = array_fill( 0, max( array_keys( $vals ) ) + 1, '' );
				foreach ( $vals as $k => $v ) {
					$line[ $k ] = $v;
				}
				$rows[ $r ] = $line;
			}
			if ( count( $rows ) > self::MAX_ROWS ) {
				throw new \RuntimeException( sprintf( _( "The file has more than %d rows. Split it into smaller files." ), self::MAX_ROWS ) );
			}
		}
		if ( ! $rows ) {
			return array();
		}
		// fill skipped (blank) rows so row numbers match what people see in Excel
		$out = array();
		for ( $i = 0, $max = max( array_keys( $rows ) ); $i <= $max; $i++ ) {
			$out[] = isset( $rows[ $i ] ) ? $rows[ $i ] : array();
		}

		return $out;
	}

	private static function richText( $node ) {
		if ( $node === null ) {
			return '';
		}
		if ( ! isset( $node->r ) ) { // plain text (isset() is false for an empty <t/>, so no test on t)
			return (string) $node->t;
		}
		$s = '';
		foreach ( $node->r as $run ) {
			$s .= (string) $run->t;
		}

		return $s;
	}

	private static function fromExcelDate( $serial ) {
		$days = (int) floor( $serial );
		$secs = (int) round( ( $serial - $days ) * 86400 );
		$d    = ( new \DateTime( '1899-12-30', new \DateTimeZone( 'UTC' ) ) )->modify( "+$days days" );
		if ( $secs > 0 ) {
			$d->modify( "+$secs seconds" );

			return $d->format( 'Y-m-d H:i' );
		}

		return $d->format( 'Y-m-d' );
	}

	/* ------------------------------------------------------------------ helpers for importers */

	/**
	 * Turns rows into a list of associative rows keyed by known column names. $columns maps
	 * key => list of accepted header spellings (case/space/punctuation-insensitive).
	 * Returns array( 'rows' => [ rowNumber => [key => value] ], 'unknown' => [headers], 'missing' => [keys] ).
	 */
	public static function mapHeader( array $rows, array $columns, array $required = array() ) {
		$norm = function ( $s ) {
			return preg_replace( '/[^a-z0-9]+/', '', mb_strtolower( trim( (string) $s ) ) );
		};
		// first non-empty row is the header
		$h = null;
		foreach ( $rows as $i => $row ) {
			if ( array_filter( $row, 'strlen' ) ) {
				$h = $i;
				break;
			}
		}
		if ( $h === null ) {
			return array( 'rows' => array(), 'unknown' => array(), 'missing' => $required, 'index' => array() );
		}
		$lookup = array();
		foreach ( $columns as $key => $names ) {
			foreach ( (array) $names as $n ) {
				$lookup[ $norm( $n ) ] = $key;
			}
			$lookup[ $norm( $key ) ] = $key;
		}
		$index = $unknown = array();
		foreach ( $rows[ $h ] as $c => $title ) {
			$k = $norm( $title );
			if ( $k === '' ) {
				continue;
			}
			if ( isset( $lookup[ $k ] ) && ! in_array( $lookup[ $k ], $index, true ) ) {
				$index[ $c ] = $lookup[ $k ];
			} else {
				$unknown[ $c ] = (string) $title;
			}
		}
		$out = array();
		for ( $i = $h + 1, $n = count( $rows ); $i < $n; $i++ ) {
			$row = $rows[ $i ];
			if ( ! array_filter( array_map( 'trim', array_map( 'strval', $row ) ), 'strlen' ) ) {
				continue;
			}
			$a = array();
			foreach ( $index as $c => $key ) {
				$a[ $key ] = isset( $row[ $c ] ) ? trim( (string) $row[ $c ] ) : '';
			}
			foreach ( $unknown as $c => $title ) {
				$a[ '#' . $title ] = isset( $row[ $c ] ) ? trim( (string) $row[ $c ] ) : '';
			}
			$out[ $i + 1 ] = $a; // spreadsheet row number
		}

		return array( 'rows' => $out, 'unknown' => array_values( $unknown ), 'missing' => array_values( array_diff( $required, $index ) ), 'index' => $index );
	}
}
