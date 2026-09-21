<?php
namespace local_privacient;

defined('MOODLE_INTERNAL') || die();

/**
 * Turns Moodle progress events into a signed callback to the console.
 *
 * The work is handed to an adhoc task rather than done inline: a learner
 * clicking through a SCORM should never wait on an outbound HTTP call, and a
 * console that is briefly down must not surface as an error mid-training.
 */
class observer {

    public static function sco_launched(\core\event\base $event): bool {
        self::queue($event->relateduserid ?: $event->userid, $event->courseid, 'started', null);
        return true;
    }

    public static function completion_updated(\core\event\base $event): bool {
        global $DB;

        $userid = $event->relateduserid ?: $event->userid;
        $completion = $DB->get_record('course_modules_completion', ['id' => $event->objectid]);
        if (!$completion) {
            return true;
        }
        // 1 = complete, 2 = complete-pass, 3 = complete-fail.
        $done = in_array((int) $completion->completionstate, [1, 2], true);
        self::queue(
            $userid,
            $event->courseid,
            $done ? 'completed' : 'started',
            $done ? self::score_for((int) $event->courseid, $userid) : null
        );
        return true;
    }

    public static function quiz_attempt_started(\core\event\base $event): bool {
        self::queue($event->relateduserid ?: $event->userid, $event->courseid, 'started', null);
        return true;
    }

    /**
     * A questionnaire has been marked: report the outcome, pass or fail.
     *
     * A failed paper is a real event with a real score, and until now nothing
     * reported it — Moodle only speaks up when completion changes, and a
     * pass-gated quiz that was failed has not changed. So the console showed
     * "Not started" to a learner who had already used every attempt.
     */
    public static function quiz_attempt_graded(\core\event\base $event): bool {
        global $DB;

        $attempt = $DB->get_record('quiz_attempts', ['id' => $event->objectid]);
        if (!$attempt) {
            return true;
        }
        self::report_quiz(
            (int) $event->courseid,
            (int) $attempt->quiz,
            (int) ($event->relateduserid ?: $event->userid)
        );
        return true;
    }

    /**
     * Queue one learner's standing on one questionnaire.
     *
     * Public so that the reconciliation script can replay a paper the console
     * never heard about — an attempt sat before this plugin observed quizzes at
     * all, or one whose adhoc task was lost while cron was down.
     */
    public static function report_quiz(int $courseid, int $quizid, int $userid): string {
        $status = self::quiz_outcome($courseid, $quizid, $userid);
        self::queue($userid, $courseid, $status, self::score_for($courseid, $userid));
        return $status;
    }

    /**
     * 'completed', 'failed' or 'started' for the paper this event is about.
     *
     * "Failed" is reserved for a learner who cannot try again: while attempts
     * remain, a wrong answer is a step in the training rather than a verdict on
     * it, and calling it a failure would be both discouraging and untrue.
     */
    public static function quiz_outcome(int $courseid, int $quizid, int $userid): string {
        global $CFG, $DB;

        $quiz = $DB->get_record('quiz', ['id' => $quizid]);
        if (!$quiz) {
            return 'started';
        }

        require_once($CFG->libdir . '/gradelib.php');
        $grades = grade_get_grades($courseid, 'mod', 'quiz', $quiz->id, $userid);
        $item = $grades ? reset($grades->items) : null;
        $grade = $item->grades[$userid]->grade ?? null;

        // No pass mark means the paper is a check rather than a gate, so
        // sitting it is finishing it.
        $pass = $item ? (float) $item->gradepass : 0.0;
        if ($pass <= 0) {
            return 'completed';
        }
        if ($grade !== null && $grade !== '' && (float) $grade >= $pass) {
            return 'completed';
        }

        $allowed = (int) $quiz->attempts; // 0 is unlimited.
        $used = $DB->count_records_select(
            'quiz_attempts',
            'quiz = :quiz AND userid = :userid AND preview = 0 AND state <> :abandoned',
            ['quiz' => $quiz->id, 'userid' => $userid, 'abandoned' => \mod_quiz\quiz_attempt::ABANDONED]
        );
        return ($allowed > 0 && $used >= $allowed) ? 'failed' : 'started';
    }

