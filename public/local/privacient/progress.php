<?php
/**
 * Record that a learner watched a video activity through to the end.
 *
 * This is the ONLY thing that completes a Privacient video. The activity is
 * created with MANUAL completion tracking precisely so that Moodle's own
 * "viewed the page" rule cannot complete it — see publish_content.php.
 *
 * Caveat worth stating plainly: coverage is measured in the browser, so it is
 * advisory rather than proof. A determined learner can forge it. What is
 * enforced here is that the caller is the enrolled learner, that the reported
 * coverage clears the threshold, and that enough wall-clock time has actually
 * elapsed since the player opened — which stops the obvious "POST 100%
 * immediately" bypass without pretending to be tamper-proof. Real assurance
 * needs the assessment step, which is the next thing to build on top of this.
 */
define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');

$cmid = required_param('cmid', PARAM_INT);
$watched = required_param('watched', PARAM_INT);

[$course, $cm] = get_course_and_cm_from_cmid($cmid, 'resource');
require_login($course, false, $cm);
require_sesskey();

echo $OUTPUT->header(); // Sends the JSON content type for AJAX_SCRIPT.

$threshold = (int) (get_config('local_privacient', 'watchthreshold') ?: 95);

$respond = function (bool $complete, string $reason = '') {
    echo json_encode(['complete' => $complete, 'reason' => $reason]);
    die();
};

if ($watched < $threshold) {
    $respond(false, 'below-threshold');
}

// Wall-clock floor: play.php stamps the start, so a client cannot claim a full
// watch the moment the page loads.
$startedkey = 'local_privacient_watchstart_' . $cm->id;
$started = (int) get_user_preferences($startedkey, 0);
$minimum = (int) (get_config('local_privacient', 'minwatchseconds') ?: 10);
if ($started && (time() - $started) < $minimum) {
    $respond(false, 'too-fast');
}

$completion = new completion_info($course);
if (!$completion->is_enabled($cm)) {
    $respond(false, 'completion-disabled');
}

// How long this learner was in the player, recorded before completion so the
// observer that fires on the next line can already read it. A video reports no
// time of its own, and elapsed-since-enrolment would call a five-minute clip a
// fortnight's work.
if ($started) {
    set_user_preference(
        'local_privacient_watchtime_' . $cm->id,
        max(0, time() - $started),
        $USER->id
    );
}

$completion->update_state($cm, COMPLETION_COMPLETE, $USER->id);

// Mirrors what mod_resource would log, so reports and the IOMAD activity trail
// show the view alongside the completion rather than a bare completion record.
\mod_resource\event\course_module_viewed::create([
    'objectid' => $cm->instance,
    'context' => context_module::instance($cm->id),
])->trigger();

$respond(true);
