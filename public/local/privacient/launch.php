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

// A video course is entered through the plugin's own player, not through
// mod/resource/view.php: the player is what measures the watch and reports it
// to progress.php. Anything else (SCORM) uses the normal course page.
$target = new moodle_url('/my/');
if ($courseid) {
    $target = new moodle_url('/course/view.php', ['id' => $courseid]);
    $video = $DB->get_record('local_privacient_content', [
        'courseid' => $courseid,
        'kind' => 'video',
    ], 'id, cmid', IGNORE_MULTIPLE);
    if ($video && $video->cmid && $DB->record_exists('course_modules', ['id' => $video->cmid])) {
        $target = new moodle_url('/local/privacient/play.php', ['cmid' => $video->cmid]);
    }
}
redirect($target);
