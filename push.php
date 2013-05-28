<?php

require_once(dirname(__FILE__) . '/init.php');
$course_copy = course_copy::create();
$master_course_id = required_param('master_course_id', PARAM_INT);
$master_course = get_record('course', 'id', $master_course_id);
$master_id = get_field('block_course_copy_master', 'id', 'course_id', $master_course_id);
$url = new moodle_url(qualified_me());
$url->param('master_course_id', $master_course_id);
$form = new block_course_copy_create_push($url, $master_course_id);
$history_url = new moodle_url("{$CFG->wwwroot}/blocks/course_copy/history/view.php");
$history_url->param('course_id', $master_course_id);
$course_url = new moodle_url("{$CFG->wwwroot}/course/view.php");
$course_url->param('id', $master_course_id);

if($data = $form->get_data()) {
    if($data->pushnow) {
        $data->timeeffective = 0;
    }
    $push = (object) array (
        'master_id' => $master_id,
        'src_course_id' => $master_course_id,
        'course_module_id' => $data->course_module,
        'user_id' => $USER->id,
        'description' => $data->description,
        'timeeffective' => $data->timeeffective,
        'timecreated' => time()
    );

    if(!$push_id = insert_record('block_course_copy_push', $push)) {
        error('Unable to insert into block_course_copy_push.');
    }

    foreach($data->child_course_ids as $child_course_id) {
        $child_id = get_field('block_course_copy_child', 'id', 'course_id', $child_course_id);
        $push_inst = (object) array (
            'push_id' => $push_id,
            'child_id' => $child_id,
            'dest_course_id' => $child_course_id,
            'attempts' => 0,
            'timecompleted' => 0,
            'timecreated' => time(),
            'timemodified' => time()
        );

        if(!insert_record('block_course_copy_push_inst', $push_inst)) {
            delete_records('block_course_copy_push', 'id', $push_id);
            delete_records('block_course_copy_push_inst', 'push_id', $push_id);
            error('Unable to insert push_inst record. Push and push_inst records removed.');
        }
    }

    redirect($history_url->out());
}

$nav = array(
    array(
        'name' => $master_course->fullname,
        'link' => $course_url->out()
    ),
    array(
        'name' => course_copy::str('blockname')
    ),
    array(
        'name' => course_copy::str('createpush')
    )
);

$heading = course_copy::str('createpush');
print_header($heading, $heading, build_navigation($nav));
$form->display();
print_footer();
