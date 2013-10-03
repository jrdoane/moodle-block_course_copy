<?php
/*
 * This file is a horrible hack-up of what was originally backup_scheduled.php 
 * (I think).
 *
 * Moodle 1.9 backup code is a labyrinth of mutable state and interleaved magic. 
 * This hacklib helps us sort-of avoid a lot of that.
 *
 * Ugh, and hip-hip-hooray for Moodle 2!
 */

require_once(dirname(dirname(dirname(__FILE__))) . "/backup/backuplib.php");
require_once(dirname(dirname(dirname(__FILE__))) . "/backup/lib.php");

#course_copy_schedule_backup_launch_backup($course, time());

//This function executes the ENTIRE backup of a course (passed as parameter)
//using all the scheduled backup preferences
function course_copy_schedule_backup_launch_backup($course, $cmid, $starttime = 0) {
    $preferences = false;
    $status = false;

    mtrace("            Executing backup");
    course_copy_schedule_backup_log($starttime,$course->id,"Start backup course $course->fullname");
    course_copy_schedule_backup_log($starttime,$course->id,"  Phase 1: Checking and counting:");
    $preferences = course_copy_schedule_backup_course_configure($course, $cmid, $starttime);

    course_copy_schedule_backup_log($starttime,$course->id,"  Phase 2: Executing and copying:");
    $status = course_copy_schedule_backup_course_execute($preferences,$starttime);

    if ($status) {
        mtrace("            End backup OK");
        course_copy_schedule_backup_log($starttime,$course->id,"End backup course $course->fullname - OK");
    } else {
        mtrace("            End backup with ERROR");
        course_copy_schedule_backup_log($starttime,$course->id,"End backup course $course->fullname - ERROR!!");
    }

    return $preferences->backup_unique_code;
}

//This function saves to backup_log all the needed process info
//to use it later.  NOTE: If $starttime = 0 no info in saved
function course_copy_schedule_backup_log($starttime,$courseid,$message) {
    print "log: course ($courseid): $message\n"; flush();
    return;
}

//This function returns the next future GMT time to execute the course based in the
//configuration of the scheduled backups
function course_copy_schedule_backup_next_execution ($backup_course,$backup_config,$now,$timezone) {

    $result = -1;

    //Get today's midnight GMT
    $midnight = usergetmidnight($now,$timezone);

    //Get today's day of week (0=Sunday...6=Saturday)
    $date = usergetdate($now,$timezone);
    $dayofweek = $date['wday'];

    //Get number of days (from today) to execute backups
    $scheduled_days = substr($backup_config->backup_sche_weekdays,$dayofweek).
                      $backup_config->backup_sche_weekdays;
    $daysfromtoday = strpos($scheduled_days, "1");

    //If some day has been found
    if ($daysfromtoday !== false) {
        //Calculate distance
        $dist = ($daysfromtoday * 86400) +                     //Days distance
                ($backup_config->backup_sche_hour*3600) +      //Hours distance
                ($backup_config->backup_sche_minute*60);       //Minutes distance
        $result = $midnight + $dist;
    }

    //If that time is past, call the function recursively to obtain the next valid day
    if ($result > 0 && $result < time()) {
        $result = course_copy_schedule_backup_next_execution ($backup_course,$backup_config,$now + 86400,$timezone);
    }

    return $result;
}

function course_copy_backup_name($courseid, $backup_unique_code) {
    return clean_filename("$backup_unique_code-$courseid.zip");
}

function course_copy_backup_dir() {
    global $CFG;
    return "$CFG->dataroot/course_copy_tmp/";
}

function course_copy_backup_path($courseid, $backup_unique_code) {
    global $CFG;
    return course_copy_backup_dir() . course_copy_backup_name($courseid, $backup_unique_code);
}

