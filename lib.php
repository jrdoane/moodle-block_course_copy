<?php
require_once(dirname(dirname(dirname(__FILE__))) . '/config.php');
require_once("{$CFG->dirroot}/backup/lib.php");
require_once("{$CFG->dirroot}/backup/backuplib.php");
require_once("{$CFG->dirroot}/backup/restorelib.php");
require_once("{$CFG->dirroot}/lib/xmlize.php");

/**
 * course_copy represents the basic ability of this block. Plugins will exist to 
 * alter how CourseCopy works and these plugins will simply extend this class.
 */
class course_copy {

    private static $_cached_instance;
    private static $_cached_cm_instances;

    const STATUS_UNTOUCHED = 'untouched';
    const STATUS_LOCKED = 'locked';
    const STATUS_COMPLETE = 'complete';
    const STATUS_ABANDONED = 'abandoned';
    const STATUS_ATTEMPTED = 'attempted';

    /**
     * Displays a link of a course if the current user has access to view the 
     * course. Otherwise a plain text varient of the course name will be 
     * presented instead.
     *
     * @param int       $course_id is a course.id.
     * @return string
     */

    public static function make_link($text, $url) {
        return '<a href="' . $url . '">' . $text . '</a>';
    }

    public static function user_course_link($course_id) {
        global $CFG;
        $course_name = get_field('course', 'fullname', 'id', $course_id);
        if(!$course_name) {
            return course_copy::str('coursedeleted');
        }
        if(!has_capability('moodle/course:view', get_context_instance(CONTEXT_COURSE, $course_id))) {
            return $course_name;
        }
        $url = new moodle_url($CFG->wwwroot . '/course/view.php');
        $url->param('id', $course_id);
        return self::make_link($course_name, $url->out());
    }

    /**
     * Displays a link to a user profile if the user has access to view the 
     * profile. Otherwise only a string without a link will be returned.
     *
     * @param int       $user_id is a user.id.
     * @return string
     */
    public static function user_profile_link($user_id) {
        global $CFG;
        if(!has_capability('moodle/user:viewdetails', get_context_instance(CONTEXT_SYSTEM))) {
            return $name;
        }
        $url = new moodle_url($CFG->wwwroot . '/user/view.php');
        $url->param('id', $user_id);
        return self::make_link(fullname(get_record('user', 'id', $user_id)), $url->out());
    }
    
     /**
      * Checks history capability against any one or many course id(s).
      *
      * @param mixed     $course_ids is a single id or array of course ids.
      * @return bool
      */
    public static function can_view_history($course_id, $require=false) {
        return self::has_course_capability('block/course_copy:history', $course_id, false, $require);
    }

    /**
     * Based on a master course, we find out if a user can push to all courses. 
     * If a person can't push to everything, then they can't push at all.
     *
     * @param mixed     $course_ids is a single id or array of course ids.
     * @return bool
     */
    public static function can_user_push($course_id, $require=false) {
        if(course_copy::is_master($course_id)) {
            $children = self::get_children_courses_by_master_course_id($course_id);
            if($children) {
                $course_ids = array_reduce($children, function(&$working, $i) {
                    $working[] = $i->id;
                    return $working;
                }, array());
            } else {
                $course_ids = array();
            }
        }
        $course_ids[] = $course_id;
        return self::has_courses_capability('block/course_copy:push', $course_ids, false, $require);
    }

    public static function has_one_courses_capability($cap, $course_ids, $require=false) {
        return self::has_courses_capability($cap, $course_ids, true, $require);
    }

    public static function has_course_capability($cap, $course, $require=false) {
        return self::has_courses_capability($cap, array($course), false, $require);
    }

    public static function has_courses_capability($cap, $courses, $only_one=false, $require=false) {
        $rval = true;
        foreach($courses as $id) {
            $context = get_context_instance(CONTEXT_COURSE, $id);
            if($require) {
                require_capability($cap, $context);
                continue;
            }
            $cap_result = has_capability($cap, $context);
            if($cap_result and $only_one) {
                return true;
            }
            // WARNING: For some weird reason, if you use "and" instead of "&&", 
            // this will always return true. -jdoane 20130618
            $rval = $rval && $cap_result;
        }
        return $rval;
    }

    public static function course_module_grade_item($cm) {
        $module = get_field('modules', 'name', 'id', $cm->module);
        return get_record_select('grade_items',
            "iteminstance = {$cm->instance} AND itemmodule = '{$module}'"
        );
    }

    public static function course_module_grade_grades($cm) {
        if(is_numeric($cm)) {
            $cm = get_record('course_modules', 'id', $cm);
        }
        if(!is_object($cm)) {
            error("Invalid parameter cm: $cm");
        }
        $gi = self::course_module_grade_item($cm);
        $gg = new grade_grade();
        return $gg->fetch_all(array('itemid' => $gi->id));
    }

    /**
     * This inserts/moves the contents of $after directly after $before in the 
     * $csv. A concequence of this method is that if $before doesn't exist in 
     * the array, then $after will be removed.
     *
     * @param string    $csv is a csv string.
     * @param mixed     $before is the value to search for.
     * @param mixed     $after is the value to put after the prior param in the
     *                  returned csv.
     * @return string
     */
    public static function csv_insert_after($csv, $before, $after) {
        return implode(',', array_reduce(explode(',', $csv),
            function(&$object, $item) {
                if($item != $object->after) {
                    $object->return[] = $item;
                }
                if($item == $object->before) {
                    $object->return[] = $object->after;
                }
                return $object;
            }, (object)array(
                'before' => $before,
                'after' => $after,
                'return' => array()
            ))->return);
    }

    public static function course_module_move_next_to($moving, $to) {
        $sections = array(get_record('course_sections', 'id', $moving->section));
        if($moving->section != $to->section) {
            $sections[] = get_record('course_sections', 'id', $to->section);
        }

        foreach($sections as $s) {
            $s->sequence = self::csv_insert_after($s->sequence, $to->id, $moving->id);
            if(!update_record('course_sections', $s)) {
                return false;
                // This should be an exception. --jdoane
            }
        }
        return true;
    }

