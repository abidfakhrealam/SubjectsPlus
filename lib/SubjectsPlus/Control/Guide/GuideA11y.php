<?php
namespace SubjectsPlus\Control\Guide;

use SubjectsPlus\Control\Querier;

/**
 * accessibility check of guide boxes (WCAG 2.1 AA basics), in plain words.
 * Checks: images without alternative text; link text that says nothing on its own ("click here");
 * empty links; skipped heading levels; tables without header cells; text colour with too little
 * contrast against white; embedded frames without a title; very long text in capitals.
 */
class GuideA11y {

	const VAGUE = array( 'click here', 'here', 'click', 'more', 'read more', 'more info', 'more information', 'link', 'this link', 'this', 'go', 'details', 'learn more', 'website', 'url' );

	private static function txt( $s ) {
		return html_entity_decode( (string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	/** Problems in one box's HTML: list of ['rule' => ..., 'message' => ..., 'snippet' => ...]. */
	public static function checkHtml( $html ) {
		$html = (string) $html;
		if ( trim( $html ) === '' || stripos( $html, '<' ) === false ) {
			return array();
		}
		$doc  = new \DOMDocument();
		$prev = libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="UTF-8"><div>' . $html . '</div>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		$out  = array();
		$snip = function ( \DOMNode $n ) use ( $doc ) {
			$s = trim( preg_replace( '/\s+/', ' ', $doc->saveHTML( $n ) ) );

			return mb_strlen( $s ) > 140 ? mb_substr( $s, 0, 137 ) . '…' : $s;
		};

		foreach ( $doc->getElementsByTagName( 'img' ) as $img ) {
			if ( ! $img->hasAttribute( 'alt' ) ) {
				$out[] = array( 'rule' => 'img-alt', 'message' => _( "Image without alternative text. Describe the image in the Image Properties → Alternative Text box (or leave it empty only if the image is decoration)." ), 'snippet' => $snip( $img ) );
			}
		}
		foreach ( $doc->getElementsByTagName( 'a' ) as $a ) {
			if ( ! $a->hasAttribute( 'href' ) ) {
				continue;
			}
			$text = trim( preg_replace( '/\s+/', ' ', $a->textContent ) );
			$alts = '';
			foreach ( $a->getElementsByTagName( 'img' ) as $i ) {
				$alts .= trim( $i->getAttribute( 'alt' ) );
			}
			$label = trim( $a->getAttribute( 'aria-label' ) . $a->getAttribute( 'title' ) );
			if ( $text === '' && $alts === '' && $label === '' ) {
				$out[] = array( 'rule' => 'link-empty', 'message' => _( "Link with no text: screen-reader users hear only the address. Add link text, or alternative text to the linked image." ), 'snippet' => $snip( $a ) );
			} elseif ( in_array( mb_strtolower( trim( $text, " .:!»›>-" ) ), self::VAGUE, true ) ) {
				$out[] = array( 'rule' => 'link-vague', 'message' => sprintf( _( "Link text \"%s\" does not say where it goes. Use the name of the destination, e.g. \"JSTOR\" or \"Off-campus access guide\"." ), $text ), 'snippet' => $snip( $a ) );
			} elseif ( preg_match( '#^https?://#i', $text ) && mb_strlen( $text ) > 40 ) {
				$out[] = array( 'rule' => 'link-url', 'message' => _( "A long web address is used as link text. Use a short description instead; it is easier to read and to listen to." ), 'snippet' => $snip( $a ) );
			}
		}
		$last = 0;
		$xp   = new \DOMXPath( $doc );
		foreach ( $xp->query( '//h1|//h2|//h3|//h4|//h5|//h6' ) as $h ) {
			$lvl = (int) substr( $h->nodeName, 1 );
			if ( $lvl === 1 ) {
				$out[] = array( 'rule' => 'heading-h1', 'message' => _( "Heading 1 inside a box. The guide title is the page's heading 1; start boxes at heading 3 (box titles are heading 2)." ), 'snippet' => $snip( $h ) );
			} elseif ( $last && $lvl > $last + 1 ) {
				$out[] = array( 'rule' => 'heading-skip', 'message' => sprintf( _( "Heading levels skip from %1\$d to %2\$d. Use the next level down so the outline makes sense to screen readers." ), $last, $lvl ), 'snippet' => $snip( $h ) );
			}
			if ( trim( $h->textContent ) === '' ) {
				$out[] = array( 'rule' => 'heading-empty', 'message' => _( "Empty heading. Remove it or add text." ), 'snippet' => $snip( $h ) );
			}
			$last = $lvl;
		}
		foreach ( $doc->getElementsByTagName( 'table' ) as $t ) {
			if ( $t->getElementsByTagName( 'th' )->length === 0 && $t->getElementsByTagName( 'tr' )->length > 1 ) {
				$out[] = array( 'rule' => 'table-headers', 'message' => _( "Table without header cells. In Table Properties choose Headers: First row (or First column), unless the table is only for layout." ), 'snippet' => $snip( $t ) );
			}
		}
		foreach ( $doc->getElementsByTagName( 'iframe' ) as $f ) {
			if ( trim( $f->getAttribute( 'title' ) ) === '' ) {
				$out[] = array( 'rule' => 'iframe-title', 'message' => _( "Embedded content (video, map, form) without a title. Add a title attribute describing it." ), 'snippet' => $snip( $f ) );
			}
		}
		foreach ( $xp->query( '//*[@style]' ) as $el ) {
			$style = strtolower( $el->getAttribute( 'style' ) );
			if ( preg_match( '/(?:^|;)\s*color\s*:\s*([^;]+)/', $style, $m ) ) {
				$fg = self::rgb( $m[1] );
				$bg = preg_match( '/background(?:-color)?\s*:\s*([^;]+)/', $style, $b ) ? self::rgb( $b[1] ) : array( 255, 255, 255 );
				if ( $fg && $bg && trim( $el->textContent ) !== '' ) {
					$ratio = self::contrast( $fg, $bg );
					if ( $ratio < 4.5 ) {
						$out[] = array( 'rule' => 'contrast', 'message' => sprintf( _( "Text colour has too little contrast (%.1f:1; at least 4.5:1 is needed). Use a darker colour." ), $ratio ), 'snippet' => $snip( $el ) );
					}
				}
			}
		}
		foreach ( $xp->query( '//p|//li|//td' ) as $p ) {
			$t = trim( $p->textContent );
			if ( mb_strlen( $t ) > 40 && $t === mb_strtoupper( $t ) && preg_match( '/[A-Z]{20}/', preg_replace( '/[^A-Z]/', '', $t ) ) ) {
				$out[] = array( 'rule' => 'all-caps', 'message' => _( "Long text in capital letters is hard to read. Use normal case (and bold for emphasis)." ), 'snippet' => $snip( $p ) );
			}
		}

		return $out;
	}

	private static function rgb( $c ) {
		$c     = trim( strtolower( $c ) );
		$named = array( 'white' => 'ffffff', 'black' => '000000', 'red' => 'ff0000', 'green' => '008000', 'blue' => '0000ff', 'yellow' => 'ffff00', 'orange' => 'ffa500',
		                'gray' => '808080', 'grey' => '808080', 'silver' => 'c0c0c0', 'lightgray' => 'd3d3d3', 'lightgrey' => 'd3d3d3', 'aqua' => '00ffff', 'lime' => '00ff00',
		                'fuchsia' => 'ff00ff', 'pink' => 'ffc0cb', 'gold' => 'ffd700', 'navy' => '000080', 'maroon' => '800000', 'purple' => '800080', 'teal' => '008080' );
		if ( isset( $named[ $c ] ) ) {
			$c = '#' . $named[ $c ];
		}
		if ( preg_match( '/^#([0-9a-f]{3})$/', $c, $m ) ) {
			$c = '#' . $m[1][0] . $m[1][0] . $m[1][1] . $m[1][1] . $m[1][2] . $m[1][2];
		}
		if ( preg_match( '/^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/', $c, $m ) ) {
			return array( hexdec( $m[1] ), hexdec( $m[2] ), hexdec( $m[3] ) );
		}
		if ( preg_match( '/^rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)/', $c, $m ) ) {
			return array( (int) $m[1], (int) $m[2], (int) $m[3] );
		}

		return null;
	}

	public static function contrast( array $a, array $b ) {
		$l = function ( array $c ) {
			$v = array_map( function ( $x ) {
				$x /= 255;

				return $x <= 0.03928 ? $x / 12.92 : pow( ( $x + 0.055 ) / 1.055, 2.4 );
			}, $c );

			return 0.2126 * $v[0] + 0.7152 * $v[1] + 0.0722 * $v[2];
		};
		$x = $l( $a );
		$y = $l( $b );

		return ( max( $x, $y ) + 0.05 ) / ( min( $x, $y ) + 0.05 );
	}

	/** Problems in one guide (or all guides when $subjectId is null), with guide/tab/box. */
	public static function checkGuides( $subjectId = null ) {
		$c   = ( new Querier )->getConnection();
		$sql = "SELECT su.subject_id, su.subject, t.label, t.tab_index, p.pluslet_id, p.title, p.body FROM pluslet p
			JOIN pluslet_section ps ON ps.pluslet_id = p.pluslet_id JOIN section s ON s.section_id = ps.section_id
			JOIN tab t ON t.tab_id = s.tab_id JOIN subject su ON su.subject_id = t.subject_id"
			. ( $subjectId ? " WHERE su.subject_id = " . (int) $subjectId : "" ) . "
			ORDER BY su.subject, t.tab_index, s.section_index, ps.pcolumn, ps.prow";
		$out = array();
		foreach ( $c->query( $sql )->fetchAll( \PDO::FETCH_ASSOC ) as $r ) {
			foreach ( self::checkHtml( $r['body'] ) as $p ) {
				$out[] = $p + array( 'subject_id' => (int) $r['subject_id'], 'guide' => self::txt( $r['subject'] ), 'tab' => self::txt( $r['label'] ),
				                     'tab_index' => (int) $r['tab_index'], 'pluslet_id' => (int) $r['pluslet_id'], 'box' => self::txt( $r['title'] ) );
			}
		}

		return $out;
	}
}
