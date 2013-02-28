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
        // Do nothing for now. This is here for plugins.
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
        $src_course_id = get_field('course_module', 'course', 'id', $cmid);
        if(!$src_course_id) {
            error('course_module does not appear to exist.');
        }
        $err = '';
        $backup_code = self::backup_course_module($cmid, $err);
        if(!$rval) {
            // Backup failed.
            error("Backup error: " . $err);
        }
        if(!restore_course_module($src_course_id, $dest_course_id, $backup_code)) {
            error('Failed to restore course module.');
        }

    }

    public static function restore_course_module($src_course_id, $dest_course_id, $backup_code) {
        global $CFG;
        // This runs the restore.
        $file_path = "{$CFG->datapath}/{$src_course_id}/$backup_code";
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
        $_GET['backup_unique_code'] = 12312312;
        $_GET['backup_name'] = $_GET['backup_unique_code'] . '.zip';
        $_GET["backup_{$module_name}_instance_{$cm->instance}"] = 1;
        $_GET["backup_{$module_name}"] = 1;
        $_GET['backup_users'] = 0;
        $_GET['backup_user_files'] = 0;
        $_GET['backup_course_files'] = 0;
        $_GET['backup_gradebook_history'] = 0;
        $_GET['backup_site_files'] = 0;
        $_GET['backup_blogs'] = 0;
        $_GET['backup_metacourse'] = 0;
        $_GET['backup_messages'] = 0;

        // This is out moodle backup preferences wrapper. I suspect that I will remove 
        // this before too long. -- jdoane 2012/01/16
        if (isset($SESSION->backupprefs[$cm->course])) {
            unset($SESSION->backupprefs[$cm->course]);
        }

        $prefs = new stdClass;
        $count = 0;
        $rval = backup_fetch_prefs_from_request($prefs, $count, $course);
        if(!$rval) {
            return false;
        }
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

    public static function get_course_modules($course_id, $cm_id=null) {
        $modules = get_records('modules');

        if($cm_id) {
            $cms = get_records('course_modules', 'id', $cm_id);
        } else {
            $cms = get_records('course_modules', 'course', $course_id);
        }
        if(!$cms) {
            error("Course module with id $course_id not found.");
        }

        $removal = array();
        $bad_mods = array('data', 'label', 'lesson', 'resource');

        foreach($cms as &$cm) {
            $cm->module = $modules[$cm->module];
            if(in_array($cm->module->name, $bad_mods)) {
                $removal[] = $cm->id;
                continue;
            }
            $cm->instance = get_record($cm->module->name, 'id', $cm->instance);
        }

        foreach($removal as $r) {
            unset($cms[$r]);
        }

        if($cm_id) {
            return array_pop($cms);
        }
        return $cms;
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
        $this->course_modules = course_copy::get_course_modules($course_id, null, true, true);
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

