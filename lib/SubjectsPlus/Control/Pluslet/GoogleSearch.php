<?php
/**
 *   @file GoogleSearch.php
 *   @brief A Google search box
 *   @author little9 (Jamie Little)
 *   @date September 2015
 */
namespace SubjectsPlus\Control;
require_once("Pluslet.php");

#[\AllowDynamicProperties] // PHP 8.2+ compatibility
class Pluslet_GoogleSearch extends Pluslet {

  public function __construct($pluslet_id, $flag, $subject_id, $isclone=0) {
    parent::__construct($pluslet_id, $flag, $subject_id, $isclone);
  
    $this->_type = "GoogleSearch";
    $this->_pluslet_bonus_classes = "type-googlesearch";
  }

  protected function onEditOutput()
  {
  	
    $this->_body = "<p class=\"faq-alert\">" . sp__("Click 'Save' to view your search box.") . "</p>";
   
  }

  protected function onViewOutput()
  {

  $output = $this->loadHtml(__DIR__ . '/views/GoogleSearch.html');
  	
  $this->_body = "$output";

  }

  static function getMenuName()
  {
    return sp__('Google Search');
  }

  static function getMenuIcon()
    {
        $icon="<span class=\"icon-text googlescholar-text\">" . sp__("Google Search") . "</span>";
        return $icon;
    }


}