    /**
     * This method takes in two course_modules.ids as arguments with an 
     * optional user_id that limits which grade(s) get copied.
     *
     * This returns the number of grades that are copied.
     *
     * TODO: Ensure that cross-course grade_copying is safe. --jdoane (20130324)
     *
     * @param int       $old_cm_id is course module id to copy grades from.
     * @param int       $new_cmd_id is the course module id to copy grades to.
     * @param int       $user_id (optional) will copy a grade for a particular user.
     * @return int
     */
    public static function copy_grades($old_cm, $new_cm) {
        global $CFG;
        $new_gi = self::course_module_grade_item($new_cm);
        $module = get_field('modules', 'name', 'id', $old_cm->module);

        $refresh_function = $module . '_grade_item_update';
        $instance = get_record($module, 'id', $new_cm->instance);
        $refresh_function($instance, 'reset');

        $old_grades = self::course_module_grade_grades($old_cm);

        foreach($old_grades as &$n) {
            unset($n->id);
            $n->itemid = $new_gi->id;
            $n->overridden = time();
            $n->timecreated = time();
            $n->timemodified = time();
            if($existing_gg_id = get_field('grade_grades', 'id', 'itemid', $new_gi->id, 'userid', $n->userid)) {
                $n->id = $existing_gg_id;
                if(!$n->update()) {
                    error('Updating grade with grade override failed.');
                }
            } else {
                if(!$n->insert()) {
                    error('Inserting grade with grade override failed.');
                }
            }
        }
        return count($old_grades);
    }

    /**
     * After this gets called the first time, we store a reference to the object 
     * so we can just return our already initialized course_copy object.
     */
    public static function create() {
        global $CFG;
        if(!empty(self::$_cached_instance)) {
            return self::$_cached_instance;
        }
        if(!empty($CFG->block_course_copy_plugin)) {
            $class_name = 'course_copy_' . $CFG->block_course_copy_plugin;
            $plugin_path = dirname(__FILE__) . "/plugins/{$CFG->block_course_copy_plugin}.php";
            if(!file_exists($plugin_path)) {
                error("Plugin file does not exist ({$plugin_path}).");
            }
            require_once($plugin_path);
        } else {
            $class_name = 'course_copy';
        }
        $obj = new $class_name();
        self::$_cached_instance =& $obj;
        return $obj;
    }

    /**
     * Wrapper for get_string to omit the repeated use of the string 
     * 'block_course_copy' in favor of a static method call.
     */
    public static function str($simple) {
        return get_string($simple, 'block_course_copy');
    }

    /**
     * Get the master moodle course id for a particular course.
     */
    public static function get_moodle_master_course_id($course_id) {
        return get_field('block_course_copy_master', 'id', 'course_id', $course_id);
    }

