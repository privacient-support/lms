<?php
namespace local_privacient;

defined('MOODLE_INTERNAL') || die();

/**
 * Report a SCORM learner's time on task once their session has closed.
 *
 * A package reports time once per session, on the way out: LMSFinish sends
 * cmi.core.session_time, and mod_scorm's player folds it into
 * cmi.(core.)total_time in that same save. Moodle fires no event for the save.
 * Everything this plugin otherwise observes — the SCO opening, completion
 * changing — happens while the session is still open, so the time those events
 * carried covered earlier sessions only. A learner who finished in one sitting
 * showed no time at all, and one who left and came back showed only the first
 * sitting.
 *
 * So the save itself is watched. Every request passes through after_config, and
 * for mod_scorm's datamodel.php posting a total time, a shutdown function runs
 * after the save. It checks that a new total really was stored for the
 * signed-in learner and reports it. The figure is always read back from
 * Moodle's tables, never from the request, so a forged post can at most cause a
 * redundant report of what Moodle already holds.
 */
class scorm_session {

    /** mod_scorm's tracking endpoint. LMSCommit and LMSFinish both post here. */
    private const DATAMODEL = '/mod/scorm/datamodel.php';

    /**
     * Only LMSFinish posts a total time, including the beacon that carries it
     * when the page unloads, so its presence separates the end of a session from
     * a mid-session commit. SCORM 1.2 and 2004 name it differently, and the
     * player writes "." as "__" so that PHP does not rewrite the field names.
     */
    private const TOTAL_TIME_FIELDS = ['cmi__core__total_time', 'cmi__total_time'];

    /** The same two elements, as stored. */
    private const TOTAL_TIME_ELEMENTS = ['cmi.core.total_time', 'cmi.total_time'];

    /**
     * Arrange a report for after this request, if it is a SCORM session ending.
     *
     * Runs on every request, so it does nothing more than compare strings until
     * it is sure this is the save it is looking for.
     */
    public static function after_config(\core\hook\after_config $hook): void {
        global $SCRIPT;

        if ($SCRIPT !== self::DATAMODEL || empty($_POST)) {
            return;
        }
        if (!array_intersect(self::TOTAL_TIME_FIELDS, array_keys($_POST))) {
            return;
        }
        $scoid = optional_param('scoid', 0, PARAM_INT);
        $attempt = optional_param('attempt', 0, PARAM_INT);
        if (!$scoid || !$attempt) {
            return;
        }
        // Taken before datamodel.php writes anything, so a total stored by this
        // request is stamped at or after it.
        \core_shutdown_manager::register_function(
            [self::class, 'session_saved'],
            [$scoid, $attempt, time()]
        );
    }

    /**
     * After the save: if a new total time was stored, report it.
     *
     * Checking what was stored also checks everything datamodel.php enforces
     * before it writes (sign-in, sesskey, the savetrack capability), with no
     * need to repeat those rules here. mod_scorm only touches a value's
     * timemodified when the value changes, so a session that added no time
     * sends nothing.
     */
    public static function session_saved(int $scoid, int $attempt, int $since): void {
        global $DB, $USER;

        if (empty($USER->id) || isguestuser()) {
            return;
        }
        $userid = (int) $USER->id;

        $scorm = $DB->get_record_sql(
            "SELECT s.id, s.course
               FROM {scorm} s
               JOIN {scorm_scoes} sc ON sc.scorm = s.id
              WHERE sc.id = :scoid",
            ['scoid' => $scoid]
        );
        if (!$scorm) {
            return;
        }

        [$insql, $params] = $DB->get_in_or_equal(self::TOTAL_TIME_ELEMENTS, SQL_PARAMS_NAMED, 'el');
        $stored = $DB->record_exists_sql(
            "SELECT 1
               FROM {scorm_scoes_value} v
               JOIN {scorm_attempt} a ON a.id = v.attemptid
               JOIN {scorm_element} e ON e.id = v.elementid
              WHERE a.userid = :userid
                AND a.scormid = :scormid
                AND a.attempt = :attempt
                AND v.scoid = :scoid
                AND v.timemodified >= :since
                AND e.element {$insql}",
            $params + [
                'userid' => $userid,
                'scormid' => $scorm->id,
                'attempt' => $attempt,
                'scoid' => $scoid,
                'since' => $since,
            ]
        );
        if (!$stored) {
            return;
        }

        observer::scorm_session_closed((int) $scorm->course, $userid);
    }
}
