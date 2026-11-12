<?php
/**
 * Created by PhpStorm.
 * User: cbrownroberts
 * Date: 10/8/15
 * Time: 4:35 PM
 */

namespace SubjectsPlus\Control;

require_once("Pluslet.php");

#[\AllowDynamicProperties] // PHP 8.2+ compatibility
class Pluslet_GuideSelect extends Pluslet {

    public function __construct($pluslet_id, $flag, $subject_id, $isclone=0) {
        parent::__construct($pluslet_id, $flag, $subject_id, $isclone);

        $this->_type = "GuideSelect";
        $this->_pluslet_bonus_classes = "type-guideselect";

        $this->db = new Querier();
    }

    public function getGuidesByType($type = 'Subject') {
        $connection = $this->db->getConnection();
        $q = "select subject, shortform from subject where active = '1' AND type = '".$type."' order by subject";

        $statement = $connection->prepare($q);
        $statement->execute();
        $r = $statement->fetchAll();

        return $r;
    }


    protected function onEditOutput()
    {


        $this->_body = "<p class=\"faq-alert\">" . sp__("Click 'Save' to view guide type menus.") . "</p>";

    }

    protected function onViewOutput()
    {
        $this->subject_guides = $this->getGuidesByType('Subject');
        $this->topic_guides   = $this->getGuidesByType('Topic');
        $this->course_guides  = $this->getGuidesByType('Course');

        $output = $this->loadHtml(__DIR__ . '/views/GuideSelect.html');

        $this->_body = "$output";
    }

    static function getMenuName()
    {
        return sp__('Guide Select');
    }

    static function getMenuIcon()
    {
        $icon="<i class=\"fa fa-check-square-o\" title=\""  . sp__("Guide Select") . "\" ></i><span class=\"icon-text\">" . sp__("Guide Select") . "</span>";
        return $icon;
    }


}