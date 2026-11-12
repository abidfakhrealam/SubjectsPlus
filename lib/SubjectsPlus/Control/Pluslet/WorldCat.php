<?php
/**
 *   @file WorldCat.php
 *   @brief A worldcat search box
 *   @author little9 (Jamie Little)
 *   @date June 2015
 */
namespace SubjectsPlus\Control;
require_once("Pluslet.php");

#[\AllowDynamicProperties] // PHP 8.2+ compatibility
class Pluslet_WorldCat extends Pluslet {

  public function __construct($pluslet_id, $flag, $subject_id, $isclone=0) {
    parent::__construct($pluslet_id, $flag, $subject_id, $isclone);
  
    $this->_type = "WorldCat";
    $this->_pluslet_bonus_classes = "type-worldcat";
  }

  protected function onEditOutput()
  {
  	
    $this->_body = "<p class=\"faq-alert\">" . sp__("Click 'Save' to view your search box.") . "</p>";
   
  }

  protected function onViewOutput()
  {

  $output = $this->loadHtml(__DIR__ . '/views/WorldCat.php');
  	
  $this->_body = "$output";

  }

  static function getMenuName()
  {
    return sp__('WorldCat Search');
  }

  static function getMenuIcon()
    {
        $icon="<span class=\"icon-text worldcat-text\">" . sp__("WorldCat Search") . "</span>";
        return $icon;
    }


}