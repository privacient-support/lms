<?php
namespace local_privacient;

defined('MOODLE_INTERNAL') || die();

/**
 * How long a learner actually spent on a course's material.
 *
 * Deliberately not "completed_at minus started_at". A learner who opens a
 * module on Monday and finishes it on Friday spent minutes on it, not four
 * days, and elapsed wall-clock reported as "time spent" would make every
 * unhurried learner look like they had laboured over it. Each activity type is
 * asked for the figure it actually keeps:
 *
 *   - a quiz knows when each attempt opened and closed;
 *   - a SCORM package reports cmi.total_time over the runtime API;
 *   - our own video player stamps the moment it opened, so completing it gives
 *     the time in the player.
 *
 * Anything that keeps no such figure contributes nothing rather than a guess,
 * and a course where nothing reports returns null — which the console shows as
 * "—" rather than as zero.
 */
class time_spent {

    /** Total seconds across every activity in the course, or null if unknown. */
    public static function for_course(int $courseid, int $userid): ?int {
        $total = 0;
        $known = false;

        foreach ([
            self::quiz_seconds($courseid, $userid),
            self::scorm_seconds($courseid, $userid),
            self::video_seconds($courseid, $userid),
        ] as $part) {
            if ($part !== null) {
                $known = true;
                $total += $part;
            }
        }
        return $known ? $total : null;
    }

    /** Every finished attempt, added together: a retake is more time spent. */
    private static function quiz_seconds(int $courseid, int $userid): ?int {
        global $DB;

        $sum = $DB->get_field_sql(
            "SELECT SUM(qa.timefinish - qa.timestart)
               FROM {quiz_attempts} qa
               JOIN {quiz} q ON q.id = qa.quiz
              WHERE q.course = :courseid
                AND qa.userid = :userid
                AND qa.preview = 0
                AND qa.timefinish > 0
                AND qa.timefinish >= qa.timestart",
            ['courseid' => $courseid, 'userid' => $userid]
        );
        return $sum === false || $sum === null ? null : (int) $sum;
    }

    /**
     * cmi.total_time, which the package maintains as a running total.
     *
     * Summed across SCOs but taken at its maximum across attempts: total_time
     * is cumulative by definition, so adding attempts together would count the
     * earlier ones twice.
     */
    private static function scorm_seconds(int $courseid, int $userid): ?int {
        global $CFG, $DB;

        $scorms = $DB->get_records('scorm', ['course' => $courseid], '', 'id');
        if (!$scorms) {
            return null;
        }
        require_once($CFG->dirroot . '/mod/scorm/locallib.php');

        $total = 0;
        $known = false;
        foreach ($scorms as $scorm) {
            $scoes = $DB->get_records('scorm_scoes', ['scorm' => $scorm->id], '', 'id');
            foreach ($scoes as $sco) {
                $attempts = scorm_get_attempt_count($userid, $scorm, true);
                $best = 0;
                for ($attempt = 1; $attempt <= max(1, (int) $attempts); $attempt++) {
                    $track = scorm_get_tracks($sco->id, $userid, $attempt);
                    if (!$track) {
                        continue;
                    }
                    // SCORM 2004 spells it cmi.total_time, 1.2 cmi.core.total_time.
                    $raw = $track->{'cmi.total_time'}
                        ?? $track->{'cmi.core.total_time'}
                        ?? null;
                    if ($raw === null) {
                        continue;
                    }
                    $seconds = self::parse_duration((string) $raw);
                    if ($seconds !== null) {
                        $known = true;
                        $best = max($best, $seconds);
                    }
                }
                $total += $best;
            }
        }
        return $known ? $total : null;
    }

    /**
     * Time in our own player, stamped by progress.php when the video completes.
     *
     * Kept as a user preference rather than a table: it is one integer per
     * module per learner, written once, and read by the observer moments later.
     */
    private static function video_seconds(int $courseid, int $userid): ?int {
        $modinfo = get_fast_modinfo($courseid, $userid);
        $total = 0;
        $known = false;
        foreach ($modinfo->get_cms() as $cm) {
            if ($cm->modname !== 'resource') {
                continue;
            }
            $seconds = get_user_preferences(
                'local_privacient_watchtime_' . $cm->id, null, $userid
            );
            if ($seconds !== null && $seconds !== '') {
                $known = true;
                $total += (int) $seconds;
            }
        }
        return $known ? $total : null;
    }

    /**
     * Seconds from either shape SCORM uses.
     *
     * 1.2 writes "HH:MM:SS.SS"; 2004 writes an ISO 8601 duration such as
     * "PT1M41.09S". Both appear in the wild because a package chooses its own
     * version, so both are read rather than assuming the newer one.
     */
    public static function parse_duration(string $raw): ?int {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        if (preg_match('/^(\d+):(\d{1,2}):(\d{1,2}(?:\.\d+)?)$/', $raw, $m)) {
            return (int) round(((int) $m[1]) * 3600 + ((int) $m[2]) * 60 + (float) $m[3]);
        }
        if (preg_match(
            '/^P(?:(\d+)Y)?(?:(\d+)M)?(?:(\d+)D)?'
            . '(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+(?:\.\d+)?)S)?)?$/',
            $raw,
            $m
        )) {
            // Years and months are not fixed lengths, but a training session
            // lasting either is not a real figure — approximating them keeps
            // the parser total rather than returning null on absurd input.
            $seconds = ((int) ($m[1] ?? 0)) * 31557600
                + ((int) ($m[2] ?? 0)) * 2629800
                + ((int) ($m[3] ?? 0)) * 86400
                + ((int) ($m[4] ?? 0)) * 3600
                + ((int) ($m[5] ?? 0)) * 60
                + (float) ($m[6] ?? 0);
            return (int) round($seconds);
        }
        return null;
    }
}
