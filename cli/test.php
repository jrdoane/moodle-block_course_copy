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
    print "push: "; print_r($push); print "\n"; flush();
    $push_instance = get_record('block_course_copy_push_inst', 'id', $opts->push_instance_id);
    print "push_instance: "; print_r($push_instance); print "\n"; flush();
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
default:
    print "Unexpected --function: $opts->function\n";
    exit;
}

?>
