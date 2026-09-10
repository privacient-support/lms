<?php
/**
 * Log a learner in from a campaign link and drop them on their course.
 *
 * The key is created by `local_privacient_enrol_learner` and carries the target
 * course as its instance. It is consumed on use, so a forwarded link cannot be
 * replayed — which matters because these arrive by email.
 */
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/moodlelib.php');

$keyvalue = required_param('key', PARAM_ALPHANUM);

// validate_user_key() matches on the instance as well as the value, and the
// instance here is the target course — which the caller does not know. So the
// record is located first, then validated properly for expiry and IP.
$stored = $DB->get_record('user_private_key', [
    'script' => 'local_privacient',
    'value' => $keyvalue,
]);
if (!$stored) {
    throw new moodle_exception('invalidkey');
}
$key = validate_user_key($keyvalue, 'local_privacient', $stored->instance);

$user = $DB->get_record('user', ['id' => $key->userid, 'deleted' => 0], '*', MUST_EXIST);
$courseid = (int) $key->instance;

// Single use: consume before establishing the session.
$DB->delete_records('user_private_key', ['id' => $key->id]);

complete_user_login($user);

// Straight to the activity, never through /course/view.php.
//
// That page is gated by IOMAD on company membership, so a learner whose tenant
// is not linked to an IOMAD company is bounced off it to the site home even
// with a valid session and an active enrolment — which looks exactly like "the
// training did not launch". Going directly to the activity sidesteps the gate,
// and also keeps learners off the one Moodle page this flow exists to avoid.
$target = new moodle_url('/my/');
if ($courseid) {
    // Read the module from the course itself rather than from the content row.
    // One content item now has a course per campaign, so `content.courseid`
    // names only the most recent — every earlier campaign would fail to route.
    $item = $DB->get_record_sql(
        "SELECT cm.id AS cmid, m.name AS modname
           FROM {course_modules} cm
           JOIN {modules} m ON m.id = cm.module
          WHERE cm.course = :courseid AND cm.deletioninprogress = 0
       ORDER BY cm.id ASC",
        ['courseid' => $courseid],
        IGNORE_MULTIPLE
    );

    $cmexists = !empty($item->cmid);

    if ($cmexists && $item->modname === 'resource') {
        // Our own player: it is what measures the watch and reports it to
        // progress.php.
        $target = new moodle_url('/local/privacient/play.php', ['cmid' => $item->cmid]);
    } else if ($cmexists && $item->modname === 'scorm') {
        // mod_scorm's own entry point, which with skipview set drops the
        // learner straight into player.php — itself an 'embedded' page, so it
        // carries no Moodle chrome either.
        $target = new moodle_url('/mod/scorm/view.php', ['id' => $item->cmid]);
    } else {
        $target = new moodle_url('/course/view.php', ['id' => $courseid]);
    }
}
redirect($target);
