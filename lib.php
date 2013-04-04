<?php
require_once("{$CFG->dirroot}/backup/lib.php");
require_once("{$CFG->dirroot}/backup/backuplib.php");
require_once("{$CFG->dirroot}/backup/restorelib.php");

/**
 * CourseCopy represents the basic ability of this block. Plugins will exist to 
 * alter how CourseCopy works and these plugins will simply extend this class.
 */
class course_copy {

    protected static $_cached_instance;

    /**
     * This method will take a course module id and will get the appropriate 
     * information to convert the grade_grade record for a /different/ course 
     * module to an override for the one specific in the first argument.
     *
     * For now, we're going to enforce transferring grades within a course until 
     * the implications of transferring grades from course to course is made.
     *
     * @param int       $course_module_id is a course_modules.id
     * @param object    $grade_grade is a record from grade_grades.
     * @param bool      $exclude will exclude the old grade_grade after the copy is made.
     * @return null
     */
    public static function copy_grade_override($course_module_id, $grade_grade, $exclude=true) {
        $old_grade_grade_id = $grade_grade->id;
        $cm = get_record('course_modules', 'id', $course_module_id);
        $grade_item = get_record('grade_items',
            'itemodule', $cm->module,
            'iteminstance', $cm->instance,
            'courseid', $cm->course
        );
        unset($grade_grade->id);
        $grade_grade->itemid = $grade_item->id;
        $grade_grade->usermodified = null;
        $grade_grade->overridden = time();
        $grade_grade->timecreated = time();
        $grade_grade->timemodified = time();
        if(!insert_record('grade_grades', $grade_grade)) {
            error('Unable to copy grade as override.');
        }
        if($exclude) {
            if(!update_record('grade_grades', (object) array (
                'id' => $old_grade_grade_id,
                'excluded' => time(),
                'timemodified' => time(),
                'usermodified' => null
            ))) {
                error('Unable to exclude old grade.');
            }
        }
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
    public static function copy_grades($old_cm_id, $new_cm_id, $user_id=null) {
        global $CFG;
        $user_where = '';
        if($user_id) {
            $user_where = "AND gg.userid = {$user_id}";
        }
        $old_grades = get_records_sql("
            SELECT
            FROM {$CFG->prefix}course_modules AS cm
            JOIN {$CFG->prefix}grade_items AS gi
                ON gi.iteminstance = cm.instance
                AND gi.itemmodule = cm.module
                AND gi.courseid = cm.course
            JOIN {$CFG->prefix}grade_grades AS gg
                ON gg.itemid = gi.id
            WHERE
                cm.id = {$new_cm_id} {$user_where}
        ");

        /**
         * No grades exist and if they do, we don't know how to find them.
         */
        if(!$old_grades) {
            return 0;
        }

        foreach($old_grades as &$grade) {
            self::copy_grade_override($new_cm_id, $grade);
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

    public function cron() {
        // We want to check to see if any pending pushes are ready to be 
        // processed. If they are we need to process them, if not we have to 
        // update them to say we've seen it and that it isn't ready.
    }

    /**
     * This is true if a particular string matches more than one course module 
     * instance.
     */
    public static function course_module_matches_many($push, $push_inst) {
        if (self::match_course_module($push->course_module_id, $push_inst->dest_course_id) === null) {
            return true;
        }
        return false;
    }

    /**
     * Lets push it!
     */
    public function attempt_push($push, $push_inst) {
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
        update_record('block_course_copy_push_inst', $push_inst);
        $source_cm_id = $push->course_module_id;
        $source_cm_name = self::get_cm_name($source_cm_id);
        $dest_course = $push_inst->dest_course_id;
        $deprecate_cm_id = self::match_course_module($source_cm_id, $dest_course);

        // If we're replacing a course module we want to get the old name so we 
        // can update it and we want to run the requirement_check on it to make 
        // sure it's ready to be replaced.
        if($deprecate_cm_id) {
            $deprecate_cm_name = self::get_cm_name($deprecate_cm_id);
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
            if(!self::deprecate_course_module($deprecate_cm_id)) {
                #error('Unable to deprecate a course module.');
                return false;
            }
        }
        return true;
    }

    public function get_push_source_cmid($push_inst_id) {
        $p = self::db_table_prefix();
        return get_field_sql("SELECT p.course_module_id
            FROM {$p}push AS p
            JOIN {$p}push_inst AS pi
                ON pi.push_id = p.id
            WHERE pi.id = $push_inst_id");
    }

    /**
     * This method is some crazyness that can get you just about any push or 
     * push instance that you could ever want.
     *
     * @param int       $child_course_id Fetch data based off child course id.
     * @param int       $master_course_id Fetch data based off master course id.
     * @param int       $push_id Fetch data based on the push id.
     * @param bool      $instances Returns push instances rather than pushes.
     * @param bool      $pending Gets pending pushes if true. Otherwise fetches 
     *                  all records based on denormalized course ids.
     * @return string
     */
    public function fetch_push_records_sql($child_course_id=false, $master_course_id=false,
        $push_id=false, $instances=false, $pending=true, $count=false)
    {
        $p = self::db_table_prefix();
        $left = 'LEFT';
        $select = 'p.*';
        $now = time();
        $where = array();
        $child_table_field = 'c.course_id';
        $master_table_field = 'm.course_id';

        $child_master_join = "
            JOIN {$p}master AS m
                ON m.id = p.master_id
            JOIN {$p}child AS c
                ON c.id = pi.child_id
            ";

        if($pending) {
            $where[] = "p.timeeffective < $now";
            $where[] = "pi.timecompleted = 0";
        } else {
            $child_table_field = 'p.src_course_id';
            $master_table_field = 'pi.dest_course_id';
            $child_master_join = '';
        }

        if($push_id) {
            $where[] = "p.id = {$push_id}";
        }

        if($child_course_id) {
            $where[] = "{$child_table_field} = {$child_course_id} ";
        }

        if($master_course_id) {
            $where[] = "{$master_table_field} = {$master_course_id} ";
        }

        if($instances) {
            $select = 'pi.*';
            $left = '';
        }

        $where = implode(' AND ', $where);

        if(!empty($where)) {
            $where = 'WHERE ' . $where;
        }

        if($count) {
            $select = "COUNT({$select})";
        }

        $sql = "
            SELECT {$select}
            FROM {$p}push AS p
            {$left} JOIN {$p}push_inst AS pi
                ON pi.push_id = p.id AND
                pi.child_id IS NOT NULL
            {$child_master_join}
            $where
            ";

        return $sql;

    }

    public function fetch_pending_pushes_by_child_course($course_id=null, $limit=0, $offset=0) {
        $sql = $this->fetch_push_records_sql($course_id);
        return get_records_sql($sql, $offset, $limit);
    }

    public function count_pending_pushes_by_child_course($course_id) {
        $sql = $this->fetch_push_records_sql($course_id, null, false, false, true, true);
        return count_records_sql($sql);
    }

    public function fetch_pending_pushes_by_master_course($course_id, $limit=0, $offset=0) {
        $sql = $this->fetch_push_records_sql(null, $course_id, false, false, true);
        return get_records_sql($sql, $offset, $limit);
    }

    public function count_pending_pushes_by_master_course($course_id) {
        $sql = $this->fetch_push_records_sql(null, $course_id, false, false, true, true);
        return count_records_sql($sql);
    }

    public function fetch_pending_push_instances($push_id, $course_id=null, $limit=0, $offset=0) {
        $sql = $this->fetch_push_records_sql($course_id, false, $push_id, true);
        return get_records_sql($sql, $offset, $limit);
    }

    public function get_possible_masters() {
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

    public function get_possible_children($master_id) {
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

    public function get_children($master_id) {
        return get_records('block_course_copy_child', 'master_id', $master_id);
    }

    public function get_children_courses($master_id) {
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

    public function get_children_courses_by_master_course_id($course_id) {
        $master_id = get_field('block_course_copy_master', 'id', 'course_id', $course_id);
        return $this->get_children_courses($master_id);
    }

    public function get_children_by_master_course_id($course_id) {
        $master_id = get_field('block_course_copy_master', 'id', 'course_id', $course_id);
        return $this->get_children($master_id);
    }

    public function get_masters($page=0, $per_page=10) {
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

    public function get_master_course_by_child_course_id($course_id) {
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

    public function can_be_assigned($course_id) {
        if($this->is_master_or_child($course_id)) {
            return false;
        }
        return true;
    }

    public function can_be_master($course_id) {
        return $this->can_be_assigned($course_id);
    }

    public function can_be_child($course_id) {
        return $this->can_be_assigned($course_id);
    }

    public function is_master_or_child($course_id) {
        if($this->is_child($course_id)) {
            return true;
        }
        if($this->is_master($course_id)) {
            return true;
        }
        return false;
    }

    public function is_master($course_id) {
        return record_exists('block_course_copy_master', 'course_id', $course_id);
    }

    public function is_child($course_id, $master_id=false) {
        return record_exists('block_course_copy_child', 'course_id', $course_id);
    }

    public function add_master($course_id) {
        if(!$this->can_be_master($course_id)) {
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

    public function add_child($course_id, $master_id) {
        if(!$this->can_be_child($course_id)) {
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

    public function remove_child_by_course($course_id) {
        $child_id = get_field('block_course_copy_child', 'id', 'course_id', $course_id);
        if(!$child_id) {
            error('attempted to remove a child by a course that is not a child.');
        }
        $this->remove_child($child_id);
    }

    public function remove_master_by_course($course_id) {
        $master_id = get_field('block_course_copy_master', 'id', 'course_id', $course_id);
        if(!$master_id) {
            error('attempted to remove a master by a course that is not a master.');
        }
        $this->remove_master($master_id);
    }

    public function remove_child($child_id) {
        delete_records('block_course_copy_child', 'id', $child_id);
    }

    public function remove_master($master_id) {
        delete_records('block_course_copy_child', 'master_id', $master_id);
        delete_records('block_course_copy_master', 'id', $master_id);
    }

    public function master_has_outstanding_push($master_id) {
    }

    public function child_has_outstanding_push($child_id) {
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

    public function course_has_outstanding_push($course_id) {
        $bp = self::db_table_prefix();
        return record_exists_sql("
            SELECT *
            FROM {$bp}push_inst AS pi
            JOIN {$bp}child AS c
                ON c.id = pi.child_id
            WHERE c.course_id = $course_id
            ");
    }

    public function master_has_children($master_id) {
        return record_exists('block_course_copy_child', 'master_id', $master_id);
    }

    public function master_has_children_by_course($course_id) {
        return $this->master_has_children(get_field('block_course_copy_master', 'id', 'course_id', $course_id));
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
        if(!self::restore_course_module($src_course_id, $dest_course_id, $backup_code)) {
            #error('Failed to restore course module.');
            return false;
        }
        return true;
    }

    public static function restore_course_module($src_course_id, $dest_course_id, $backup_code) {
        global $CFG;
        // This runs the restore.
        $file_path = "{$CFG->dataroot}/{$src_course_id}/backupdata/{$backup_code}.zip";
        $rval = import_backup_file_silently($file_path, $dest_course_id);
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
        $backup_prefs = array();
        $backup_prefs['backup_unique_code'] = time();
        $backup_prefs['backup_name'] = $backup_prefs['backup_unique_code'] . '.zip';
        $backup_prefs["backup_{$module_name}_instance_{$cm->instance}"] = 1;
        $backup_prefs["backup_{$module_name}"] = 1;
        $backup_prefs['backup_users'] = 0;
        $backup_prefs['backup_user_files'] = 0;
        $backup_prefs['backup_course_files'] = 0;
        $backup_prefs['backup_gradebook_history'] = 0;
        $backup_prefs['backup_site_files'] = 0;
        $backup_prefs['backup_blogs'] = 0;
        $backup_prefs['backup_metacourse'] = 0;
        $backup_prefs['backup_messages'] = 0;
        $backup_prefs['backup_course'] = $course->id;

        // This is out moodle backup preferences wrapper. I suspect that I will remove 
        // this before too long. -- jdoane 2012/01/16
        if (isset($SESSION->backupprefs[$cm->course])) {
            unset($SESSION->backupprefs[$cm->course]);
        }

        $prefs = (object)$backup_prefs;
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
        $base_name = self::simplify_name(self::get_cm_name($src_cm_id));
        $course_modules = get_records('course_modules', 'course', $dest_course_id, '', 'id, module, instance');
        if(!$course_modules) {
            return false;
        }
        foreach($course_modules as $cm) {
            if($base_name == self::simplify_name(self::get_cm_name($cm->id))) {
                return $cm->id;
            }
        }

        return false;
    }

    public static function simplify_name($str) {
        return strtolower(preg_replace('/\s/', '', $str));
    }

    /**
     * We want to keep this as minimal as possible.
     */
    public static function get_cm_name($cm) {
        return self::get_cm_instance($cm, 'name');
    }

    public static function get_cm_instance($cm, $field=false) {
        if(is_numeric($cm)) {
            $cm = get_record('course_modules', 'id', $cm);
        }
        $module_name = get_field('modules', 'name', 'id', $cm->module);
        if($field) {
            return get_field($module_name, $field, 'id', $cm->instance);
        }
        return get_record($module_name, 'id', $cm->instance);
    }

    public static function set_cm_instance($cm, $field, $value) {

    }

    public static function deprecate_course_module($cm_id) {
        $cm = get_record('course_modules', 'id', $cm_id);
        $cm->timemodified = time();
        $cm->visible = 0;
        $rval = update_record('course_modules', $cm);
        $module = get_field('module', 'name', 'id', $cm->module);
        $instance = get_record($module, 'id', $cm->instance);
        $instance->name = '[Deprecated] ' . $instance->name;
        $instance->timemodified = time();
        $rval = $rval and update_record($module, $instance);
        return $rval;
    }

    /**
     * This method checks to see if 
     */
    public static function check_requirements($push, $instance) {
        $cm = course_copy::match_course_module($push->course_module_id, $instance->dest_course_id);
        // No replacement makes the check easy. We're good to go. :)
        if(!$cm) {
            return false;
        }
        return course_copy_requirement_check::check_course_module($cm);
    }

    public static function import_backup_wrapper($pathtofile, $destinationcourse,
        $emptyfirst=false, $userdata=false, $preferences=array())
    {
        global $CFG,$SESSION,$USER; // is there such a thing on cron? I guess so..

        if (!defined('RESTORE_SILENTLY')) {
            define('RESTORE_SILENTLY',true); // don't output all the stuff to us.
        }

        $debuginfo = 'import_backup_file_silently: ';
        $cleanupafter = false;
        $errorstr = ''; // passed by reference to restore_precheck to get errors from.

        $course = null;
        if ($destinationcourse && !$course = get_record('course','id',$destinationcourse)) {
            mtrace($debuginfo.'Course with id $destinationcourse was not a valid course!');
            return false;
        }

        // first check we have a valid file.
        if (!file_exists($pathtofile) || !is_readable($pathtofile)) {
            mtrace($debuginfo.'File '.$pathtofile.' either didn\'t exist or wasn\'t readable');
            return false;
        }

        // now make sure it's a zip file
        require_once($CFG->dirroot.'/lib/filelib.php');
        $filename = substr($pathtofile,strrpos($pathtofile,'/')+1);
        $mimetype = mimeinfo("type", $filename);
        if ($mimetype != 'application/zip') {
            mtrace($debuginfo.'File '.$pathtofile.' was of wrong mimetype ('.$mimetype.')' );
            return false;
        }

        // restore_precheck wants this within dataroot, so lets put it there if it's not already..
        if (strstr($pathtofile,$CFG->dataroot) === false) {
            // first try and actually move it..
            if (!check_dir_exists($CFG->dataroot.'/temp/backup/',true)) {
                mtrace($debuginfo.'File '.$pathtofile.' outside of dataroot and couldn\'t move it! ');
                return false;
            }
            if (!copy($pathtofile,$CFG->dataroot.'/temp/backup/'.$filename)) {
                mtrace($debuginfo.'File '.$pathtofile.' outside of dataroot and couldn\'t move it! ');
                return false;
            } else {
                $pathtofile = 'temp/backup/'.$filename;
                $cleanupafter = true;
            }
        } else {
            // it is within dataroot, so take it off the path for restore_precheck.
            $pathtofile = substr($pathtofile,strlen($CFG->dataroot.'/'));
        }

        if (!backup_required_functions()) {
            mtrace($debuginfo.'Required function check failed (see backup_required_functions)');
            return false;
        }
        @ini_set("max_execution_time","3000");
        if (empty($CFG->extramemorylimit)) {
            raise_memory_limit('128M');
        } else {
            raise_memory_limit($CFG->extramemorylimit);
        }

        if (!$backup_unique_code = restore_precheck($destinationcourse,$pathtofile,$errorstr,true)) {
            mtrace($debuginfo.'Failed restore_precheck (error was '.$errorstr.')');
            return false;
        }

        global $restore; // ick
        $restore = new StdClass;
        // copy back over the stuff that gets set in restore_precheck
        $restore->course_header = $SESSION->course_header;
        $restore->info          = $SESSION->info;

        $xmlfile = "$CFG->dataroot/temp/backup/$backup_unique_code/moodle.xml";
        $info = restore_read_xml_roles($xmlfile);
        $restore->rolesmapping = array();
        if (isset($info->roles) && is_array($info->roles)) {
            foreach ($info->roles as $id => $info) {
                if ($newroleid = get_field('role', 'id', 'shortname', $info->shortname)) {
                    $restore->rolesmapping[$id] = $newroleid;
                }
            }
        }

        // add on some extra stuff we need...
        $restore->metacourse = (isset($preferences['restore_metacourse']) ? $preferences['restore_metacourse'] : 0);
        $restore->course_id = $destinationcourse;
        if ($destinationcourse) {
            $restore->restoreto              = RESTORETO_CURRENT_ADDING;
            $restore->course_startdateoffset = $course->startdate - $restore->course_header->course_startdate;
        } else {
            $restore->restoreto              = RESTORETO_NEW_COURSE;
            $restore->restore_restorecatto   = 0; // let this be handled by the headers
            $restore->course_startdateoffset = 0;

        }

        $restore->users      = $userdata;
        $restore->user_files = $userdata;
        $restore->deleting   = $emptyfirst;

        $restore->groups       = (isset($preferences['restore_groups']) ? $preferences['restore_groups'] : RESTORE_GROUPS_NONE);
        $restore->logs         = (isset($preferences['restore_logs']) ? $preferences['restore_logs'] : 0);
        $restore->messages     = (isset($preferences['restore_messages']) ? $preferences['restore_messages'] : 0);
        $restore->blogs        = (isset($preferences['restore_blogs']) ? $preferences['restore_blogs'] : 0);
        $restore->course_files = (isset($preferences['restore_course_files']) ? $preferences['restore_course_files'] : 0);
        $restore->site_files   = (isset($preferences['restore_site_files']) ? $preferences['restore_site_files'] : 0);

        $restore->backup_version   = $restore->info->backup_backup_version;
        $restore->original_wwwroot = $restore->info->original_wwwroot;

        // now copy what we have over to the session
        // this needs to happen before restore_setup_for_check
        // which for some reason reads the session
        $SESSION->restore =& $restore;
        // rename the things that are called differently 
        $SESSION->restore->restore_course_files = $restore->course_files;
        $SESSION->restore->restore_site_files   = $restore->site_files;
        $SESSION->restore->backup_version       = $restore->info->backup_backup_version;

        restore_setup_for_check($restore, $backup_unique_code);

        // maybe we need users (defaults to 2 (none) in restore_setup_for_check)
        // so set this again here
        if (!empty($userdata)) {
            $restore->users = 1;
        }

        // we also need modules...
        if ($allmods = get_records("modules")) {
            foreach ($allmods as $mod) {
                $modname = $mod->name;
                //Now check that we have that module info in the backup file
                if (isset($restore->info->mods[$modname]) && $restore->info->mods[$modname]->backup == "true") {
                    $restore->mods[$modname]->restore = true;
                    $restore->mods[$modname]->userinfo = $userdata;
                }
                else {
                    // avoid warnings
                    $restore->mods[$modname]->restore = false;
                    $restore->mods[$modname]->userinfo = false;
                }
            }
        }
        if (!$status = restore_execute($restore,$restore->info,$restore->course_header,$errorstr)) {
            mtrace($debuginfo.'Failed restore_execute (error was '.$errorstr.')');
            return false;
        }
        // now get out the new courseid and return that
        if ($restore->restoreto = RESTORETO_NEW_COURSE) {
            if (!empty($SESSION->restore->course_id)) {
                return $SESSION->restore->course_id;
            }
            return false;
        }
        return true;

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
        $course_copy = course_copy::create();
        $this->master_course = get_record('course', 'id', $course_id);
        $this->child_courses = $course_copy->get_children_courses_by_master_course_id($this->master_course->id);
        $this->course_modules = course_copy::get_course_modules($course_id, true);
        parent::__construct($url, null, 'POST');
    }

    function definition() {
        $form =& $this->_form;
        $form->addElement('header', 'assessment_push', course_copy::str('createpush'));

        $form->addElement('htmleditor', 'description', course_copy::str('descriptionforpush'));
        $form->addElement('checkbox', 'copy_grades', course_copy::str('copygrades'));
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

