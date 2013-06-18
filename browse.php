<?php

require_once(dirname(__FILE__) . '/init.php');
$course_id = required_param('course_id', PARAM_INT);
$course = get_record('course', 'id', $course_id);
$course_url = "{$CFG->wwwroot}/course/view.php?id={$course_id}";
$heading_str = course_copy::str('pendingpushes');

$nav = array(
    array(
        'name' => $course->fullname,
        'link' => $course_url
    ),
    array(
        'name' => course_copy::str('blockname')
    ),
    array(
        'name' => $heading_str
    )
);

$is_master = course_copy::is_master($course_id);
$is_child = course_copy::is_child($course_id);

if($is_master and $is_child) {
    error("Course appears to be both a master and child. Impossible.");
}

if($is_master) {
    $count = course_copy::count_pending_pushes_by_master_course($course_id);
    $pushes = course_copy::fetch_pending_pushes_by_master_course($course_id);
} else {
    $count = course_copy::count_pending_pushes_by_child_course($course_id);
    $pushes = course_copy::fetch_pending_pushes_by_child_course($course_id);
}

print_header($heading_str, $heading_str, build_navigation($nav));

$table = new stdClass;
$table->head = array(
    course_copy::str('sourcecourse'),
    course_copy::str('sourcecoursemodule'),
    course_copy::str('destinationcourses'),
    course_copy::str('pushcreatedon'),
    course_copy::str('takeseffecton'),
    course_copy::str('waitingoncourses')
);

foreach($pushes as $p) {
    $waiting_list = '<ul>';
    $course_list_str = '<ul>';
    $has_waiting = false;
    $instances = course_copy::fetch_pending_push_instances($p->id, $is_child ? $course_id : null);

    foreach($instances as $i) {
        $course_name = get_field('course', 'fullname', 'id', $i->dest_course_id);
        $course_list_str .= "<li>{$course_name}</li>";
        $checker = course_copy::check_requirements($p, $i);
        if(!$checker) {
            // No checker object means there is no matching cm in the 
            // destination course. It's good to go.
            continue;
        }
        if($checker->passed()) {
            continue;
        }

        // If we get a checker and it failed, we're held up on this instance.
        $waiting_list .= "<li>{$course_name}</li>"; 
        $has_waiting = true;
    }

    if(!$has_waiting) {
        $waiting_list = course_copy::str('allcoursessatisfyrequirements');
    }

    $row = array(
        get_field('course', 'fullname', 'id', $p->src_course_id),
        course_copy::get_cm_name($p->course_module_id),
        $course_list_str,
        $p->timecreated, // Turn this into something human readable.
        $p->timeeffective,
        $waiting_list
    );

    $table->data[] = $row;
}

print_table($table);

print_footer();
