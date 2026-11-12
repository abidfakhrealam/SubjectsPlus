<?php
/**
 * Created by IntelliJ IDEA.
 * User: cbrownroberts
 * Date: 2019-02-13
 * Time: 14:45
 */

namespace SubjectsPlus\Control\Guide;

use PDO;
use SubjectsPlus\Control\Querier;
use SubjectsPlus\Control\Interfaces\OutputInterface;

#[\AllowDynamicProperties] // PHP 8.2+ compatibility
class SectionService implements OutputInterface {

	private $_db;
	private $_connection;
	public $section_ids;
	public $last_insert;


	/**
	 * the section layouts offered in the guide editor. A layout is three column widths in
	 * twelfths (left-main-right) that add up to 12; 0 hides a column. Any other valid combination
	 * can be entered as a custom layout (see normaliseLayout()).
	 */
	const LAYOUTS = array(
		'One column'    => array( '0-12-0' => 'Full width' ),
		'Two columns'   => array(
			'6-6-0' => 'Two halves (½ + ½)',
			'8-4-0' => 'Main + sidebar (⅔ + ⅓)',
			'4-8-0' => 'Sidebar + main (⅓ + ⅔)',
			'9-3-0' => 'Main + narrow sidebar (¾ + ¼)',
			'3-9-0' => 'Narrow sidebar + main (¼ + ¾)',
		),
		'Three columns' => array(
			'4-4-4' => 'Three thirds (⅓ + ⅓ + ⅓)',
			'3-6-3' => 'Two sidebars (¼ + ½ + ¼)',
			'2-8-2' => 'Two narrow sidebars (⅙ + ⅔ + ⅙)',
			'6-3-3' => 'Wide left (½ + ¼ + ¼)',
			'3-3-6' => 'Wide right (¼ + ¼ + ½)',
		),
	);

	/**
	 * returns the layout as "a-b-c" if valid, otherwise null. Valid: three whole numbers
	 * 0–12 adding up to 12, the main (middle) column at least 2, any other column 0 or at least 2.
	 */
	public static function normaliseLayout( $layout ) {
		if ( ! is_string( $layout ) || ! preg_match( '/^\s*(\d{1,2})-(\d{1,2})-(\d{1,2})\s*$/', $layout, $m ) ) {
			return null;
		}
		$w = array( (int) $m[1], (int) $m[2], (int) $m[3] );
		if ( array_sum( $w ) !== 12 || $w[1] < 2 ) {
			return null;
		}
		foreach ( $w as $x ) {
			if ( $x === 1 || $x > 12 ) {
				return null;
			}
		}
		return implode( '-', $w );
	}

	/** label for a layout (built-in name, or "Custom (a-b-c)"). */
	public static function layoutLabel( $layout ) {
		foreach ( self::LAYOUTS as $group ) {
			if ( isset( $group[ $layout ] ) ) {
				return $group[ $layout ];
			}
		}
		return 'Custom (' . $layout . ')';
	}

	public function __construct(Querier $db) {
		$this->_db = $db;
		$this->_connection = $this->_db->getConnection();
		$this->_connection->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING );
	}

	public function create($section_index, $layout, $tab_id) {
		$layout = self::normaliseLayout( $layout ) ?: '4-4-4';

		$this->_connection->beginTransaction();
		$this->last_insert = null;

		$statement = $this->_connection->prepare("INSERT INTO section (section_index, layout, tab_id) VALUES (:section_index, :layout, :tab_id)");

		$statement->bindParam(':section_index', $section_index);
		$statement->bindParam(':layout', $layout);
		$statement->bindParam(':tab_id', $tab_id);

		$statement->execute();
		$this->last_insert = $this->_connection->lastInsertId();
		$this->_connection->commit();

		return $this->last_insert;
	}


	public function updateSectionLayout($section_id, $layout) {
		// accept only valid layouts (see normaliseLayout), and move boxes out of columns
		// the new layout hides into the main column (as the editor does on screen), so they are
		// not lost from the public page.
		$layout = self::normaliseLayout( $layout );
		if ( $layout === null ) {
			return false;
		}
		$widths = explode( '-', $layout );
		$hidden = array( $layout => array() );
		foreach ( array( 0, 2 ) as $col ) {
			if ( (int) $widths[ $col ] === 0 ) {
				$hidden[ $layout ][] = $col;
			}
		}
		$this->_connection->beginTransaction();
		$statement = $this->_connection->prepare("UPDATE section SET layout = :layout WHERE section_id = :section_id");
		$statement->bindParam(':section_id', $section_id);
		$statement->bindParam(':layout', $layout);
		$statement->execute();

		if ( $hidden[ $layout ] ) {
			$max = $this->_connection->prepare( "SELECT COALESCE(MAX(prow), 0) FROM pluslet_section WHERE section_id = :s AND pcolumn = 1" );
			$max->execute( array( ':s' => $section_id ) );
			$row = (int) $max->fetchColumn();
			$move = $this->_connection->prepare( "UPDATE pluslet_section SET pcolumn = 1, prow = :prow WHERE pluslet_section_id = :id" );
			foreach ( $hidden[ $layout ] as $col ) {
				$list = $this->_connection->prepare( "SELECT pluslet_section_id FROM pluslet_section WHERE section_id = :s AND pcolumn = :c ORDER BY prow" );
				$list->execute( array( ':s' => $section_id, ':c' => $col ) );
				foreach ( $list->fetchAll( \PDO::FETCH_COLUMN ) as $id ) {
					$move->execute( array( ':prow' => ++$row, ':id' => $id ) );
				}
			}
		}
		return $this->_connection->commit();
	}

	public function fetchSectionIdsBySubjectId( $subject_id ) {
		$statement = $this->_connection->prepare( "SELECT * FROM section
    											INNER JOIN tab on section.tab_id = tab.tab_id
											    INNER JOIN subject on tab.subject_id = subject.subject_id
												WHERE tab.subject_id = :subject_id" );
		$statement->bindParam( ":subject_id", $subject_id );
		$statement->execute();
		$section_ids        = $statement->fetchAll();
		$this->section_ids = $section_ids;
	}


	public function toArray() {
		return get_object_vars( $this );
	}

	public function toJSON() {
		return json_encode( get_object_vars( $this ) );
	}

}