    public static function course_completed(\core\event\base $event): bool {
        $userid = $event->relateduserid ?: $event->userid;
        self::queue(
            $userid,
            $event->courseid,
            'completed',
            self::score_for((int) $event->courseid, (int) $userid)
        );
        return true;
    }

    /**
     * What the learner scored, or null when the material does not score.
     *
     * Only SCORM reports a mark: it talks back over the SCORM runtime API. A
     * video has nothing to report, and sending 0 for one would look like a
     * failed assessment rather than an ungraded activity — so the distinction
     * between "no score" and "scored zero" is kept, and null means the former.
     *
     * Moodle's own grade function is used rather than reading the tracking
     * tables, because the activity's grading method (highest, average, first,
     * last attempt) is a setting, and reimplementing it here would quietly
     * disagree with the grade Moodle itself shows.
     */
    private static function score_for(int $courseid, int $userid): ?float {
        global $CFG, $DB;

        // A generated questionnaire is a quiz, and its mark is the whole point
        // of it — so it is read from the gradebook as a percentage, which is
        // the same scale the SCORM branch below reports on.
        $quizzes = $DB->get_records('quiz', ['course' => $courseid]);
        if (!empty($quizzes)) {
            require_once($CFG->libdir . '/gradelib.php');
            $best = null;
            foreach ($quizzes as $quiz) {
                $grades = grade_get_grades($courseid, 'mod', 'quiz', $quiz->id, $userid);
                $item = reset($grades->items);
                if (!$item) {
                    continue;
                }
                $grade = $item->grades[$userid]->grade ?? null;
                // Never attempted reads as null, not as a genuine zero.
                if ($grade === null || $grade === '') {
                    continue;
                }
                $max = (float) ($item->grademax ?: 0);
                $percent = $max > 0 ? ((float) $grade / $max) * 100 : (float) $grade;
                $best = $best === null ? $percent : max($best, $percent);
            }
            if ($best !== null) {
                return round($best, 2);
            }
        }

        $scorms = $DB->get_records('scorm', ['course' => $courseid]);
        if (empty($scorms)) {
            return null;
        }
        require_once($CFG->dirroot . '/mod/scorm/locallib.php');

        $best = null;
        foreach ($scorms as $scorm) {
            // No attempt means no score, and scorm_grade_user() returns 0 for
            // that — which would otherwise be recorded as a genuine zero.
            if (!scorm_get_last_attempt($scorm->id, $userid)) {
                continue;
            }
            $grade = scorm_grade_user($scorm, $userid);
            if (!is_numeric($grade)) {
                continue;
            }
            $grade = (float) $grade;
            $best = $best === null ? $grade : max($best, $grade);
        }
        return $best;
    }

    /**
     * Queue a callback carrying a known time on task.
     *
     * Public so the reconciliation script can hand over what Moodle already
     * recorded before this plugin began reporting it. The score is read the
     * same way a live event reads it, so a replay says nothing a real event
     * would not have said.
     */
    public static function report_time(
        int $courseid,
        int $userid,
        string $status,
        int $seconds
    ): void {
        self::queue($userid, $courseid, $status, self::score_for($courseid, $userid), $seconds);
    }

    /** Queue one progress callback. */
    private static function queue(
        int $userid,
        int $courseid,
        string $status,
        $score,
        ?int $seconds = null
    ): void {
        if (!$userid || !$courseid) {
            return;
        }
        if (!get_config('local_privacient', 'callbackurl')) {
            return; // Nothing configured to notify.
        }

        $task = new \local_privacient\task\progress_callback();
        $task->set_custom_data([
            'userid' => $userid,
            'courseid' => $courseid,
            'status' => $status,
            'score' => $score,
            // Read now rather than when the task runs: cron may be minutes
            // behind, and a second attempt started in between would make the
            // figure describe a different moment than the status beside it.
            'seconds' => $seconds ?? time_spent::for_course($courseid, $userid),
            'occurred' => time(),
        ]);
        \core\task\manager::queue_adhoc_task($task);
    }
}
