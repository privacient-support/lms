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
        self::queue($userid, $event->courseid, $done ? 'completed' : 'started', null);
        return true;
    }

    public static function course_completed(\core\event\base $event): bool {
        self::queue($event->relateduserid ?: $event->userid, $event->courseid, 'completed', null);
        return true;
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