//This function implements all the needed code to prepare a course
//to be in backup (insert temp info into backup temp tables).
function course_copy_schedule_backup_course_configure($course, $cmid, $starttime = 0) {
    global $CFG;

    course_copy_schedule_backup_log($starttime,$course->id,"    checking parameters");

    $backup_config->backup_sche_modules = 1;
    $backup_config->backup_sche_withuserdata = 0;
    $backup_config->backup_sche_metacourse = 0;
    $backup_config->backup_sche_users = 0;
    $backup_config->backup_sche_logs = 0;
    $backup_config->backup_sche_userfiles = 0;
    $backup_config->backup_sche_coursefiles = 0;
    $backup_config->backup_sche_sitefiles = 0;
    $backup_config->backup_sche_gradebook_history = 0;
    $backup_config->backup_sche_messages = 0;
    $backup_config->backup_sche_blogs = 0;
    $backup_config->backup_sche_active = 0;
    $backup_config->backup_sche_weekdays = "0000000";
    $backup_config->backup_sche_hour = 00;
    $backup_config->backup_sche_minute = 00;

    #if ($allmods = get_records("modules") ) {
    #    foreach ($allmods as $mod) {
    #        $preferences->mods[$mod->name]->backup = false;
    #    }
    #}

    $cm = get_record('course_modules', 'id', $cmid);
    $mod = get_record('modules', 'id', $cm->module);

    $modname = $mod->name;
    $preferences->mods[$mod->name]->backup = true;
    $preferences->mods[$mod->name]->userinfo = false;
    $preferences->mods[$mod->name]->name = $mod->name; /* sigh */
    // now set the instance
    if (empty($preferences->mods[$modname]->instances)) {
        $preferences->mods[$modname]->instances = array(); // avoid warnings
    }
    $preferences->mods[$modname]->instances[$cm->instance]->backup = $preferences->mods[$modname]->backup; /* sigh */
    $preferences->mods[$modname]->instances[$cm->instance]->userinfo = $preferences->mods[$modname]->userinfo; /* sigh */
    // there isn't really a nice way to do this...
    $preferences->mods[$modname]->instances[$cm->instance]->name = get_field($modname,'name','id',$cm->instance); /* sigh */

    // finally, clean all the $preferences->mods[] not having instances. Nothing to backup about them
    foreach ($preferences->mods as $modname => $mod) {
        if (!isset($mod->instances)) {
            unset($preferences->mods[$modname]);
        }
    }

    $preferences->backup_metacourse = $backup_config->backup_sche_metacourse;
    $preferences->backup_users = $backup_config->backup_sche_users;
    $preferences->backup_logs = $backup_config->backup_sche_logs;
    $preferences->backup_user_files = $backup_config->backup_sche_userfiles;
    $preferences->backup_course_files = $backup_config->backup_sche_coursefiles;
    $preferences->backup_site_files = $backup_config->backup_sche_sitefiles;
    $preferences->backup_gradebook_history = $backup_config->backup_sche_gradebook_history;
    $preferences->backup_messages = $backup_config->backup_sche_messages;
    $preferences->backup_blogs = $backup_config->backup_sche_blogs;
    $preferences->backup_course = $course->id;
    $preferences->backup_destination = course_copy_backup_dir();
    mkdir($preferences->backup_destination);

    //Calculate various backup preferences
    course_copy_schedule_backup_log($starttime,$course->id,"    calculating backup name");

    $backup_unique_code = time();
    $preferences->backup_unique_code = $backup_unique_code;

    $preferences->backup_name = course_copy_backup_name($course->id, $backup_unique_code);
    course_copy_schedule_backup_log($starttime,$course->id,"    backup_name: $preferences->backup_name");

    backup_add_static_preferences($preferences);

    $modbackup = $modname."_backup_mods";
    $modcheckbackup = $modname."_check_backup_mods";
    $modfile = "$CFG->dirroot/mod/$modname/backuplib.php";
    if (file_exists($modfile)) {
        include_once($modfile);
    }
    $modcheckbackup($course->id, false, $backup_unique_code);

    //Calculate necesary info to backup modules
    course_copy_schedule_backup_log($starttime,$course->id,"    calculating modules data");

    return $preferences;
}

