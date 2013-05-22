<?php

require_once(dirname(__FILE__) . '/init.php');
$push_id = optional_param('push_id', null, PARAM_INT);
$push_inst_id = optional_param('push_inst_id', null, PARAM_INT);
$return_url = required_param('return', PARAM_RAW);

if(!$push_id and !$push_inst_id) {
    // Needs one or the other
    error("Must provide a push_id or a push_inst_id.");
}

if($push_id) {
    $updating = 'push';
    $rval = course_copy::abandon_push($push_id);
} else {
    $updating = 'push instance';
    $rval = course_copy::abandon_push_instance($push_inst_id);
}

if(!$rval) {
    error("Failed to abandon the $updating.");
}

redirect($return_url);
