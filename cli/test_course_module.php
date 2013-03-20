<?php

if (PHP_SAPI !== 'cli') { print "NO!\n"; exit; }
require_once(dirname(dirname(dirname(dirname(__FILE__)))) . '/config.php');
require_once(dirname(dirname(__FILE__)) . '/lib.php');

$longopts = array('course_module:');
$opts = (object)getopt(null, $longopts);

if(!isset($opts->course_module)) {
    print "course_module id required.\n";
    exit;
}

$rval = course_copy_requirement_check::check_course_module($opts->course_module);

if($rval->passed()) {
    print "Course module passes the check.\n";
} else {
    print "Course module does not pass the check.\n";
    print $rval->describe();
}
