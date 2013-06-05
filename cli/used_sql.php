<?php
require dirname(dirname(__FILE__)) . '/lib.php';
$cc = course_copy::create();
$course_id = 12345;
$push_id = 54321;
$sql = array();
$sql[] = $cc->fetch_push_records_sql(false, false, false, true, true);
$sql[] = $cc->fetch_push_records_sql($course_id);
$sql[] = $cc->fetch_push_records_sql($course_id, null, false, false, true, true);
$sql[] = $cc->fetch_push_records_sql(null, $course_id, false, false, true);
$sql[] = $cc->fetch_push_records_sql(null, $course_id, false, false, true, true);
$sql[] = $cc->fetch_push_records_sql($course_id, false, $push_id, true);
$sql[] = $cc->fetch_push_records_sql($course_id, $course_id, false, false, false);
$sql[] = $cc->fetch_push_records_sql(false, false, $d->id, true, false);
$sql[] = $cc->fetch_push_records_sql($course_id, $course_id, false, false, false, true);

print_r($sql);
print "\n\n";
