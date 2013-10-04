<?php

if (PHP_SAPI !== 'cli') { print "NO!\n"; exit; }
require_once(dirname(dirname(dirname(dirname(__FILE__)))) . '/config.php');
require_once(dirname(dirname(__FILE__)) . '/lib.php');

$longopts = array(
    'function:',
    'cmid:',
    'dest_courseid:',
    'backup_code:',
    'push_id:',
    'push_instance_id:',
);
$opts = (object)getopt(null, $longopts);

$function = $opts->function;
switch($function) {
case 'attempt_push':
    $push = get_record('block_course_copy_push', 'id', $opts->push_id);
    $push_instance = get_record('block_course_copy_push_inst', 'id', $opts->push_instance_id);
    $tmp = course_copy::$function($push, $push_instance);
    print_r($tmp);
    break;
case 'copy_course_module':
    $err = '';
    $tmp = course_copy::$function($opts->cmid, $err);
    print_r($tmp);
    print_r($err);
    break;
case 'restore_course_module':
    $err = '';
    $tmp = course_copy::$function($opts->cmid, $opts->dest_courseid, $opts->backup_code);
    print_r($tmp);
    print_r($err);
    break;
case 'course_copy_schedule_backup_launch_backup':
    $cm = get_record('course_modules', 'id', $opts->cmid);
    $course = get_record('course', 'id', $cm->course);
    $tmp = $function($course, $opts->cmid, time());
    print_r($tmp);
    break;
default:
    print "Unexpected --function: $opts->function\n";
    exit;
}

?>
