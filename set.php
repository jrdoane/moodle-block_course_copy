<?php

/**
 * This file allows you to create masters.
 */
require_once(dirname(__FILE__) . '/init.php');

$course_copy = course_copy::create();
$course_id = required_param('course_id', PARAM_INT);
$master_id = optional_param('master_id', null, PARAM_INT);
$create_child = optional_param('create_child', null, PARAM_INT);

$page = optional_param('page', 0, PARAM_INT);
$perpage = optional_param('perpage', 10, PARAM_INT);

$course_url = "{$CFG->wwwroot}/course/view.php?id={$course_id}";
$local_url = new moodle_url("{$CFG->wwwroot}/blocks/course_copy/set.php");
$local_url->param('course_id', $course_id);
if($master_id) {
    $local_url->param('master_id', $master_id);
}
if($create_child) {
    $local_url->param('create_child', 1);
}

$course = get_record('course', 'id', $course_id);

if (!$create_child) {
    if ($course_copy->plugin_manages_masters()) {
        error('Plugin manages masters automatically. Adding masters is disabled.');
    }
    $course_copy->add_master($course_id);
    redirect($course_url);
}

if ($course_copy->plugin_manages_children()) {
    error('Plugin manages children automatically. Adding children is disabled.');
}

if($master_id) {
    $course_copy->add_child($course_id, $master_id);
    redirect($course_url);
}

$nav = array(
    array(
        'name' => $course->fullname,
        'link' => $course_url
    ),
    array(
        'name' => course_copy::str('blockname')
    ),
    array(
        'name' => course_copy::str('addachildcourse')
    )
);

$heading = course_copy::str('addachildcourse');
print_header($heading, $heading, build_navigation($nav));

// From this point on we can assume that we have all the parts we need to make 
// a course a child. Setting a master will have been redirected by now.


if (!$course_copy->can_be_child($course_id)) {
    error('This course can not be a child.');
}

$possible_courses = $course_copy->get_masters($page, $perpage);

if (empty($possible_courses)) {
    error('Cannot list master courses since none exist.');
}

$table = new stdClass;
$table->head = array(course_copy::str('chooseamastercourse'));
$table->data = array();

foreach($possible_courses as $c) {
    $local_url->param('master_id', $c->master_id);
    $anchor = "<a href=\"".$local_url->out()."\">{$c->fullname}</a>";
    $table->data[] = array($anchor);
}

print_heading(course_copy::str('addachildcourse'), 'center', 1);
print_heading($course->fullname, 'center', 4);

print_table($table);

print_footer();
