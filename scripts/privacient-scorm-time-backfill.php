<?php
/**
 * Re-send SCORM time on task that the console never received.
 *
 * Until local_privacient 1.23.1, a SCORM module's time was read while the
 * session was still open, before LMSFinish had stored that session's time.
 * Learners who finished in one sitting show no time, and those who came back
 * later show only their first sitting. Moodle holds the true totals, so this
 * script reports them again for every learner with stored SCORM time, through
 * the same call a closing session now makes.
 *
 * The status sent is Moodle's own completion state. A learner whom Moodle shows
 * as complete but the console does not is completed by the replay, and their
 * campaign's completion email is sent. The dry run lists who would be sent what.
 *
 * Run from the lms/ directory:
 *   docker cp scripts/privacient-scorm-time-backfill.php lms:/tmp/
 *   docker exec lms php /tmp/privacient-scorm-time-backfill.php          # dry run
 *   docker exec lms php /tmp/privacient-scorm-time-backfill.php --run    # queue reports
 *
 * Reports are queued as adhoc tasks, which lms-cron sends within a minute or
 * two. Safe to re-run: the console only ever raises a stored time.
 */

define('CLI_SCRIPT', true);
require('/var/www/html/config.php');
require_once($CFG->libdir . '/clilib.php');

[$options] = cli_get_params(['run' => false, 'help' => false], ['h' => 'help']);
if ($options['help']) {
    echo "Usage: php privacient-scorm-time-backfill.php [--run]\n";
    exit(0);
}

$pairs = $DB->get_recordset_sql(
    "SELECT DISTINCT a.userid, s.course
       FROM {scorm_scoes_value} v
       JOIN {scorm_attempt} a ON a.id = v.attemptid
       JOIN {scorm} s ON s.id = a.scormid
       JOIN {scorm_element} e ON e.id = v.elementid
      WHERE e.element IN ('cmi.core.total_time', 'cmi.total_time')
   ORDER BY s.course, a.userid"
);

$count = 0;
foreach ($pairs as $pair) {
    $seconds = \local_privacient\time_spent::for_course((int) $pair->course, (int) $pair->userid);
    if ($seconds === null) {
        continue;
    }
    $email = $DB->get_field('user', 'email', ['id' => $pair->userid]);
    $status = \local_privacient\observer::scorm_status((int) $pair->course, (int) $pair->userid);
    printf("course %d  %-40s  %-9s  %ds\n", $pair->course, $email, $status, $seconds);
    if ($options['run']) {
        \local_privacient\observer::scorm_session_closed((int) $pair->course, (int) $pair->userid);
    }
    $count++;
}
$pairs->close();

echo $options['run']
    ? "Queued {$count} report(s).\n"
    : "{$count} report(s) would be queued. Re-run with --run to send them.\n";
