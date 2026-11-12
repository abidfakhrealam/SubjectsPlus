<?php
       namespace SubjectsPlus\Control;
     require_once("Pluslet.php");
/* 
 * To change this template, choose Tools | Templates
 * and open the template in the editor.
 */

#[\AllowDynamicProperties] // PHP 8.2+ compatibility
class Pluslet_Reserved_for_Special extends Pluslet {

    public function output($action="", $view="public") {
        return;
    }
}
?>