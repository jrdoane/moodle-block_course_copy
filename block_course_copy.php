<?php
// This file is part of Moodle - http://moodle.org/
require_once(dirname(__FILE__) . '/lib.php');

class block_course_copy extends block_base {
    function init() {
        $plugin = new stdClass;
        require(dirname(__FILE__) . '/version.php');
        $this->title = course_copy::str('blockname');
        $this->version = $plugin->version;
        $this->cron = 1; // Enable this when the time comes.
    }

    function has_config() {
        return true;
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
        $remove_url = new moodle_url("{$base_url}/remove.php");
        $set_url = new moodle_url("{$CFG->wwwroot}/blocks/course_copy/set.php");

        $option_list = array();

        if($COURSE->id != SITEID) {
            if(course_copy::can_user_push($COURSE->id)) {
                $course_copy->ensure_relations($COURSE->id);
                if(course_copy::can_be_master($COURSE->id) and !$course_copy->plugin_manages_masters()) {
                    $set_url->param('master', 1);
                    $set_url->param('course_id', $COURSE->id);
                    $option_list[] = course_copy::make_link(course_copy::str('makethiscourseamaster'), $set_url->out());
                    $set_url->remove_params();
                }

                if(course_copy::can_be_child($COURSE->id) and !$course_copy->plugin_manages_children()) {
                    $set_url->param('create_child', 1);
                    $set_url->param('course_id', $COURSE->id);
                    $option_list[] = course_copy::make_link(course_copy::str('makethiscourseachild'), $set_url->out());
                    $set_url->remove_params();
                }

                if(course_copy::is_master($COURSE->id)) {
                    $this->content->text .= course_copy::str('thiscourseisamaster') . '<br /><br />';

                    if(course_copy::master_has_children_by_course($COURSE->id)) {
                        $url = new moodle_url("{$base_url}/push.php");
                        $url->param('master_course_id', $COURSE->id);
                        $option_list[] = course_copy::make_link(course_copy::str('pushcoursemodule'), $url->out());
                    } else {
                        $this->content->text .= course_copy::str('masterhasnochildren') .'<br /><br />';
                    }

                    if(!$course_copy->plugin_manages_masters()) {
                        $remove_url->param('master', 1);
                        $remove_url->param('course_id', $COURSE->id);
                        $option_list[] = course_copy::make_link(course_copy::str('relinquishmasterstatus'), $remove_url->out());
                        $remove_url->remove_params();
                    }
                }

                if(course_copy::is_child($COURSE->id)) {
                    $master = course_copy::get_master_course_by_child_course_id($COURSE->id);
                    $this->content->text .= course_copy::str('thiscourseisachildof') . " {$master->fullname}<br /><br />";

                    if(!$course_copy->plugin_manages_children()) {
                        $remove_url->param('child', 1);
                        $remove_url->param('course_id', $COURSE->id);
                        $option_list[] = course_copy::make_link(course_copy::str('relinquishchildstatus'), $url->out());
                        $remove_url->remove_params();
                    }
                }
            }

            if(course_copy::can_view_history($COURSE->id)) {
                if (course_copy::course_has_history($COURSE->id)) {
                    $url = new moodle_url("{$base_url}/history/view.php");
                    $url->param('course_id', $COURSE->id);
                    $option_list[] = course_copy::make_link(course_copy::str('viewhistory'), $url->out());
                }
            }
        }

        if(!empty($option_list)) {
            $this->content->text .= "<ul>";
            foreach($option_list as $a) {
                $this->content->text .= "<li>$a</li>";
            }
            $this->content->text .= "</ul>";
        }
        return $this->content;
    }
}

