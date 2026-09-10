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

    /** Queue one progress callback. */
    private static function queue(int $userid, int $courseid, string $status, $score): void {
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
            'occurred' => time(),
        ]);
        \core\task\manager::queue_adhoc_task($task);
    }
}
