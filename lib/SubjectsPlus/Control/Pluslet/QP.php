<?php
   namespace SubjectsPlus\Control;
     require_once("Pluslet.php");
/**
 *   @file sp_Pluslet_QP
 *   @brief 
 *
 *   @author sandbergja
 *   @date Dec 2017
 *   @todo 
 */
#[\AllowDynamicProperties] // PHP 8.2+ compatibility
class Pluslet_QP extends Pluslet {

    public function __construct($pluslet_id, $flag, $subject_id, $isclone=0) {
        parent::__construct($pluslet_id, $flag, $subject_id, $isclone);

        $this->_type = "QP";
        $this->_pluslet_bonus_classes = "type-chat";

        $this->_title = sp__("Chat with a librarian");
        $this->_hide_titlebar = false;
    }
  static function getMenuName()
  {
    return sp__('QuestionPoint Chat');
  }

    public static function getMenuIcon()
    {
        $icon="<i class=\"fa fa-commenting-o\" title=\"" . sp__("QuestionPoint Chat") . "\" ></i><span class=\"icon-text\">" . sp__("QuestionPoint Chat") . "</span>";
        return $icon;
    }
    protected function onViewOutput()
    {
        global $qp_inst_id;
        if( empty($qp_inst_id)) {
                if( strpos($_SERVER['REQUEST_URI'], 'control')) {
                    $flashMessage = "<p>" . sp__("Please have your SubjectsPlus Admin add the settings for your institution's QuestionPoint Chat account.") . "</p>";
                    $flashMessage .= "<p>" . sp__("The file is located at /control/includes/config.php") . "</p>";
                } else {
                    $flashMessage = "<p>" . sp__("The chat feature is not currently available.") . "</p>";
                    $flashMessage .= "<p>" . sp__("Please contact your library directly.") . "</p>";
                }
		$this->_body = $flashMessage;
        } else {
                $this->_inst_id = $qp_inst_id;
                $this->_extra = json_decode( (string) $this->_extra, true );
	        $this->_color = isset($this->_extra['color']) ? $this->_extra['color'] : 'green';
	        $this->_lang_id = isset($this->_extra['language']) ? $this->_extra['language'] : 1;
                $this->_body = $this->loadHtml(__DIR__ . '/views/QPChatViewOutput.php');
        }
    }
    protected function onEditOutput()
    {
        if($this->_extra == "")
        {
            $this->_extra = array();

        }else
        {
            $this->_extra = json_decode( (string) $this->_extra, true );
        }

        $this->_body = $this->loadHtml(__DIR__ . '/views/QPChatEditOutput.php');
    }
}

?>
