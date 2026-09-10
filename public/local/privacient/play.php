<?php
/**
 * Play a video activity and complete it only once it has actually been watched.
 *
 * Moodle's `mod_resource` has exactly one automatic completion condition —
 * "student must view this activity" — which fires the instant the page opens.
 * For compliance training that is worthless: it certifies that someone clicked
 * a link, not that they watched anything.
 *
 * So the resource is created with MANUAL tracking (see publish_content.php),
 * which stops Moodle completing it on view, and learners are routed here
 * instead of to mod/resource/view.php. This page reports coverage back to
 * progress.php, which is the only thing that marks the activity complete.
 *
 * Coverage is measured as the set of whole seconds actually played, so seeking
 * to the end fills one bucket rather than the whole bar.
 */
require_once(__DIR__ . '/../../config.php');

$cmid = required_param('cmid', PARAM_INT);

[$course, $cm] = get_course_and_cm_from_cmid($cmid, 'resource');
require_login($course, false, $cm);

$context = context_module::instance($cm->id);
$resource = $DB->get_record('resource', ['id' => $cm->instance], '*', MUST_EXIST);

// The playable file: mod_resource keeps one file per activity in `content`.
$fs = get_file_storage();
$files = $fs->get_area_files($context->id, 'mod_resource', 'content', 0, 'sortorder DESC, id ASC', false);
if (empty($files)) {
    throw new moodle_exception('filenotfound', 'error');
}
$file = reset($files);
$fileurl = moodle_url::make_pluginfile_url(
    $context->id, 'mod_resource', 'content', $resource->revision,
    $file->get_filepath(), $file->get_filename()
);

$threshold = (int) (get_config('local_privacient', 'watchthreshold') ?: 95);

$completion = new completion_info($course);
$alreadydone = false;
if ($completion->is_enabled($cm)) {
    $data = $completion->get_data($cm, false, $USER->id);
    $alreadydone = in_array((int) $data->completionstate, [COMPLETION_COMPLETE, COMPLETION_COMPLETE_PASS], true);
}

// Wall-clock floor for progress.php: stamp when this player was opened.
if (!$alreadydone) {
    set_user_preference('local_privacient_watchstart_' . $cm->id, time());
}

$PAGE->set_url('/local/privacient/play.php', ['cmid' => $cmid]);
$PAGE->set_context($context);
$PAGE->set_cm($cm, $course);
// 'embedded' strips Moodle's navigation, header and branding entirely. The
// learner's chrome belongs to the Privacient portal; this page is only the
// player, so showing Moodle's would be showing them a system they never signed
// in to and cannot use.
$PAGE->set_pagelayout('embedded');
$PAGE->set_title(format_string($cm->name));
$PAGE->set_heading(format_string($course->fullname));

echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($cm->name));

if (trim(strip_tags($resource->intro)) !== '') {
    echo $OUTPUT->box(format_module_intro('resource', $resource, $cm->id), 'generalbox');
}

echo html_writer::start_div('local-privacient-player');
echo html_writer::empty_tag('video', [
    'id' => 'privacient-player',
    'src' => $fileurl->out(false),
    'controls' => 'controls',
    'controlsList' => 'nodownload',
    'preload' => 'metadata',
    'playsinline' => 'playsinline',
    'style' => 'width:100%;max-width:900px;background:#000;',
    'data-cmid' => $cm->id,
    'data-threshold' => $threshold,
    'data-sesskey' => sesskey(),
    'data-endpoint' => (new moodle_url('/local/privacient/progress.php'))->out(false),
    'data-done' => $alreadydone ? 1 : 0,
]);

echo html_writer::div(
    $alreadydone
        ? get_string('watchcomplete', 'local_privacient')
        : get_string('watchprogress', 'local_privacient', '0'),
    'alert ' . ($alreadydone ? 'alert-success' : 'alert-info') . ' mt-3',
    ['id' => 'privacient-status', 'role' => 'status', 'aria-live' => 'polite']
);
echo html_writer::end_div();

// Back to the portal, not to Moodle's course page: the course page is exactly
// the Moodle surface this whole flow exists to keep learners out of.
$portal = trim((string) get_config('local_privacient', 'portalurl'));
echo html_writer::div(
    html_writer::link(
        $portal !== '' ? $portal : (new moodle_url('/course/view.php', ['id' => $course->id]))->out(false),
        get_string('backtotraining', 'local_privacient'),
        ['class' => 'btn btn-secondary']
    ),
    'mt-3'
);

// Strings the inline module needs, resolved server-side.
$PAGE->requires->strings_for_js(['watchprogress', 'watchcomplete', 'watchfailed'], 'local_privacient');
$PAGE->requires->js_amd_inline(<<<'JS'
require(['core/str'], function(str) {
    var video = document.getElementById('privacient-player');
    var status = document.getElementById('privacient-status');
    if (!video || !status) {
        return;
    }

    var threshold = parseInt(video.dataset.threshold, 10) || 95;
    var sent = video.dataset.done === '1';
    // Whole seconds actually played. A Set, so re-watching does not inflate it
    // and a seek to the end adds exactly one second.
    var seen = {};
    var seenCount = 0;

    var pct = function() {
        var total = Math.floor(video.duration || 0);
        if (!total) {
            return 0;
        }
        return Math.min(100, Math.round((seenCount / total) * 100));
    };

    var render = function(value) {
        str.get_string('watchprogress', 'local_privacient', String(value)).done(function(s) {
            if (!sent) {
                status.textContent = s;
            }
        });
    };

    var report = function() {
        if (sent) {
            return;
        }
        sent = true;
        var body = new FormData();
        body.append('cmid', video.dataset.cmid);
        body.append('sesskey', video.dataset.sesskey);
        body.append('watched', String(pct()));
        fetch(video.dataset.endpoint, {method: 'POST', body: body, credentials: 'same-origin'})
            .then(function(r) { return r.json(); })
            .then(function(data) {
                var key = data && data.complete ? 'watchcomplete' : 'watchfailed';
                status.className = 'alert ' + (data && data.complete ? 'alert-success' : 'alert-warning') + ' mt-3';
                return str.get_string(key, 'local_privacient').done(function(s) {
                    status.textContent = s;
                });
            })
            .catch(function() {
                sent = false; // Let 'ended' try again.
            });
    };

    video.addEventListener('timeupdate', function() {
        var second = Math.floor(video.currentTime);
        if (!seen[second]) {
            seen[second] = true;
            seenCount++;
        }
        var value = pct();
        render(value);
        if (value >= threshold) {
            report();
        }
    });

    // Short or oddly-encoded files may never reach the threshold by sampling.
    video.addEventListener('ended', report);
});
JS);

echo $OUTPUT->footer();
