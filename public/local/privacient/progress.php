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
 * coverage clears the threshold, and — measured on the server's own clock by
 * watch.php's progress reports, across every visit (videos can be resumed) —
 * that the learner has spent at least the threshold's share of the video's real
 * running time actually playing it and has really got that far through it. So
 * fast-forwarding, faster playback or a hand-crafted POST cannot finish a video
 * sooner than it could have been watched; what cannot be proven is that the
 * learner looked at the screen while it played. Real assurance needs the
 * assessment step.
 */
define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');

$cmid = required_param('cmid', PARAM_INT);
$watched = required_param('watched', PARAM_INT);
// The player's final position report, folded in below so the check sees the
// learner's true furthest point rather than one up to a beat stale.
$pos = optional_param('pos', 0, PARAM_FLOAT);
$far = optional_param('far', 0, PARAM_FLOAT);

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

try {
    $duration = \local_privacient\video::duration_for_cm((int) $cm->id);
} catch (\Throwable $e) {
    $duration = null;
}

// The final report, applied under the same rules as every other: the furthest
// point may only have advanced as fast as real time since the last report.
$state = \local_privacient\watch_state::beat(
    (int) $cm->id, (int) $USER->id, (float) $pos, (float) $far, false, $duration
);

// Real playing time, accumulated on the server's clock across every visit —
// so a video watched in two sittings completes, and neither seeking, 2x
// playback nor a hand-crafted POST can finish a ten-minute video in two. The
// bar is the watch threshold's share of the video's running time (read from
// the file, never the browser) less 10% slack, and never below
// `minwatchseconds`. Unknown duration (e.g. WebM) falls back to that minimum.
$minimum = (int) (get_config('local_privacient', 'minwatchseconds') ?: 10);
$required = $minimum;
if ($duration !== null && $duration > 0) {
    $required = max($minimum, (int) floor($duration * ($threshold / 100) * 0.9));
}
if ($state['acc'] < $required) {
    $respond(false, 'too-fast');
}

// And the learner must actually have got that far through it. `far` is
// server-policed (see watch_state), so this does not trust the browser either.
// A couple of seconds' slack absorbs the last report's rounding.
if ($duration !== null && $duration > 0 && $state['far'] < ($duration * $threshold / 100) - 2) {
    $respond(false, 'below-threshold');
}

$completion = new completion_info($course);
if (!$completion->is_enabled($cm)) {
    $respond(false, 'completion-disabled');
}

// How long this learner spent actually watching, across every visit, recorded
// before completion so the observer that fires on the next line can already
// read it. A video reports no time of its own, and elapsed-since-enrolment
// would call a five-minute clip a fortnight's work.
set_user_preference('local_privacient_watchtime_' . $cm->id, $state['acc'], $USER->id);

$completion->update_state($cm, COMPLETION_COMPLETE, $USER->id);

// Complete: nothing left to resume. A revisit to review starts from the top.
\local_privacient\watch_state::clear((int) $cm->id, (int) $USER->id);

// Mirrors what mod_resource would log, so reports and the IOMAD activity trail
// show the view alongside the completion rather than a bare completion record.
\mod_resource\event\course_module_viewed::create([
    'objectid' => $cm->instance,
    'context' => context_module::instance($cm->id),
])->trigger();

$respond(true);
