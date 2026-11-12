<?php
/**
 *   @file GoogleBooks.php
 *   @brief A Google Books search box
 *   @author little9 (Jamie Little)
 *   @date September 2015
 */
namespace SubjectsPlus\Control;

require_once("Pluslet.php");

#[\AllowDynamicProperties] // PHP 8.2+ compatibility
class Pluslet_GoogleBooks extends Pluslet {

  public function __construct($pluslet_id, $flag, $subject_id, $isclone=0) {
    parent::__construct($pluslet_id, $flag, $subject_id, $isclone);
  
    $this->_type = "GoogleBooks";
    $this->_pluslet_bonus_classes = "type-googlebooks";
  }

  protected function onEditOutput()
  {
  	
    $this->_body = "<p class=\"faq-alert\">" . sp__("Click 'Save' to view your search box.") . "</p>";
   
  }

  protected function onViewOutput()
  {

  $output = $this->loadHtml(__DIR__ . '/views/GoogleBooks.html');
  	
  $this->_body = "$output";

  }

  static function getMenuName()
  {
    return sp__('Google Books');
  }

  static function getMenuIcon()
    {
        $icon="<span class=\"icon-text googlescholar-text\">" . sp__("Google Books") . "</span>";
        return $icon;
    }


}