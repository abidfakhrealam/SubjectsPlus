<?php

namespace SubjectsPlus\Control;

/**
 * custom fields on database records (Admin > Record Settings > Custom fields).
 *
 * Values are stored per record in record_field_value. Checkbox values are stored as JSON arrays.
 * Uploaded files go to assets/records/<title_id>/ under a random name; that folder is closed to
 * browsers and files are served by control/records/field_file.php (staff only, unless the field is public).
 */
#[\AllowDynamicProperties]
class RecordFields {

	private static $publicCache = null;

	private static function conn() {
		$db = new Querier;

		return $db->getConnection();
	}

	private static function h( $s ) {
		return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
	}

	public static function values( $titleId ) {
		if ( ! $titleId ) {
			return array();
		}
		$st = self::conn()->prepare( "SELECT field_id, value FROM record_field_value WHERE title_id = ?" );
		$st->execute( array( (int) $titleId ) );

		return $st->fetchAll( \PDO::FETCH_KEY_PAIR );
	}

	public static function storageDir( $titleId = null ) {
		$base = dirname( __DIR__, 3 ) . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'records';
		if ( ! is_dir( $base ) ) {
			@mkdir( $base, 0755, true );
		}
		// keep browsers out even if the web server config was not updated (Apache .htaccess; IIS web.config)
		if ( ! file_exists( $base . '/.htaccess' ) ) {
			@file_put_contents( $base . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\n  Deny from all\n</IfModule>\n" );
		}
		if ( ! file_exists( $base . '/web.config' ) ) {
			@file_put_contents( $base . '/web.config', '<?xml version="1.0"?><configuration><system.webServer><authorization><deny users="*" /></authorization></system.webServer></configuration>' );
		}
		if ( ! file_exists( $base . '/index.html' ) ) {
			@file_put_contents( $base . '/index.html', '' );
		}

		return $titleId === null ? $base : $base . DIRECTORY_SEPARATOR . (int) $titleId;
	}

	/** Display a stored value as text (for lists, emails and the public page). */
	public static function displayValue( array $f, $value, $forPublic = false, $titleId = null ) {
		if ( $value === null || $value === '' ) {
			return '';
		}
		switch ( $f['type'] ) {
			case 'checkboxes':
				$arr = json_decode( (string) $value, true );

				return self::h( implode( ', ', is_array( $arr ) ? $arr : array() ) );
			case 'money':
				return self::h( trim( ( $f['currency'] ?: '' ) . ' ' . number_format( (float) $value, 2 ) ) );
			case 'date':
				$t = strtotime( (string) $value );

				return self::h( $t ? date( 'j M Y', $t ) : $value );
			case 'url':
				return preg_match( '#^https?://#i', (string) $value ) ? '<a href="' . self::h( $value ) . '" target="_blank" rel="noopener">' . self::h( $value ) . '</a>' : self::h( $value );
			case 'file':
				global $BaseURL;
				$url = rtrim( (string) $BaseURL, '/' ) . '/control/records/field_file.php?title_id=' . (int) $titleId . '&field_id=' . (int) $f['field_id'];

				return '<a href="' . self::h( $url ) . '">' . self::h( self::originalName( $value ) ) . '</a>';
			case 'textarea':
				return nl2br( self::h( $value ) );
			default:
				return self::h( $value );
		}
	}

	/** Stored file values look like "<random>__<original name>". */
	public static function originalName( $stored ) {
		$base = basename( (string) $stored );
		$pos  = strpos( $base, '__' );

		return $pos === false ? $base : substr( $base, $pos + 2 );
	}

	/** HTML inputs for the record edit form. */
	public static function formHtml( $titleId ) {
		$fields = RecordSettings::fields();
		if ( ! $fields ) {
			return '';
		}
		$vals = self::values( $titleId );
		$html = '';
		foreach ( $fields as $f ) {
			$id    = (int) $f['field_id'];
			$name  = 'cf[' . $id . ']';
			$val   = $vals[ $id ] ?? '';
			$req   = (int) $f['required'] === 1;
			$reqA  = $req ? ' required' : '';
			$label = '<label for="cf_' . $id . '">' . self::h( $f['label'] ) . ( $req ? ' <span class="required_star" title="' . sp__( "required" ) . '">*</span>' : '' ) . '</label>';
			$help  = $f['help'] !== '' ? '<small class="cf-help" style="display:block;color:#666">' . self::h( $f['help'] ) . '</small>' : '';
			switch ( $f['type'] ) {
				case 'textarea':
					$input = '<textarea id="cf_' . $id . '" name="' . $name . '" rows="3" class="pure-input-1"' . $reqA . '>' . self::h( $val ) . '</textarea>';
					break;
				case 'select':
					$input = '<select id="cf_' . $id . '" name="' . $name . '"' . $reqA . '><option value="">—</option>';
					$opts  = RecordSettings::fieldOptions( $f );
					if ( $val !== '' && ! in_array( $val, $opts, true ) ) {
						$opts[] = $val; // keep a value that was removed from the list
					}
					foreach ( $opts as $o ) {
						$input .= '<option value="' . self::h( $o ) . '"' . ( $o === $val ? ' selected' : '' ) . '>' . self::h( $o ) . '</option>';
					}
					$input .= '</select>';
					break;
				case 'checkboxes':
					$sel   = json_decode( (string) $val, true );
					$sel   = is_array( $sel ) ? $sel : array();
					$input = '<input type="hidden" name="' . $name . '[]" value="">';
					foreach ( array_unique( array_merge( RecordSettings::fieldOptions( $f ), $sel ) ) as $i => $o ) {
						$input .= '<label class="pure-checkbox" style="font-weight:normal"><input type="checkbox" name="' . $name . '[]" value="' . self::h( $o ) . '"' . ( in_array( $o, $sel, true ) ? ' checked' : '' ) . '> ' . self::h( $o ) . '</label>';
					}
					break;
				case 'date':
					$input = '<input type="date" id="cf_' . $id . '" name="' . $name . '" value="' . self::h( $val ) . '"' . $reqA . '>';
					break;
				case 'number':
					$input = '<input type="number" step="any" id="cf_' . $id . '" name="' . $name . '" value="' . self::h( $val ) . '"' . $reqA . '>';
					break;
				case 'money':
					$input = '<span>' . self::h( $f['currency'] ) . '</span> <input type="number" step="0.01" min="0" id="cf_' . $id . '" name="' . $name . '" value="' . self::h( $val ) . '"' . $reqA . '>';
					break;
				case 'url':
					$input = '<input type="url" id="cf_' . $id . '" name="' . $name . '" class="pure-input-1" placeholder="https://" value="' . self::h( $val ) . '"' . $reqA . '>';
					break;
				case 'file':
					$input = '';
					if ( $val !== '' ) {
						$input .= '<div>' . self::displayValue( $f, $val, false, $titleId ) . ' <label style="font-weight:normal"><input type="checkbox" name="cf_remove[' . $id . ']" value="1"> ' . sp__( "remove" ) . '</label></div>';
					}
					$input .= '<input type="file" id="cf_' . $id . '" name="cf_file_' . $id . '"' . ( $req && $val === '' ? ' required' : '' ) . '>';
					break;
				default:
					$input = '<input type="text" id="cf_' . $id . '" name="' . $name . '" class="pure-input-1" value="' . self::h( $val ) . '"' . $reqA . '>';
			}
			$html .= '<div class="cf-field" style="margin-bottom:.8em">' . $label . $input . $help . '</div>';
		}

		return $html;
	}

	/**
	 * Save posted values for a record.
	 * @return string[] error messages (empty when everything was saved)
	 */
	public static function save( $titleId, array $post, array $files ) {
		$titleId = (int) $titleId;
		$fields  = RecordSettings::fields();
		if ( ! $titleId || ! $fields ) {
			return array();
		}
		$c      = self::conn();
		$old    = self::values( $titleId );
		$posted = isset( $post['cf'] ) && is_array( $post['cf'] ) ? $post['cf'] : array();
		$errors = array();
		$set    = $c->prepare( "INSERT INTO record_field_value (title_id, field_id, value) VALUES (?,?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)" );
		$del    = $c->prepare( "DELETE FROM record_field_value WHERE title_id = ? AND field_id = ?" );

		foreach ( $fields as $f ) {
			$id = (int) $f['field_id'];
			if ( $f['type'] === 'file' ) {
				$value = $old[ $id ] ?? '';
				if ( ! empty( $post['cf_remove'][ $id ] ) && $value !== '' ) {
					@unlink( self::storageDir( $titleId ) . DIRECTORY_SEPARATOR . basename( $value ) );
					$value = '';
				}
				$up = $files[ 'cf_file_' . $id ] ?? null;
				if ( $up && ( $up['error'] ?? UPLOAD_ERR_NO_FILE ) === UPLOAD_ERR_OK ) {
					$res = self::storeUpload( $titleId, $up );
					if ( is_array( $res ) ) {
						if ( $value !== '' ) {
							@unlink( self::storageDir( $titleId ) . DIRECTORY_SEPARATOR . basename( $value ) );
						}
						$value = $res['stored'];
					} else {
						$errors[] = $f['label'] . ': ' . $res;
					}
				} elseif ( $up && ( $up['error'] ?? 0 ) !== UPLOAD_ERR_NO_FILE ) {
					$errors[] = $f['label'] . ': ' . sp__( "the file could not be uploaded (too large?)." );
				}
			} else {
				if ( ! array_key_exists( $id, $posted ) ) {
					continue; // field was not on the form (e.g. added since the page was opened)
				}
				$raw   = $posted[ $id ];
				$value = self::clean( $f, $raw, $err );
				if ( $err ) {
					$errors[] = $f['label'] . ': ' . $err;
					continue;
				}
			}
			if ( (int) $f['required'] === 1 && ( $value === '' || $value === '[]' ) ) {
				$errors[] = sprintf( sp__( "%s is required." ), $f['label'] );
			}
			if ( $value === '' || $value === '[]' ) {
				$del->execute( array( $titleId, $id ) );
			} else {
				$set->execute( array( $titleId, $id, $value ) );
			}
		}
		self::$publicCache = null;

		return $errors;
	}

	/** Validate and normalise one value. */
	public static function clean( array $f, $raw, &$err = null ) {
		$err = null;
		if ( $f['type'] === 'checkboxes' ) {
			$allowed = RecordSettings::fieldOptions( $f );
			$vals    = array();
			foreach ( (array) $raw as $v ) {
				$v = trim( (string) $v );
				if ( $v !== '' && in_array( $v, $allowed, true ) ) {
					$vals[] = $v;
				}
			}

			return $vals ? json_encode( array_values( array_unique( $vals ) ), JSON_UNESCAPED_UNICODE ) : '';
		}
		$v = trim( is_array( $raw ) ? '' : (string) $raw );
		if ( $v === '' ) {
			return '';
		}
		switch ( $f['type'] ) {
			case 'date':
				if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) || ! strtotime( $v ) ) {
					$err = sp__( "please enter a valid date." );
				}
				break;
			case 'number':
			case 'money':
				if ( ! is_numeric( $v ) ) {
					$err = sp__( "please enter a number." );
				}
				break;
			case 'url':
				if ( ! preg_match( '#^https?://[^\s<>"]+$#i', $v ) ) {
					$err = sp__( "please enter a web address starting with http:// or https://." );
				}
				break;
			case 'select':
				if ( ! in_array( $v, RecordSettings::fieldOptions( $f ), true ) ) {
					$err = sp__( "please pick a value from the list." );
				}
				break;
			case 'text':
				$v = mb_substr( strip_tags( $v ), 0, 1000 );
				break;
			case 'textarea':
				$v = mb_substr( strip_tags( $v ), 0, 20000 );
				break;
		}

