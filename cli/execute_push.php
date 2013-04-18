<?php

if (PHP_SAPI !== 'cli') { print "NO!\n"; exit; }
require_once(dirname(dirname(dirname(dirname(__FILE__)))) . '/config.php');
require_once(dirname(dirname(__FILE__)) . '/lib.php');

$longopts = array('instance:');
$opts = (object)getopt(null, $longopts);

$inst = get_record('block_course_copy_push_inst', 'id', $opts->instance);
$push = get_record('block_course_copy_push', 'id', $inst->push_id);

// We have to do this to test since the restore code needs a user logged in.
// In the long run we may want to create or designate a user for the restore to 
// run as when a $USER is not available.
global $USER;
$USER = get_record('user', 'username', 'admin');

if(course_copy::attempt_push($push, $inst)) {
    print "Success!\n";
} else {
    print "Failed!\n";
}

