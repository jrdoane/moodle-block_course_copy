<?php
require_once(dirname(dirname(dirname(__FILE__))) . '/config.php');
require_once(dirname(__FILE__) . '/lib.php');

require_login(0, false);

$cmid = required_param('cmid', PARAM_INT);
$err = '';

if(!$unique = CourseCopy::backup_course_module($cmid, $err)) {
    print "Unable to get course module to backup.\n";
    print $err;
}

print $unique;

