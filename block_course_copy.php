<?php
// This file is part of Moodle - http://moodle.org/
require_once(dirname(__FILE__) . '/lib.php');

class block_course_copy extends block_base {
    function init() {
        $plugin = new stdClass;
        require(dirname(__FILE__) . '/version.php');
        $this->title = course_copy::str('blockname');
        $this->version = $plugin->version;
        $this->cron = 0; // Enable this when the time comes.
    }

    function cron() {
        // Process cron method for whatever course copy plugin is currently 
        // active.
        $course_copy = course_copy::create();
        $course_copy->cron();
    }

    function get_content() {
        global $COURSE, $CFG;
        $this->content = new stdClass;
        $this->content->text = '';
        $this->content->footer = '';
        $course_copy = course_copy::create();
        $base_url = "{$CFG->wwwroot}/blocks/course_copy";

        if($COURSE->id != SITEID) {
            if($course_copy->can_be_master($COURSE->id)) {
                $make_master_str = course_copy::str('makethiscourseamaster');
                $url = new moodle_url("{$CFG->wwwroot}/blocks/course_copy/set.php");
                $url->param('master', 1);
                $url->param('course_id', $COURSE->id);
                $this->content->text .= "<a href=\"".$url->out()."\">{$make_master_str}</a><br />";
            }

            if($course_copy->can_be_child($COURSE->id)) {
                $make_child_str = course_copy::str('makethiscourseachild');
                $url = new moodle_url("{$CFG->wwwroot}/blocks/course_copy/set.php");
                $url->param('create_child', 1);
                $url->param('course_id', $COURSE->id);
                $this->content->text .= "<a href=\"".$url->out()."\">{$make_child_str}</a><br />";
            }

            if($course_copy->is_master($COURSE->id)) {
                $this->content->text .= course_copy::str('thiscourseisamaster') . '<br /><br />';

                if($course_copy->master_has_children_by_course($COURSE->id)) {
                    $url = new moodle_url("{$base_url}/push.php");
                    $url->param('master_course_id', $COURSE->id);
                    $url = $url->out();
                    $str = course_copy::str('pushcoursemodule');
                    $this->content->text .= "<a href=\"{$url}\">{$str}</a><br /><br />";
                } else {
                    $str = course_copy::str('masterhasnochildren');
                    $this->content->text .= "{$str}<br /><br />";
                }

                $url = new moodle_url("{$base_url}/remove.php");
                $url->param('master', 1);
                $url->param('course_id', $COURSE->id);
                $url = $url->out();
                $str = course_copy::str('relinquishmasterstatus');
                $this->content->text .= "<a href=\"{$url}\">{$str}</a>";
            }

            if($course_copy->is_child($COURSE->id)) {
                $master = $course_copy->get_master_course_by_child_course_id($COURSE->id);
                $this->content->text .= course_copy::str('thiscourseisachildof') . " {$master->fullname}<br /><br />";

                $url = new moodle_url("{$base_url}/remove.php");
                $url->param('child', 1);
                $url->param('course_id', $COURSE->id);
                $url = $url->out();
                $str = course_copy::str('relinquishchildstatus');
                $this->content->text .= "<a href=\"{$url}\">{$str}</a>";
            }
        }
        return $this->content;
    }
}

