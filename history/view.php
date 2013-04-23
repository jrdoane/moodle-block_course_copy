<?php
require_once(dirname(dirname(__FILE__)) . '/lib.php');
$course_copy    = course_copy::create();
$course_id      = required_param('course_id', PARAM_INT);
$page           = optional_param('page', 0, PARAM_INT);
$perpage        = optional_param('perpage', 10, PARAM_INT);
$course         = get_record('course', 'id', $course_id);
$title          = course_copy::str('blockname') . ": $course->fullname: " . course_copy::str('viewhistory');
$course_page_url = new moodle_url("{$CFG->wwwroot}/course/view.php");
$course_page_url->param('id', $course->id);

$nav = array(
    array(
        'name' => $course->fullname,
        'link' => $course_page_url->out()
    ),
    array(
        'name' => course_copy::str('blockname')
    ),
    array(
        'name' => course_copy::str('viewhistory')
    )
);

print_header($title, $course->fullname, build_navigation($nav));

// We're only supposed to get to this page if we have a history but we need to 
// double check anyways instead of carping without end.
if(!$course_copy->course_has_history($course->id)) {
    notify(course_copy::str('thiscoursehasnohistory'));
    print_footer();
    exit();
}

$table = (object)array(
    'head' => array(
        '',
        course_copy::str('sourcecoursemodule'),
        course_copy::str('sourcecourse'),
        course_copy::str('desinationcourses'),
        course_copy::str('status'),
        course_copy::str('issuedby'),
        course_copy::str('timeeffective'),
        course_copy::str('timecreated')

    ),
    'data' => array(),
);
$history = $course_copy->fetch_course_push_history($course->id, $perpage, $page);

$table = array_reduce($history, function(&$working_table, $push) {
    $inst_struct = (object) array(
        'names' => array(),
        'completed' => 0,
        'abandoned' => 0,
        'attempted' => false,
    );
    $idata = array_reduce($push->instances, function(&$instance_data, $inst) {
        // TODO: If there are other pushes that touch the same course, it might 
        // be faster to cache the names. We'll see how performance is then 
        // consider changing this.
        // --jdoane 20130422
        $instance_data->names[] = get_field('course', 'fullname', 'id', $inst->dest_course_id);
        if ($inst->attempts > 0) {
            $instance_data->attempted = true;
            if ($inst->timecompleted > 0) {
                $instance_data->completed++;
            }
        }
        if ($inst->child_id == 0) {
            $instance_data->abandoned++;
        }
        return $instance_data;
    }, clone $inst_struct);

    $source_cm_name = course_copy::get_cm_name($push->course_module_id);
    $source_course_name = get_field('course', 'fullname', 'id', $push->src_course_id);
    $user = get_record('user', 'id', $push->user_id, '', '', '', '', 'id, firstname, lastname');
    $status = course_copy::str('untouched');
    if($idata->attempted) {
        $status = course_copy::str('attempted');
        if(!$push->master_id) {
            $status = course_copy::str('abandoned');
        }
        if($idata->completed == count($push->instances)) {
            $status = course_copy::str('complete');
        }
    }

    global $CFG;
    $url = new moodle_url("$CFG->wwwroot/blocks/course_copy/history/push.php");
    $url->param('id', $push->id);
    $detail_link = "<a href=\"". $url->out() ."\">". course_copy::str('details') ."</a>";

    $working_table->data[] = array(
        $detail_link,
        $source_cm_name,
        $source_course_name,
        implode('<br />', $idata->names),
        $status,
        fullname($user),
        $push->timeeffective == 0 ? course_copy::str('immediately') : userdate($push->timeeffective),
        userdate($push->timecreated)
    );
    return $working_table;
}, clone $table);

print_heading(course_copy::str('blockname'), 'center', 1);
print_heading(course_copy::str('viewhistory') . ': ' . $course->fullname, 'center' , 2);
print_table($table);

print_footer();