//TODO: Unify this function with backup_execute() to have both backups 100% equivalent. Moodle 2.0

//This function implements all the needed code to backup a course
//copying it to the desired destination (default if not specified)
function course_copy_schedule_backup_course_execute($preferences,$starttime = 0) {
    global $CFG;

    $status = true;

    //Some parts of the backup doesn't know about $preferences, so we
    //put a copy of it inside that CFG (always global) to be able to
    //use it. Then, when needed I search for preferences inside CFG
    //Used to avoid some problems in full_tag() when preferences isn't
    //set globally (i.e. in scheduled backups)
    $CFG->backup_preferences = $preferences;

    //Check for temp and backup and backup_unique_code directory
    //Create them as needed
    course_copy_schedule_backup_log($starttime,$preferences->backup_course,"    checking temp structures");
    $status = check_and_create_backup_dir($preferences->backup_unique_code);
    //Empty backup dir
    if ($status) {
        course_copy_schedule_backup_log($starttime,$preferences->backup_course,"    cleaning current dir");
        $status = clear_backup_dir($preferences->backup_unique_code);
    }

    //Create the moodle.xml file
    if ($status) {
        course_copy_schedule_backup_log($starttime,$preferences->backup_course,"    creating backup file");
        //Obtain the xml file (create and open) and print prolog information
        $backup_file = backup_open_xml($preferences->backup_unique_code);
        //Prints general info about backup to file
        if ($backup_file) {
            course_copy_schedule_backup_log($starttime,$preferences->backup_course,"      general info");
            $status = backup_general_info($backup_file,$preferences);
        } else {
            $status = false;
        }

        //Prints course start (tag and general info)
        if ($status) {
            $status = backup_course_start($backup_file,$preferences);
        }

        //Metacourse information
        if ($status && $preferences->backup_metacourse) {
            course_copy_schedule_backup_log($starttime,$preferences->backup_course,"      metacourse info");
            $status = backup_course_metacourse($backup_file,$preferences);
        }

        //Block info
        if ($status) {
            course_copy_schedule_backup_log($starttime,$preferences->backup_course,"      blocks info");
            $status = backup_course_blocks($backup_file,$preferences);
        }

        //Section info
        if ($status) {
            course_copy_schedule_backup_log($starttime,$preferences->backup_course,"      sections info");
            $status = backup_course_sections($backup_file,$preferences);
        }

        //User info
        if ($status) {
            course_copy_schedule_backup_log($starttime,$preferences->backup_course,"      user info");
            $status = backup_user_info($backup_file,$preferences);
        }

        //If we have selected to backup messages and we are
        //doing a SITE backup, let's do it
        if ($status && $preferences->backup_messages && $preferences->backup_course == SITEID) {
            course_copy_schedule_backup_log($starttime,$preferences->backup_course,"      messages");
            $status = backup_messages($backup_file,$preferences);
        }

        //If we have selected to backup blogs and we are
        //doing a SITE backup, let's do it
        if ($status && $preferences->backup_blogs && $preferences->backup_course == SITEID) {
            course_copy_schedule_backup_log($starttime,$preferences->backup_course,"      blogs");
            $status = backup_blogs($backup_file,$preferences);
        }

        //If we have selected to backup quizzes, backup categories and
        //questions structure (step 1). See notes on mod/quiz/backuplib.php
        if ($status and $preferences->mods['quiz']->backup) {
            course_copy_schedule_backup_log($starttime,$preferences->backup_course,"      categories & questions");
            $status = backup_question_categories($backup_file,$preferences);
        }

        //Print logs if selected
        if ($status) {
            if ($preferences->backup_logs) {
                course_copy_schedule_backup_log($starttime,$preferences->backup_course,"      logs");
                $status = backup_log_info($backup_file,$preferences);
            }
        }

        //Print scales info
        if ($status) {
            course_copy_schedule_backup_log($starttime,$preferences->backup_course,"      scales");
            $status = backup_scales_info($backup_file,$preferences);
        }

        //Print groups info
        if ($status) {
            course_copy_schedule_backup_log($starttime,$preferences->backup_course,"      groups");
            $status = backup_groups_info($backup_file,$preferences);
        }

        //Print groupings info
        if ($status) {
            course_copy_schedule_backup_log($starttime,$preferences->backup_course,"      groupings");
            $status = backup_groupings_info($backup_file,$preferences);
        }

        //Print groupings_groups info
        if ($status) {
            course_copy_schedule_backup_log($starttime,$preferences->backup_course,"      groupings_groups");
            $status = backup_groupings_groups_info($backup_file,$preferences);
        }

        //Print events info
        if ($status) {
            course_copy_schedule_backup_log($starttime,$preferences->backup_course,"      events");
            $status = backup_events_info($backup_file,$preferences);
        }

        //Print gradebook info
        if ($status) {
            course_copy_schedule_backup_log($starttime,$preferences->backup_course,"      gradebook");
            $status = backup_gradebook_info($backup_file,$preferences);
        }

        //Module info, this unique function makes all the work!!
        //db export and module fileis copy
        if ($status) {
            $mods_to_backup = false;
            //Check if we have any mod to backup
            foreach ($preferences->mods as $module) {
                if ($module->backup) {
                    $mods_to_backup = true;
                }
            }
            //If we have to backup some module
            if ($mods_to_backup) {
                course_copy_schedule_backup_log($starttime,$preferences->backup_course,"      modules");
                //Start modules tag
                $status = backup_modules_start ($backup_file,$preferences);
                //Iterate over modules and call backup
                foreach ($preferences->mods as $module) {
                    if ($module->backup and $status) {
                        course_copy_schedule_backup_log($starttime,$preferences->backup_course,"        $module->name");
                        $status = backup_module($backup_file,$preferences,$module->name);
                    }
                }
                //Close modules tag
                $status = backup_modules_end ($backup_file,$preferences);
            }
        }

        //Backup course format data, if any.
        if ($status) {
            course_copy_schedule_backup_log($starttime,$preferences->backup_course,"      course format data");
            $status = backup_format_data($backup_file,$preferences);
        }

        //Prints course end
        if ($status) {
            $status = backup_course_end($backup_file,$preferences);
        }

        //Close the xml file and xml data
        if ($backup_file) {
            backup_close_xml($backup_file);
        }
    }

    //Now, if selected, copy user files
    if ($status) {
        if ($preferences->backup_user_files) {
            course_copy_schedule_backup_log($starttime,$preferences->backup_course,"    copying user files");
            $status = backup_copy_user_files ($preferences);
        }
    }

    //Now, if selected, copy course files
    if ($status) {
        if ($preferences->backup_course_files) {
            course_copy_schedule_backup_log($starttime,$preferences->backup_course,"    copying course files");
            $status = backup_copy_course_files ($preferences);
        }
    }

    //Now, if selected, copy site files
    if ($status) {
        if ($preferences->backup_site_files) {
            course_copy_schedule_backup_log($starttime,$preferences->backup_course,"    copying site files");
            $status = backup_copy_site_files ($preferences);
        }
    }

    //Now, zip all the backup directory contents
    if ($status) {
        course_copy_schedule_backup_log($starttime,$preferences->backup_course,"    zipping files");
        $status = backup_zip ($preferences);
    }

    //Now, copy the zip file to course directory
    if ($status) {
        course_copy_schedule_backup_log($starttime,$preferences->backup_course,"    copying backup");
        $status = copy_zip_to_course_dir ($preferences);
    }

    //Now, clean temporary data (db and filesystem)
    if ($status) {
        course_copy_schedule_backup_log($starttime,$preferences->backup_course,"    cleaning temp data");
        $status = clean_temp_data ($preferences);
    }

    //Unset CFG->backup_preferences only needed in scheduled backups
    unset ($CFG->backup_preferences);

    return $status;
}

?>