		return $err ? '' : $v;
	}

	/** @return array ['stored' => name] or an error message */
	private static function storeUpload( $titleId, array $up ) {
		global $upload_whitelist;
		$allowed = array_map( 'strtolower', (array) ( $upload_whitelist ?: array( 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt', 'png', 'jpg', 'jpeg' ) ) );
		$orig    = basename( (string) $up['name'] );
		$ext     = strtolower( pathinfo( $orig, PATHINFO_EXTENSION ) );
		if ( $ext === '' || ! in_array( $ext, $allowed, true ) || in_array( $ext, array( 'php', 'phtml', 'phar', 'html', 'htm', 'svg', 'js' ), true ) ) {
			return sprintf( sp__( "files of type .%s are not allowed." ), $ext );
		}
		if ( ! is_uploaded_file( $up['tmp_name'] ) && PHP_SAPI !== 'cli' ) {
			return sp__( "upload failed." );
		}
		$safe = preg_replace( '/[^A-Za-z0-9._-]+/', '_', $orig );
		$safe = trim( substr( $safe, -120 ), '._' ) ?: 'file.' . $ext;
		$name = bin2hex( random_bytes( 8 ) ) . '__' . $safe;
		$dir  = self::storageDir( $titleId );
		if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0755, true ) ) {
			return sp__( "the upload folder could not be created." );
		}
		$ok = PHP_SAPI === 'cli' ? @rename( $up['tmp_name'], $dir . DIRECTORY_SEPARATOR . $name ) : @move_uploaded_file( $up['tmp_name'], $dir . DIRECTORY_SEPARATOR . $name );

		return $ok ? array( 'stored' => $name ) : sp__( "the file could not be saved." );
	}

	/** Public custom fields of a record as HTML (for the A–Z "more information" area). */
	public static function publicHtml( $titleId ) {
		if ( self::$publicCache === null ) {
			self::$publicCache = array();
			try {
				$rows = self::conn()->query( "SELECT v.title_id, f.* , v.value FROM record_field_value v JOIN record_field f ON f.field_id = v.field_id
					WHERE f.active = 1 AND f.is_public = 1 ORDER BY f.sort_order, f.label" );
				foreach ( $rows ? $rows->fetchAll( \PDO::FETCH_ASSOC ) : array() as $r ) {
					self::$publicCache[ (int) $r['title_id'] ][] = $r;
				}
			} catch ( \Throwable $e ) {
				// tables not created yet
			}
		}
		$out = '';
		foreach ( self::$publicCache[ (int) $titleId ] ?? array() as $r ) {
			$v = self::displayValue( $r, $r['value'], true, $titleId );
			if ( $v !== '' ) {
				$out .= '<div class="db-field"><strong>' . self::h( $r['label'] ) . ':</strong> ' . $v . '</div>';
			}
		}

		return $out;
	}
}
