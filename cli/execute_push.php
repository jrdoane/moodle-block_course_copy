<?php

if (PHP_SAPI !== 'cli') { print "NO!\n"; exit; }
require_once(dirname(dirname(dirname(dirname(__FILE__)))) . '/config.php');
require_once(dirname(dirname(__FILE__)) . '/lib.php');

$longopts = array('instance:');
$opts = (object)getopt(null, $longopts);

$inst = get_record('block_course_copy_push_inst', 'id', $opts->instance);
$push = get_record('block_course_copy_push', 'id', $inst->push_id);

if(course_copy::attempt_push($push, $inst)) {
    print "Success!\n";
} else {
    print "Failed!\n";
}

