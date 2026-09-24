<?php
/**
 * The video player's progress reports ("beats"): where the learner is and how
 * far they have got, every few seconds while playing, on pause, and when they
 * leave the page (sent with navigator.sendBeacon).
 *
 * This is what lets a learner resume a video on a later visit, and it is where
 * real watching time is measured — see \local_privacient\watch_state for the
 * rules. It never completes anything; progress.php does that.
 */
define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');

$cmid = required_param('cmid', PARAM_INT);
$pos = optional_param('pos', 0, PARAM_FLOAT);
$far = optional_param('far', 0, PARAM_FLOAT);
$playing = optional_param('playing', 0, PARAM_BOOL);

[$course, $cm] = get_course_and_cm_from_cmid($cmid, 'resource');
require_login($course, false, $cm);
require_sesskey();
require_capability('mod/resource:view', context_module::instance($cm->id));

echo $OUTPUT->header(); // Sends the JSON content type for AJAX_SCRIPT.

// Already complete: nothing to resume, so nothing to save. This also stops a
// report still in flight when the video finished (the pause at its end) from
// recreating the state progress.php has just cleared.
$completion = new completion_info($course);
if ($completion->is_enabled($cm)) {
    $data = $completion->get_data($cm, false, $USER->id);
    if (in_array((int) $data->completionstate, [COMPLETION_COMPLETE, COMPLETION_COMPLETE_PASS], true)) {
        echo json_encode(['complete' => true]);
        die();
    }
}

$state = \local_privacient\watch_state::beat(
    (int) $cm->id,
    (int) $USER->id,
    (float) $pos,
    (float) $far,
    (bool) $playing,
    \local_privacient\video::duration_for_cm((int) $cm->id)
);

echo json_encode(['pos' => $state['pos'], 'far' => $state['far']]);
