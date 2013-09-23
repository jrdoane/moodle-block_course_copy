<?php
require_once(dirname(dirname(__FILE__)) . '/lib.php');

$push_id = required_param('id', PARAM_INT);
$course_id = optional_param('course_id', null, PARAM_INT);

$title = course_copy::str('pushhistory');
$course_name = $title;

$push = get_record('block_course_copy_push', 'id', $push_id);
$push->instances = get_records('block_course_copy_push_inst', 'push_id', $push_id, 'dest_course_id ASC');

$impacted_classrooms = array($push->src_course_id);
array_reduce($push->instances, function(&$working, $item) {
    $working[] = $item->dest_course_id;
}, $impacted_classrooms);

if(!course_copy::can_view_history($push->src_course_id)) {
    error("User does not have access to view push history for at least one course.");
}

$nav = array(
    array(
        'name' => course_copy::str('blockname')
    )
);

$hist_url = new moodle_url("$CFG->wwwroot/blocks/course_copy/history/view.php");
$hist_nav = array(
    'name' => course_copy::str('viewhistory')
);

if($course_id) {
    $course = get_record('course', 'id', $course_id);
    $course_name = $course->fullname;
    $course_page_url = new moodle_url("$CFG->wwwroot/course/view.php");
    $course_page_url->param('id', $course_id);
    $course_nav = array(
        'name' => $course->fullname,
        'link' => $course_page_url->out()
    );
    array_unshift($nav, $course_nav);
    $hist_url->param('course_id', $course_id);
    $hist_nav['link'] = $hist_url->out();
}
$nav[] = $hist_nav;
$nav[] = array('name' => course_copy::str('details'));

print_header($title, $course_name, build_navigation($nav));

$master_course_name = get_field('course', 'fullname', 'id', $push->src_course_id);
$course_module_name = course_copy::get_cached_cm_instance($push->course_module_id, 'name');
$timeeffective = $push->timeeffective == 0 ? course_copy::str('immediately') : userdate($push->timeeffective);
$timecreated = userdate($push->timecreated);
$is_done = get_string(course_copy::is_push_done($push, $push->instances) ? 'yes' : 'no');

$push_table = new stdClass;
$push_table->head = array(course_copy::str('pushdetails'), '');
$push_table->data = array(
    array(course_copy::str('pushid'), $push->id),
    array(course_copy::str('mastercourse'), course_copy::user_course_link($push->src_course_id)),
    array(course_copy::str('coursemodulename'), $course_module_name),
    array(course_copy::str('issuedby'), course_copy::user_profile_link($push->user_id)),
    array(course_copy::str('descriptionforpush'), $push->description),
    array(course_copy::str('timeeffective'), $timeeffective),
    array(course_copy::str('timecreated'), $timecreated),
    array(course_copy::str('isdone?'), $is_done)
);

$instance_table = new stdClass;
$instance_table->head = array(
    course_copy::str('childcourse'),
    course_copy::str('attemptsmade'),
    course_copy::str('status'),
    course_copy::str('lastattempt')
);

$instance_table->data = array();
foreach($push->instances as $i) {
    $status = course_copy::str('untouched');
    if ($i->isprocessing) {
        $status = course_copy::str('locked');
    } else if($i->timecompleted > 0) {
        $status = course_copy::str('complete');
    } else if(!$push->master_id or !$i->child_id) {
        $status = course_copy::str('abandoned');
    } else if ($i->attempts > 0) {
        $status = course_copy::str('attempted');
    }

    if($push->master_id and $i->child_id and $i->timecompleted == 0) {
        $abandon_push_url = new moodle_url("$CFG->wwwroot/blocks/course_copy/abandon.php");
        $abandon_push_url->param('push_inst_id', $i->id);
        $abandon_push_url->param('return', qualified_me());
        $str = course_copy::str('abandonthispush');
        $status .= "<br /><a href=\"" . $abandon_push_url->out() . "\">{$str}</a>";
    }

    $child_course_name = get_field('course', 'fullname', 'id', $i->dest_course_id);
    if($i->attempts == 0) {
        $lastattempt = course_copy::str('noattempthasbeenmade');
    } else {
        $lastattempt = userdate($i->timemodified);
    }
    $instance_table->data[] = array(
        course_copy::user_course_link($i->dest_course_id),
        $i->attempts,
        $status,
        $lastattempt
    );
}

print_heading(course_copy::str('pushhistorydetails'));
print_table($push_table);
print_table($instance_table);

print_footer();


