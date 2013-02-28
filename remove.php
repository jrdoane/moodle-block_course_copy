<?php

/**
 * This file allows you to create masters.
 */
require_once(dirname(__FILE__) . '/init.php');

$course_copy = course_copy::create();
$course_id = required_param('course_id', PARAM_INT);
$course = get_record('course', 'id', $course_id);
$course_url = "{$CFG->wwwroot}/course/view.php?id={$course_id}";
$master = optional_param('master', null, PARAM_INT);
$child = optional_param('child', null, PARAM_INT);

if($master) {
    $confirmation_form = new block_course_copy_confirmation_form(
        qualified_me(),
        course_copy::str('confirmremovalofmasterstatus'),
        course_copy::str('removemaster')
    );
} else {
    $confirmation_form = new block_course_copy_confirmation_form(
        qualified_me(),
        course_copy::str('confirmremovalofchildstatus'),
        course_copy::str('removechild')
    );
}

$cancelled = $confirmation_form->is_cancelled();
$accepted = $confirmation_form->user_accepted();

if($accepted) {
    if($master) {
        $course_copy->remove_master_by_course($course_id); 
    }
    if($child) {
        $course_copy->remove_child_by_course($course_id);
    }
}

if($accepted or $cancelled) {
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
        'name' => course_copy::str('removemaster')
    )
);

$heading = course_copy::str('removemaster');

print_header($heading, $heading, build_navigation($nav));
$confirmation_form->display();
print_footer();
