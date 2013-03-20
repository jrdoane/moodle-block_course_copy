<?php

global $CFG;
require_once("{$CFG->dirroot}/lib/questionlib.php");

class course_copy_requirement_check_quiz extends course_copy_requirement_check {

    public static $EVENTS_ALLOWED = array(
        QUESTION_EVENTGRADE,
        QUESTION_EVENTCLOSEANDGRADE,
        QUESTION_EVENTMANUALGRADE
    );

    public static $EVENTS_TOBEGRADED = array(
        QUESTION_EVENTSUBMIT,
        QUESTION_EVENTCLOSE,
    );

    public static $EVENTS_TOBEFINISHED = array(
        QUESTION_EVENTOPEN,
        QUESTION_EVENTSAVE
    );

    protected $blocker_attempts;
    protected $blocker_by_student;
    protected $course;
    protected $quiz;
    protected $ran;
    protected $needs_grading;
    protected $needs_completion;

    public function __construct() {
        $this->blocker_attempts = false;
        $this->blocker_by_student = array();
        $this->needs_grading = false;
        $this->needs_completion = false;
        parent::__construct();
    }

    public function check($instance_id) {
        global $CFG;
        $p = $CFG->prefix;
        $graded_events = implode(', ', self::$EVENTS_ALLOWED);
        $this->quiz = get_record('quiz', 'id', $instance_id);
        $this->course = get_record('course', 'id', $this->quiz->course);
        $sql = "
            SELECT
                qst.id AS question_state_id,
                qa.uniqueid AS unique_attempt_id,
                u.firstname, u.lastname, u.id AS user_id,
                qst.event AS question_state_event,
                qa.id AS quiz_attempt_id, quiz.id AS quiz_id
            FROM {$p}quiz AS quiz
            JOIN {$p}quiz_attempts AS qa ON
                qa.quiz = quiz.id
            JOIN {$p}question_sessions AS qs ON 
                qs.attemptid = qa.uniqueid
            JOIN {$p}question_states AS qst ON
                qst.id = qs.newest
            JOIN {$p}user AS u ON
                u.id = qa.userid
            WHERE 
                quiz.id = $instance_id
                AND qa.preview = 0
                AND qst.event NOT IN ($graded_events)
        ";

        $blocker_attempts = get_records_sql($sql);

        // TODO: remove this next line when we're done.
        $this->sql = $sql;
        $this->ran = true;

        if(!$blocker_attempts) {
            $this->passed = true;
        } else {
            $this->passed = false;
        }

        // We might want to use these later to find out exactly why these 
        // attempts are preventing the check from completing. We're too lazy and 
        // care about resource too much to just do this now.
        $this->blocker_attempts = array();

        foreach($blocker_attempts as $a) {
            if(!isset($this->blocker_attempts[$a->quiz_id])) {
                $this->blocker_attempts[$a->quiz_id] = array();
            }
            if(!isset($this->blocker_attempts[$a->quiz_id][$a->user_id])) {
                $this->blocker_attempts[$a->quiz_id][$a->user_id] = (object) array (
                    'user_id' => $a->user_id,
                    'user' => get_record('user', 'id', $a->user_id),
                    'attempt_open' => (bool)in_array($a->question_state_event, self::$EVENTS_TOBEFINISHED),
                    'grading_required' => (bool)in_array($a->question_state_event, self::$EVENTS_TOBEGRADED),
                );
                if(!$this->needs_grading and $this->blocker_attempts[$a->quiz_id][$a->user_id]->grading_required) {
                    $this->needs_grading = true;
                }
                if(!$this->needs_completion and $this->blocker_attempts[$a->quiz_id][$a->user_id]->attempt_open) {
                    $this->needs_completion = true;
                }
                if(!isset($this->blocker_by_student[$a->user_id])) {
                    $this->blocker_by_student[$a->user_id] = array();
                }
                $this->blocker_by_student[$a->user_id][$a->quiz_id] = $this->blocker_attempts[$a->quiz_id][$a->user_id];
            }
        }

        return $this->passed;
    }

    private function require_run() {
        if(!$this->ran) {
            error("Must run a check before describing it.");
        }
    }

    /**
     * Passed for user strictly checks to see if there are any open attempts. 
     * Attempts waiting to be graded shouldn't bother the student since it is 
     * the teacher's responsibility to grade their work.
     */
    public function passed_for_user($user_id) {
        $this->require_run();
        if(!isset($this->blocker_by_student[$user_id])) {
            return true;
        }
        foreach($this->blocker_by_student[$user_id] as $bbs) {
            if($bbs->attempt_open == true) {
                return false;
            }
        }
        return true;
    }

    /**
     * Using HTML, this method describes what the check found as well as 
     * a little bit of deeper information about the check.
     */
    public function describe($user_id=false, $summary=true) {
        global $USER;
        # User must be able to edit grades and grade quizzes to see a full 
        # description.
        $course_context = get_context_instance(CONTEXT_COURSE, $this->course->id);

        $this->require_run();

        $output = '';

        $error_box_start                = print_box_start('errorbox', '', true);
        $info_box_start                 = print_box_start('informationbox', '', true);
        $box_end                        = print_box_end(true);
        $req_check_failed_str           = course_copy::str('requirementcheckfailedfor');
        $open_attempt_student_str       = course_copy::str('youhaveanopenattemptonthisquiz');
        $open_attempt_other_str         = course_copy::str('hasanopenattemptonthisquiz');
        $gradable_attempt_str           = course_copy::str('hasworkreadytobegraded');
        $gradable_count_str             = course_copy::str('thereareattemptsthatneedtobegraded');
        $open_count_str                 = course_copy::str('thereareopenattemptsthatneedtobecompleted');
        $check_passed_str               = course_copy::str('requirementcheckpassedfor');

        $fail_start = $error_box_start . print_heading($req_check_failed_str . $this->quiz->name, 'center', 3, 'main', true);
        $success_start = $info_box_start . print_heading($check_passed_str . $this->quiz->name, 'center', 3, 'main', true) . $box_end;

        if($this->passed()) {
            return $success_start;
        }

        // Description for a student.
        if($user_id) {
            $user_passed = $this->passed_for_user($user_id);
            if($user_passed) {
                return $success_start;
            }

            if($user_id == $USER->id) {
                return $fail_start . $open_attempt_student_str . $box_end;
            } else {
                return $fail_start . $open_attempt_other_str . $box_end;
            }
        }

        // We know that we didn't succeed if we're getting here.
        $output = $fail_start . "<p>";
        if($summary) {
            $gradable = $this->count_blockers(true, false);
            $open = $this->count_blockers(false, true);
            if($gradable > 0) {
                $output .= "{$gradable_count_str} ({$gradable})<br />";
            }
            if($open > 0) {
                $output .= "{$open_count_str} ({$open})<br />";
            }
            $output .= "</p>" . $box_end;
            return $output;
        }
        $error_list = array();

        foreach($this->blocker_by_student as $user_id => $sal) {
            if($sal->attempt_open) {
                $error_list[] = fullname($sal->user) . $open_attempt_other_str;
            }
            if($sal->grading_required) {
                $error_list[] = fullname($sal->user) . $gradable_attempt_str;
            }
        }
        $output .= implode("<br />\n", $error_list) . "</p>" . $box_end;

        return $output;
    }

    private function count_blockers($gradable = true, $open = true) {
        $count = 0;
        foreach($this->blocker_attempts as $quiz_instance => $data) {
            foreach($data as $ba) {
                if (($gradable and $ba->grading_required) or ($open and $ba->attempt_open)) {
                    $count++;
                    continue;
                }
            }
        }
        return $count;
    }
}