    public static function get_course_from_master_id($master_id) {
        global $CFG;
        $bpre = self::db_table_prefix();
        return get_record_sql("
            SELECT c.*
            FROM {$bpre}master AS m
            JOIN {$CFG->prefix}course AS c
                ON c.id = m.course_id
            WHERE m.id = $master_id
            ");
    }

    /**
     * We need this wrapper because we're violating scope when we use the $db 
     * global variable. We need this because execute_sql doesn't tell us enough. 
     * We need to know exactly how many records were updated.
     */
    public static function db_update_sql($sql) {
        global $db;
        $rs = $db->Execute($sql);
        if($rs) {
            if($db->Affected_Rows() > 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * This is a really cool method that takes a push_instance record and 
     * a closure and locks a push_instance for the duration of the closure.
     */
    public static function aquire_push_instance_lock($push_instance, $function) {
        global $CFG;
        // We have a push instance! First thing is first, lets attempt to lock 
        // the instance. If we fail to update the record, we failed to get the 
        // lock.
        $locked = self::db_update_sql("
            UPDATE {$CFG->prefix}block_course_copy_push_inst
            SET isprocessing = 1
            WHERE id = {$push_instance->id} AND isprocessing = 0
            ");
        $push_instance->isprocessing = 1; // Don't fetch it again just for this.

        if($locked) {
            // We're in business. Let's run the closure then give the lock back.
            // We're not passing anything in, we're "use"ing data where the 
            // closure is defined.
            $function($push_instance);
        } else {
            return false;
        }
        if(!update_record('block_course_copy_push_inst', (object)array(
            'id' => $push_instance->id,
            'isprocessing' => 0
        ))) {
            throw new Exception("Unable for forfit push_instance lock ({$push_instance->id}).");
        }
        return true;
    }

    public function cron() {
        $push_instances = self::fetch_push_instances(false, true);
        $timeout = self::cron_timeout_fromnow();
        $rval = true;

        if(!$push_instances) {
            return true;
        }

        foreach($push_instances as $push_instance) {
        // Attempt a push inside a push_instance lock.
            $rval = $rval and self::aquire_push_instance_lock($push_instance,
                function($push_instance) {
                    $push = get_record('block_course_copy_push', 'id', $push_instance->push_id);
                    return course_copy::attempt_push($push, $push_instance);
                });

            if(time() > $timeout) {
                print "[block_course_copy] Cron time limit exceeded. Giving up until next time.\n";
                break;
            }

        }
        return $rval;
    }

    /**
     * Ensures that all children can be children.
     * Example: If a course is deleted, the child should be deleted as well.
     *
     * @return null
     */
    public function ensure_relations($course_id) {
        $this->ensure_master($course_id);
        $this->ensure_child($course_id);
    }

    public function ensure_master($course_id) {
        $master = get_record('block_course_copy_master', 'course_id', $course_id);
        if(!$master) { return; }
        if(!record_exists('course', 'id', $course_id)) {
            self::remove_master_by_course($course_id);
        }
    }

    public function ensure_child($course_id) {
        $child = get_record('block_course_copy_child', 'course_id', $course_id);
        if(!$child) { return; }
        if(!record_exists('course', 'id', $course_id)) {
            self::remove_child_by_course($course_id);
        }
    }

    /**
     * This is true if a particular string matches more than one course module 
     * instance.
     */
    public static function course_module_matches_many($push, $push_inst) {
        self::precache_course_cm_instances($push_inst->dest_course_id);
        if (self::match_course_module($push->course_module_id, $push_inst->dest_course_id) === null) {
            return true;
        }
        return false;
    }

    /**
     * Lets push it!
     *
     * @param object    $push is a push record.
     * @param object    $push_inst is the instance being attempted.
     * @return bool
     */
    public static function attempt_push($push, $push_inst) {
        // If it's already complete, then get out.
        if($push_inst->timecompleted > 0) {
            return false;
        }

        // If it's not time, get out.
        if($push->timeeffective > time()) {
            return false;
        }

        $push_inst->attempts++;
        $push_inst->timemodified = time();
        // TODO? Do we want to check this output to ensure it succeededs? There 
        // is no reason why it shouldn't unless $push_inst is bad.
        update_record('block_course_copy_push_inst', $push_inst);
        $source_cm_id = $push->course_module_id;
        $source_cm_name = self::get_cached_cm_instance($source_cm_id, 'name');
        $dest_course = $push_inst->dest_course_id;
        $replace = self::is_replacing();
        $deprecate_cm_id = null;
        if($replace) {
            $deprecate_cm_id = self::match_course_module($source_cm_id, $dest_course);
        }

        // If we're replacing a course module we want to get the old name so we 
        // can update it and we want to run the requirement_check on it to make 
        // sure it's ready to be replaced.
        if($deprecate_cm_id) {
            $deprecate_cm_name = self::get_cached_cm_instance($deprecate_cm_id, 'name');
            $check = course_copy_requirement_check::check_course_module($deprecate_cm_id);
            if(!$check->passed()) {
                return false;
            }
        }

        $rval = course_copy::copy_course_module($source_cm_id, $dest_course);
        if(!$rval) {
            return false;
        }

        $push_inst->timecompleted = time();
        update_record('block_course_copy_push_inst', $push_inst);

        if($deprecate_cm_id) {
            // Make this course module not visible and alter the name.
            if(!self::deprecate_course_module($deprecate_cm_id)) {
                error('Unable to deprecate a course module.');
                // Make this an exception. We want to handle exception in cron.
            }

            // At this point the old course module's name has been changed but 
            // we need the course module that was just newly imported. Running 
            // this a second time after we change the old name should leave us 
            // with just the course module we just created.
            $new_cm_id = self::match_course_module($source_cm_id, $dest_course);

            $old_cm = get_record('course_modules', 'id', $deprecate_cm_id);
            $new_cm = get_record('course_modules', 'id', $new_cm_id);

            // If we're copying grades, do that now.
            if (self::is_copying_grades()) {
                self::copy_grades($old_cm, $new_cm);
            }
            self::course_module_move_next_to($new_cm, $old_cm);
        }
        rebuild_course_cache($dest_course);
        return true;
    }

    public static function get_push_source_cmid($push_inst_id) {
        $p = self::db_table_prefix();
        return get_field_sql("SELECT p.course_module_id
            FROM {$p}push AS p
            JOIN {$p}push_inst AS pi
                ON pi.push_id = p.id
            WHERE pi.id = $push_inst_id");
    }

    public static function sql_table_def($table = false, $tidbit = false) {
        $p = self::db_table_prefix();
        static $lookup;
        if(empty($lookup)) {
            $lookup = array(
                'push' => array(
                    'alias' => 'p',
                    'fullname' => "{$p}push",
                    'fields' => array(
                        'id',
                        'master_id',
                        'src_course_id',
                        'course_module_id',
                        'user_id',
                        'description',
                        'timeeffective',
                        'timecreated'
                    )
                ),
                'push_inst' => array(
                    'alias' => 'pi',
                    'fullname' => "{$p}push_inst",
                    'fields' => array(
                        'id',
                        'push_id',
                        'child_id',
                        'dest_course_id',
                        'attempts',
                        'timecompleted',
                        'timemodified',
                        'timecreated',
                        'isprocessing'
                    )
                ),
                'master' => array(
                    'alias' => 'm',
                    'fullname' => "{$p}master",
                    'fields' => array(
                        'id',
                        'course_id',
                        'timecreated',
                        'timemodified'
                    )
                ),
                'child' => array(
                    'alias' => 'c',
                    'fullname' => "{$p}child",
                    'fields' => array(
                        'id',
                        'master_id',
                        'course_id',
                        'timecreated',
                        'timemodified'
                    )
                )
            );
        }

        if($table) {
            return $lookup[$table];
            if($tidbit) {
                return $lookup[$table][$tidbit];
            }
        }
        return $lookup;
    }

    public static function sql_partial_def($field) {
        return array_map(function($i) use ($field) { return $i[$field]; }, course_copy::sql_table_def());
    }

    /**
     * Uses the table definition to give an array of fields prepended with the 
     * table alias.
     */
    public static function sql_select_as($tdef) {
        return array_map(function($v) use ($tdef) { return course_copy::sql_alias($tdef, $v); }, $tdef['fields']);
    }

    /**
     * This has been generalized. We want to use sql_select_as() to use the 
     * appropriate aliases as opposed to using them here. That was this can 
     * still build a select that consists of multiple tables.
     *
     * Note: Like moodle get_record, the first field is used for counting and PK.
     * 
     * @param int       $fields is an array of strings that represent table fields.
     *                  Fields must express their context (ex. p.src_course_id)
     * @param bool      $count will count the records using one field instead of all.
     * @return string
     */
    public static function sql_select($fields, $count = false) {
        if($count) { $fields = array(array_shift($fields)); }
            return 'SELECT ' . implode(', ', $fields);
    }

    public static function sql_from($pending) {
        $names = self::sql_partial_def('fullname');
        $aliases = self::sql_partial_def('alias');
        $partial = "
            FROM {$names['push']} AS {$aliases['push']}
            JOIN {$names['push_inst']} AS {$aliases['push_inst']}
            ON {$aliases['push_inst']}.push_id = {$aliases['push']}.id
    ";
        if($pending) {
            $partial .= "
            JOIN {$names['master']} AS {$aliases['master']}
                ON {$aliases['master']}.id = {$aliases['push']}.master_id
            JOIN {$names['child']} AS {$aliases['child']}
                ON {$aliases['child']}.id = {$aliases['push_inst']}.child_id
            ";
        }
        return $partial;
    }

    /**
     * Takes an array ($fvp), and where-ifies the data. Embed will wrap the 
     * output in parens and with is the SQL keyword to concat the comparisons 
     * together with. If the comparator is empty, all the elements are combined 
     * together using the SQL keyword in the "WITH" statement.
     */
    public static function sql_where($fvp, $embed = false, $with = 'AND', $comparator = '=') {
        $ou = array();
        foreach($fvp as $key => $value) {
            if(is_array($value) and empty($comparator)) {
                foreach($value as $nval) {
                    $ou[] = self::sql_compare($key, $comparator, $nval);
                }
            } else {
                if(!empty($comparator)) {
                    $ou[] = self::sql_compare($key, $comparator, $value);
                } else {
                    $ou[] = $value; // Assume the string results in a SQL bool.
                }
            }
        }
        $ou = implode(" {$with} ", $ou);
        return $embed ? self::sql_where_embed($ou) : self::sql_where_base($ou);
    }

    public static function sql_compare($field, $comparator, $value) {
        return "{$field} {$comparator} {$value}";
    }

    /**
     * Wrap where string in parens since it's most likely going to be AND'ed 
     * with other things.
     */
    public static function sql_where_embed($string) {
        return " ({$string}) ";
    }

    public static function sql_where_base($string) {
        return " WHERE {$string} ";
    }

    public static function sql_alias($def, $field) {
        return $def['alias'] . '.' . $field;
    }

    /**
     * 
     */
    public static function sql_group_by($fields) {
        if(empty($fields)) {
            return '';
        }
        return "GROUP BY " . implode(', ', $fields);
    }

    public static function sql_fetch_push($push_id, $instances=false) {
        $td = self::sql_table_def($instances ? 'push_inst' : 'push');
        return self::sql_select(self::sql_select_as($td))
            . self::sql_from(false)
            . self::sql_where(array(self::sql_alias($td, 'id') => $push_id))
            . self::sql_group_by(self::sql_select_as($td));
    }

    public static function sql_course_history($course_id, $count = false) {
        $defs = self::sql_table_def();

        $sql = self::sql_select(self::sql_select_as($defs['push']), $count)
            . self::sql_from(false)
            . self::sql_where(array(
                self::sql_alias($defs['push'], 'src_course_id') => $course_id,
                self::sql_alias($defs['push_inst'], 'dest_course_id') => $course_id
            ), false, 'OR')
            . self::sql_group_by(self::sql_select_as($defs['push']));
        
        if(!$count) {
            $sql .= " ORDER BY " . self::sql_alias($defs['push'], 'timecreated') . ' DESC';
        }
        return $sql;
    }

    public static function fetch_pending_pushes_by_child_course($course_id=null, $limit=0, $offset=0) {
        $pdef = self::sql_table_def('push');
        $pidef = self::sql_table_def('push_inst');
        $sql = self::sql_select(self::sql_select_as($pdef))
            . self::sql_from(true)
            . self::sql_where(array(self::sql_alias($pidef, 'dest_course_id') => $course_id))
            . self::sql_group_by(self::sql_select_as($pdef));
        return get_records_sql($sql, $offset, $limit);
    }

    public static function fetch_pending_push_instances_by_child_course($course_id) {
        $pdef = self::sql_table_def('push');
        $pidef = self::sql_table_def('push_inst');
        $sql = self::sql_select(self::sql_select_as($pidef))
            . self::sql_from(false)
            . self::sql_where(array(
                self::sql_where(array(
                    self::sql_alias($pidef, 'dest_course_id') => $course_id,
                    self::sql_alias($pidef, 'timecompleted') => 0
                ), true),
                self::sql_where(array(
                    self::sql_alias($pidef, 'child_id') => 0,
                    self::sql_alias($pdef, 'master_id') => 0
                ), true, 'AND', '>')
            ), false, 'AND', false)
            . self::sql_group_by(self::sql_select_as($pidef));
        return get_records_sql($sql);
    }

    public static function fetch_push_instances($push_id = false, $pending = false) {
        $defs = self::sql_table_def();
        $sql = self::sql_select(self::sql_select_as($defs['push_inst']))
            . self::sql_from($pending);
        if($push_id) {
            $sql .= self::sql_where(array(self::sql_alias($defs['push'], 'id') => $push_id));
        }
        return get_records_sql($sql);
    }

    public static function course_has_history($course_id) {
        return record_exists('block_course_copy_push', 'src_course_id', $course_id) or
            record_exists('block_course_copy_push_inst', 'dest_course_id', $course_id);
    }

    public static function count_course_push_history($course_id) {
        $sql = self::sql_course_history($course_id, true);
        return count_records_sql($sql);
    }

    /**
     * This fetches push information for a particular course. This includes 
     * outgoing pushes and incoming pushes as opposed to a single direction like 
     * the fetch_pending_pushes_... methods which target one and the other as 
     * opposed to one or the other. This is also fairly easy to query.
     */
    public static function fetch_course_push_history($course_id, $limit=0, $offset=0) {
        $sql = self::sql_course_history($course_id, false);
        $data = get_records_sql($sql, $offset, $limit);
        // Almost there, we want all the push_inst records to be included here 
        // as well.
        foreach($data as &$d) {
            $d->instances = array();
            $inst = get_records('block_course_copy_push_inst', 'push_id', $d->id);
            if($inst) {
                $d->instances = $inst;
            }
        }
        return $data;
    }

    public static function get_possible_masters() {
        global $CFG;
        $p = $CFG->prefix;
        $sql = "
            SELECT c.*
            FROM {$p}course AS c
            WHERE c.id NOT IN
            (
                SELECT bccm.course_id FROM {$p}block_course_copy_master AS bccm
            ) OR  c.id NOT IN (
                SELECT bccc.course_id FROM {$p}block_course_copy_child AS bccc
            )
            ";
        return get_records_sql($sql);
    }

    public static function get_possible_children($master_id) {
        $sql = "
            SELECT
            FROM {$p}course AS c
            WHERE c.id NOT IN
            SELECT bccm.course_id FROM {$p}block_course_copy_master AS bccm
        ) OR  c.id NOT IN (
            SELECT bccc.course_id FROM {$p}block_course_copy_child AS bccc
        )
        ";
        return get_records_sql($sql);
    }

    public static function get_children($master_id) {
        return get_records('block_course_copy_child', 'master_id', $master_id);
    }

    public static function get_children_courses($master_id) {
        global $CFG;
        $bp = self::db_table_prefix();
        return get_records_sql("
            SELECT c.*
            FROM {$bp}child AS ch
            JOIN {$CFG->prefix}course AS c
            ON ch.course_id = c.id
            WHERE ch.master_id = $master_id
            ");
    }

    public static function get_children_courses_by_master_course_id($course_id) {
        $master_id = get_field('block_course_copy_master', 'id', 'course_id', $course_id);
        return self::get_children_courses($master_id);
    }

    public static function get_children_by_master_course_id($course_id) {
        $master_id = get_field('block_course_copy_master', 'id', 'course_id', $course_id);
        return self::get_children($master_id);
    }

    public static function get_masters($page=0, $per_page=10) {
        global $CFG;
        $limit = $per_page;
        $offset = $page * $limit;
        $p = $CFG->prefix;
        $bpre = self::db_table_prefix();
        return get_records_sql("
            SELECT bccm.id AS master_id, c.*
            FROM {$bpre}master AS bccm
            JOIN {$p}course AS c
                ON bccm.course_id = c.id
            ORDER BY c.fullname ASC
            ", $offset, $limit
        );
    }

    public static function get_master_course_by_child_course_id($course_id) {
        $master_id = get_field('block_course_copy_child', 'master_id', 'course_id', $course_id);
        if(!$master_id) {
            error("This course does not have a master ({$course_id}).");
        }
        $master_course_id =  get_field('block_course_copy_master', 'course_id', 'id', $master_id);
        if(!$master_course_id) {
            error("Master is missing a course_id ({$master_id}).");
        }
        return get_record('course', 'id', $master_course_id);
    }

    public static function can_be_assigned($course_id) {
        if(self::is_master_or_child($course_id)) {
            return false;
        }
        return true;
    }

    public static function can_be_master($course_id) {
        return self::can_be_assigned($course_id);
    }

    public static function can_be_child($course_id) {
        return self::can_be_assigned($course_id);
    }

    public static function is_master_or_child($course_id) {
        if(self::is_child($course_id)) {
            return true;
        }
        if(self::is_master($course_id)) {
            return true;
        }
        return false;
    }

    public static function is_master($course_id) {
        return record_exists('block_course_copy_master', 'course_id', $course_id);
    }

    public static function is_child($course_id) {
        return record_exists('block_course_copy_child', 'course_id', $course_id);
    }

    public static function add_master($course_id) {
        if(!self::can_be_master($course_id)) {
            error("This course can not be a master.");
        }
        $master = (object)array(
            'course_id' => $course_id,
            'timecreated' => time(),
            'timemodified' => time()
        );
        if(!insert_record('block_course_copy_master', $master)) {
            error('Unable to insert into block_course_copy_master.');
        }
    }

    public static function add_child($course_id, $master_id) {
        if(!self::can_be_child($course_id)) {
            error("This course can not be a child.");
        }

        if(!record_exists('block_course_copy_master', 'id', $master_id)) {
            error("There is no master with the id ({$master_id}).");
        }

        $child = (object)array(
            'course_id' => $course_id,
            'master_id' => $master_id,
            'timecreated' => time(),
            'timemodified' => time()
        );
        if(!insert_record('block_course_copy_child', $child)) {
            error('Unable to insert into block_course_copy_child.');
        }
    }

    public static function remove_child_by_course($course_id) {
        $child_id = get_field('block_course_copy_child', 'id', 'course_id', $course_id);
        if(!$child_id) {
            error('attempted to remove a child by a course that is not a child.');
        }
        self::remove_child($child_id);
    }

    public static function remove_master_by_course($course_id) {
        $master_id = get_field('block_course_copy_master', 'id', 'course_id', $course_id);
        if(!$master_id) {
            error('attempted to remove a master by a course that is not a master.');
        }
        self::remove_master($master_id);
    }

    public static function remove_child($child_id) {
        global $CFG;
        delete_records('block_course_copy_child', 'id', $child_id);
        execute_sql("
            UPDATE {$CFG->prefix}block_course_copy_push_inst
            SET child_id = NULL
            WHERE child_id = $child_id
            ");
    }

    public static function remove_master($master_id) {
        delete_records('block_course_copy_child', 'master_id', $master_id);
        delete_records('block_course_copy_master', 'id', $master_id);
        $pushes = get_records('block_course_copy_push', 'master_id', $master_id);
        if($pushes) {
            $pushes = array_map(function($i) {return $i->id;}, $pushes);
            execute_sql("
                UPDATE {$CFG->prefix}block_course_copy_push
                SET master_id = NULL
                WHERE master_id = $master_id
                ");
            foreach($pushes as $p) {
                execute_sql("
                    UPDATE {$CFG->prefix}block_course_copy_push_inst
                    SET child_id = NULL
                    WHERE push_id = $p
                    ");
            }
        }
    }

    public function master_has_outstanding_push($master_id) {
    }

    public static function child_has_outstanding_push($child_id) {
        $p = self::db_table_prefix();
        return record_exists_sql("
            SELECT pi.*
            FROM {$p}push_inst AS pi
            WHERE (
                pi.timecompleted IS NOT NULL OR
                pi.timecompleted = 0
            ) AND pi.child_id = {$child_id}
            ");
    }

    public static function course_has_outstanding_push($course_id) {
        $bp = self::db_table_prefix();
        return record_exists_sql("
            SELECT *
            FROM {$bp}push_inst AS pi
            JOIN {$bp}child AS c
            ON c.id = pi.child_id
            WHERE c.course_id = $course_id
            ");
    }

    public static function master_has_children($master_id) {
        return record_exists('block_course_copy_child', 'master_id', $master_id);
    }

    public static function master_has_children_by_course($course_id) {
        return self::master_has_children(get_field('block_course_copy_master', 'id', 'course_id', $course_id));
    }

    public static function abandon_push($push_id) {
        return update_record('block_course_copy_push', (object) array(
            'id' => $push_id,
            'master_id' => 0
        ));
    }

    public static function abandon_push_instance($push_inst_id) {
        return update_record('block_course_copy_push_inst', (object) array(
            'id' => $push_inst_id,
            'child_id' => 0
        ));
    }

    public static function push_instance_status($push, $push_inst) {
        $status = course_copy::STATUS_UNTOUCHED;
        if ($push_inst->isprocessing) {
            $status = course_copy::STATUS_LOCKED;
        } else if($push_inst->timecompleted > 0) {
            $status = course_copy::STATUS_COMPLETE;
        } else if(!$push->master_id or !$push_inst->child_id) {
            $status = course_copy::STATUS_ABANDONED;
        } else if ($push_inst->attempts > 0) {
            $status = course_copy::STATUS_ATTEMPTED;
        }
        return $status;
    }

    public static function is_push_done($push, $insts) {
        $complete = array(
            self::STATUS_COMPLETE,
            self::STATUS_ABANDONED
        );
        foreach($insts as $i) {
            if(!in_array(self::push_instance_status($push, $i), $complete)) {
                return false;
            }
        }
        return true;
    }

    /**
     * When a plugin overrides this and returns true, it will not give a capable 
     * user the option to add children since the plugin will be expected to manage
     * these records automatically.
     */
    public function plugin_manages_children() {
        return false;
    }

    /**
     * When a plugin overrides this and returns true, it will not give a capable 
     * user the option to add masters since the plugin will be expected to manage
     * these records automatically.
     */
    public function plugin_manages_masters() {
        return false;
    }

    /**
     * Processes a course module through the backup and restore system to make 
     * an identical copy of this module without re-creating the backup system 
     * since it has been replaced in 2.x.
     *
     * @param int       $cmid is a course module id.
     * @param int       $dest_course_id is the id of the course you are copying to.
     * @return bool
     */
    public static function copy_course_module($cmid, $dest_course_id) {
        $src_course_id = get_field('course_modules', 'course', 'id', $cmid);
        if(!$cmid) {
            error('course_module does not appear to exist.');
        }
        $err = '';
        $backup_code = self::backup_course_module($cmid, $err);
        if(!$backup_code) {
            // Backup failed.
            # error("Backup error: " . $err);
            return false;
        }
        if(!self::restore_course_module($cmid, $dest_course_id, $backup_code)) {
            #error('Failed to restore course module.');
            return false;
        }
        return true;
    }

    public static function generate_restore_prefs($course_module_id, $destination_course_id) {
        $restore_prefs = self::generate_prefs('restore');
        $cm = get_record('course_modules', 'id', $course_module_id);
        $module_name = get_field('modules', 'name', 'id', $cm->module);
        $restore_prefs["restore_{$module_name}"] = 1;
        return $restore_prefs;
    }

    public static function generate_backup_prefs($course_module_id) {
        $cm = get_record('course_modules', 'id', $course_module_id);
        $module_name = get_field('modules', 'name', 'id', $cm->module);
        $course = get_record('course', 'id', $cm->course);
        $instance_name = get_field($module_name, 'name', 'id', $cm->instance);
        $backup_prefs = self::generate_prefs('backup', $course->id);
        $backup_prefs["backup_unique_code"] = time();
        $backup_prefs["backup_name"] = $backup_prefs["backup_unique_code"] . ".zip";
        $backup_prefs["exists_one_{$module_name}"] = true;
        $backup_prefs["mods"] = array(
            $module_name => (object)array(
                'name' => $module_name,
                'instances' => array(
                    $cm->instance => (object)array(
                        'name' => $instance_name,
                        'backup' => 1,
                        'userinfo' => 0
                    )
                ),
                'backup' => 1,
                'userinfo' => 0
            )
        );
        $backup_prefs = (object)$backup_prefs;
        backup_add_static_preferences($backup_prefs);
        return $backup_prefs;
    }

    public static function generate_prefs($prefix, $course_id=null) {
        $prefs = array();
        $prefs["{$prefix}_users"] = 0;
        $prefs["{$prefix}_user_files"] = 0;
        $prefs["{$prefix}_course_files"] = 0;
        $prefs["{$prefix}_gradebook_history"] = 0;
        $prefs["{$prefix}_site_files"] = 0;
        $prefs["{$prefix}_blogs"] = 0;
        $prefs["{$prefix}_metacourse"] = 0;
        $prefs["{$prefix}_messages"] = 0;
        if($course_id) {
            $prefs["{$prefix}_course"] = $course_id;
        }
        return $prefs;
    }

    public static function restore_course_module($src_course_module_id, $dest_course_id, $backup_code) {
        global $CFG;
        // This runs the restore.
        $src_course_id = get_field('course_modules', 'course', 'id', $src_course_module_id);
        $prefs = self::generate_restore_prefs($src_course_module_id, $dest_course_id);
        $file_path = "{$CFG->dataroot}/{$src_course_id}/backupdata/{$backup_code}.zip";
        $rval = import_backup_file_silently($file_path, $dest_course_id, false, false, $prefs);
        return $rval;
    }

    public static function backup_course_module($cmid, &$err='') {
        $cm = get_record('course_modules', 'id', $cmid);
        $module_name = get_field('modules', 'name', 'id', $cm->module);
        $course = get_record('course', 'id', $cm->course);

        // We don't want to hear the backup output. That will will only remind us that 
        // this uses the Moodle 1.9 backup/restore system. Something we would rather not 
        // remember. :) --jdoane 2012/01/16
        define('BACKUP_SILENTLY', true);

        // Fake some backup data so we can generate some preferences.
        $prefs = self::generate_backup_prefs($cmid);
        $count = 0;
        $rval = backup_execute($prefs, $err);
        if($rval) {
            return $prefs->backup_unique_code;
        }
        return false;
    }

    public static function db_table_prefix() {
        global $CFG;
        return $CFG->prefix . "block_course_copy_";
    }

    public static function uasort_course_modules($a, $b) {
        return strcmp($a->instance->name, $b->instance->name);
    }

    public static function get_course_modules($course_id, $sort=false) {
        $modules = get_records('modules');

        $cms = get_records('course_modules', 'course', $course_id);
        if(!$cms) {
            error("Course module with id $course_id not found.");
        }

        $removal = array();

        foreach($cms as &$cm) {
            $cm->module = $modules[$cm->module];
            if(!course_copy_requirement_check::module_checkable($cm->module->name)) {
                $removal[] = $cm->id;
            }
            $cm->instance = get_record($cm->module->name, 'id', $cm->instance);
        }

        foreach($removal as $r) {
            unset($cms[$r]);
        }

        if($sort) {
            uasort($cms, array('course_copy', 'uasort_course_modules'));
        }

        return $cms;
    }

    public static function match_course_module($src_cm_id, $dest_course_id) {
        static $cm_cache;
        static $result_cache;
        $key = $src_cm_id . ':' . $dest_course_id;

        if(empty($cm_cache)) {
            $cm_cache = array();
        }
        if(empty($result_cache)) {
            $result_cache = array();
        }

        if(isset($result_cache[$key])) {
            return $result_cache[$key];
        }
        $base_name = self::simplify_name(self::get_cached_cm_instance($src_cm_id, 'name'));

        if(empty($cm_cache[$dest_course_id])) {
            $cm_cache[$dest_course_id] = get_records('course_modules', 'course', $dest_course_id, '', 'id, module, instance');
        }
        if(!$cm_cache[$dest_course_id]) {
            return false;
        }
        $src_cm = self::get_cached_cm($src_cm_id);

        foreach($cm_cache[$dest_course_id] as $cm) {
            if($base_name == self::simplify_name(self::get_cached_cm_instance($cm->id, 'name'))) {
                if($src_cm->module == $cm->module) {
                    $result_cache[$key] = $cm->id;
                    return $result_cache[$key];
                }
            }
        }

        $result_cache[$key] = false;
        return $result_cache[$key];
    }

    public static function simplify_name($str) {
        return strtolower(preg_replace('/\s/', '', $str));
    }

    public static function precache_course_cm_instances($course_id) {
        global $CFG;
        static $has_run;

        if(empty($has_run)) {
            $has_run = array();
        }

        if(!empty($has_run[$course_id])) {
            return;
        }

        $modules = get_records_sql("
            SELECT m.id, m.name FROM {$CFG->prefix}modules AS m
            JOIN {$CFG->prefix}course_modules AS cm
                ON m.id = cm.module
            WHERE cm.course = $course_id
            GROUP BY m.id, m.name
        ");

        foreach($modules as $m) {
            $cms = get_records_sql("
                SELECT cm.id AS course_module_id, mb.id, mb.name
                FROM {$CFG->prefix}{$m->name} AS mb
                JOIN {$CFG->prefix}course_modules AS cm
                ON mb.id = cm.instance
                WHERE cm.module = {$m->id} AND cm.course = {$course_id}
                ");
            foreach($cms as $i) {
                self::$_cached_cm_instances[$i->course_module_id] = $i;
            }
        }

        $has_run[$course_id] = true;
    }

    public static function get_cached_cm_instance($cm, $field=false) {
        $id = is_object($cm) ? $cm->id : $cm;
        if(empty(self::$_cached_cm_instances)) {
            self::$_cached_cm_instances = array();
        }
        if(empty(self::$_cached_cm_instances[$id])) {
            if(!is_object($cm)) {
                $cm = self::get_cached_cm($id);
            }
            $module_name = self::get_cached_module_name($cm->module);
            self::$_cached_cm_instances[$cm->id] = get_record($module_name, 'id', $cm->instance);
        }
        if($field) {
            return self::$_cached_cm_instances[$id]->$field;
        }
        return self::$_cached_cm_instances[$id];
    }

    public static function get_cached_cm($cm_id) {
        static $cache;
        if(!is_array($cache)) {
            $cache = array();
        }
        if(!isset($cache[$cm_id])) {
            $cache[$cm_id] = get_record('course_modules', 'id', $cm_id);
        }
        return $cache[$cm_id];
    }

    public static function get_cached_module_name($id) {
        static $cache;
        if(!is_array($cache)) {
            $cache = array();
        }
        if(!isset($cache[$id])) {
            $cache[$id] = get_field('modules', 'name', 'id', $id);
        }
        return $cache[$id];
    }

    public static function deprecate_course_module($cm_id) {
        // The the course module and hide it.
        $cm = get_record('course_modules', 'id', $cm_id);
        $cm->timemodified = time();
        $cm->visible = 0;
        $rval = update_record('course_modules', $cm);

        // Update the name of the module instance
        $module = get_field('modules', 'name', 'id', $cm->module);
        $instance = get_record($module, 'id', $cm->instance);
        // TODO: Make this prepended string a configurable option.
        $instance->name = '[Deprecated] ' . $instance->name;
        $instance->timemodified = time();
        $rval = $rval and update_record($module, $instance);

        // Lock the old grade item.
        $gei = new grade_item();
        $gi = $gei->fetch(array(
            'iteminstance' => $instance->id,
            'itemmodule' => $module
        ));
        $gi->locked = 1;
        $gi->hidden = 1;
        $gi->itemname = $instance->name; // Grrr... Moodle denormalization. :< --jdoane 20130513
        $rval = $rval and $gi->update();
        return $rval;
    }

    public static function is_copying_grades() {
        return get_config(null, 'block_course_copy_transfer_grades');
    }

    public static function is_replacing() {
        return get_config(null, 'block_course_copy_replace');
    }

    public static function cron_timeout_fromnow() {
        $value = get_config(null, 'block_course_copy_cron_timeout');
        $time = time();
        if(!$value) {
            return $time;
        }
        return $time + (60 * $value);
    }

    /**
     * This method checks to see if requirements to copy this course module are 
     * met. This depends much more on the course module that is being replaced.
     */
    public static function check_requirements($push, $instance) {
        $cm = course_copy::match_course_module($push->course_module_id, $instance->dest_course_id);
        // No replacement makes the check easy. We're good to go. :)
        if(!$cm) {
            return false;
        }
        return course_copy_requirement_check::check_course_module($cm);
    }
}

/**
 * Default class for checking moodle course modules to verify that they're ready 
 * to be copied.
 *
 * Against my better judgement, I'm adding course module name checking to the 
 * requirement check base code.
 * TODO: Make this smart or get rid of it. >:-(
 * -jdoane
 */
class course_copy_requirement_check {

    public static $_plugins_loaded = array();

    public static function create_plugin_path($module) {
        return dirname(__FILE__) . "/mods/{$module}.php";
    }

    public static function load_module_plugin($module, $errors=true) {
        if(isset(self::$_plugins_loaded[$module])) {
            return true;
        }
        $p = self::create_plugin_path($module);
        if(!is_file($p)) {
            if($errors) {
                error("Module plugin file does not exist. {$p}");
            }
            return false;
        }
        if(!include_once($p)) {
            if($errors) {
                error("Unable to include plugin file. {$p}");
            }
            return false;
        }
        $class_name = __CLASS__ . '_' . $module;
        if(!class_exists($class_name)) {
            if($errors) {
                error("Requirement class doesn't exist: $class_name");
            }
            return false;
        }
        self::$_plugins_loaded[$module] = true;
        return true;
    }

    public static function module_checkable($module) {
        if(self::load_module_plugin($module, false)) {
            return true;
        }
        return false;
    }

    public static function create($module) {
        self::load_module_plugin($module);
        $class_name = __CLASS__ . '_' . $module;
        return new $class_name();
    }

    public static function check_course_module($cm) {
        if(is_numeric($cm)) {
            $cm = get_record('course_modules', 'id', $cm);
        }
        if(!is_object($cm)) {
            error('Expected object, got other.');
        }

        $module_name = get_field('modules', 'name', 'id', $cm->module);
        $checker = self::create($module_name);
        $checker->check($cm->instance);
        return $checker;
    }

    protected $passed;

    public function __construct() {
        $this->passed = null;
    }

    public function check($instance_id) {
        error('This method should be overridden.');
    }

    public function passed() {
        return $this->passed;
    }

    public function passed_for_user($user_id) {
        error('This method should be overridden.');
    }

    public function describe($user_id=false, $summary=true) {
        error('This method should be overridden.');
    }
}

class course_copy_assessment_push_form extends moodleform {

    private $classrooms;

    function __construct($url, $classrooms) {
        $this->classrooms = $classrooms;
        parent::__construct($url, null, 'POST');
    }

    function definition() {
        $form =& $this->_form;
        $form->addElement('header', 'assessment_push', get_string('selectclassrooms', 'block_assessment_manager'));

        $form->addElement('textarea', 'description', get_string('descriptionforpush', 'block_assessment_manager'));
        $form->addElement('checkbox', 'pushnow', get_string('pushnow', 'block_assessment_manager'));
        $form->addElement('date_time_selector', 'timeeffective', get_string('pushatthistime', 'block_assessment_manager'));

        $form->disabledIf('timeeffective', 'pushnow', 'checked');
        $form->addRule('description', null, 'required', null, 'client');
        $form->addRule('description', null, 'required', null, 'server');

        $cr_idstrs = array();
        foreach($this->classrooms as $c) {
            $form->addElement('advcheckbox', 'c'.$c->classroom_idstr, $c->name, '');
            $cr_idstrs[] = $c->classroom_idstr;
        }
        $form->addElement('submit', 'submit', get_string('pushassessment', 'block_assessment_manager'));
    }
}

/**
 * Basic form for confirming changes to the course copy block since some changes 
 * can no be reversed.
 */
class block_course_copy_confirmation_form extends moodleform {

    private $_description;
    private $_confirm_string;

    function __construct($url, $description, $confirm_string) {
        $this->_description = $description;
        $this->_confirm_string = $confirm_string;
        parent::__construct($url, null, 'POST');
    }

    function definition() {
        $f =& $this->_form;
        $buttons = array();
        $f->addElement('header', 'confirm_action', course_copy::str('confirmaction'));
        $str = "<p style=\"margin: 2em; margin-top: 1em; margin-bottom: 1em;\">{$this->_description}</p>";
        $f->addElement('html', $str);
        $f->addElement('checkbox', 'user_confirmed', $this->_confirm_string);
        $buttons[] = $f->createElement('submit', 'submit', course_copy::str('proceed'));
        $buttons[] = $f->createElement('cancel');
        $f->addGroup($buttons, 'button_opts', '', array(' '), false);

        $f->disabledIf('submit', 'user_confirmed');
    }

    function user_accepted() {
        if($this->is_cancelled()) {
            return false;
        }
        if(!$data = $this->get_data()) {
            return false;
        }

        if($data->user_confirmed and $data->submit) {
            return true;
        }
        return false;
    }
}

class block_course_copy_create_push extends moodleform {
    protected $master_id;
    protected $master_course;
    protected $child_courses;
    protected $course_modules;

    function __construct($url, $course_id) {
        $this->master_course = get_record('course', 'id', $course_id);
        $this->child_courses = course_copy::get_children_courses_by_master_course_id($this->master_course->id);
        $this->course_modules = course_copy::get_course_modules($course_id, true);
        parent::__construct($url, null, 'POST');
    }

    function definition() {
        $form =& $this->_form;
        $form->addElement('header', 'assessment_push', course_copy::str('createpush'));

        $form->addElement('htmleditor', 'description', course_copy::str('descriptionforpush'));
        $form->addElement('checkbox', 'pushnow', course_copy::str('pushnow'));
        $form->addElement('date_time_selector', 'timeeffective', course_copy::str('pushatthistime'));

        $form->disabledIf('timeeffective', 'pushnow', 'checked');
        $form->addRule('description', null, 'required', null, 'client');
        $form->addRule('description', null, 'required', null, 'server');

        $cclist = array();
        foreach($this->child_courses as $cc) {
            $cclist[$cc->id] = $cc->fullname;
        }
        $select = $form->addELement('select', 'child_course_ids', course_copy::str('childrentopushto'), $cclist);
        $select->setMultiple(true);

        $radio_cm_group = array();
        foreach($this->course_modules as $cm) {
            $radio_cm_group[] = $form->createElement('radio', 'course_module', '', $cm->instance->name, $cm->id);
        }
        $form->addGroup($radio_cm_group, 'cm_group', '', '<br />', false);

        $form->addElement('submit', 'submit', course_copy::str('pushassessment'));

    }
}

