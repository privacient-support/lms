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
require_capability('mod/resource:view', $context);
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
// `false` suppresses Moodle's " | <site shortname>" suffix. Learners arrive
// from the portal, whose tabs read "<page> - Security Training"; a tab that
// suddenly named a different system would read as having left the product.
$PAGE->set_title(
    format_string($cm->name) . ' - ' . get_string('portaltitle', 'local_privacient'),
    false
);
$PAGE->set_heading(format_string($course->fullname));

// Drop Moodle's activity header. It is not merely redundant with the title
// below it — it also renders the manual completion toggle, and that button
// marks the module done on a single click, without watching anything. Leaving
// it on this page would hand every learner a one-click bypass of the very
// thing the player exists to measure.
$PAGE->activityheader->disable();

echo $OUTPUT->header();

// The 'embedded' layout deliberately drops Moodle's navigation, and with it the
// theme's container and grid rules — so this page must bring its own. Styling
// it here rather than leaning on the theme also keeps it looking like the
// portal the learner came from, instead of like Moodle with the chrome removed.
echo <<<'CSS'
<style>
  body, #page, #region-main { background: #f4f7fb; }
  .pv-wrap {
    max-width: 900px;
    margin: 0 auto;
    padding: 40px 20px 56px;
    text-align: center;
    font-family: system-ui, -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
  }
  .pv-title { font-size: 1.75rem; line-height: 1.25; font-weight: 600; color: #18181b; margin: 0 0 8px; }
  .pv-intro { color: #52525b; font-size: .95rem; margin: 0 auto 28px; max-width: 620px; }
  .pv-video {
    background: #000; border-radius: 12px; overflow: hidden;
    box-shadow: 0 8px 28px rgba(24, 24, 27, .18);
  }
  .pv-video video { display: block; width: 100%; height: auto; }
  .pv-status {
    margin: 20px auto 0; max-width: 620px; padding: 12px 16px;
    border-radius: 10px; font-size: .92rem;
  }
  .pv-status.is-progress { background: #e6f4fe; color: #14506e; }
  .pv-status.is-done { background: #e7f7ee; color: #14532d; }
  .pv-status.is-failed { background: #fef3c7; color: #78350f; }
  .pv-back {
    display: inline-block; margin-top: 28px; padding: 10px 18px;
    border: 1px solid #d4d4d8; border-radius: 10px; background: #fff;
    color: #3f3f46; font-size: .9rem; font-weight: 500; text-decoration: none;
  }
  .pv-back:hover { background: #f4f4f5; color: #18181b; text-decoration: none; }
  @media (max-width: 600px) {
    .pv-wrap { padding: 24px 14px 40px; }
    .pv-title { font-size: 1.4rem; }
  }
</style>
CSS;

echo html_writer::start_div('pv-wrap');
echo html_writer::tag('h1', format_string($cm->name), ['class' => 'pv-title']);

if (trim(strip_tags($resource->intro)) !== '') {
    echo html_writer::div(
        format_module_intro('resource', $resource, $cm->id),
        'pv-intro'
    );
}

echo html_writer::start_div('pv-video');
echo html_writer::empty_tag('video', [
    'id' => 'privacient-player',
    'src' => $fileurl->out(false),
    'controls' => 'controls',
    'controlsList' => 'nodownload',
    'preload' => 'metadata',
    'playsinline' => 'playsinline',
    'data-cmid' => $cm->id,
    'data-threshold' => $threshold,
    'data-sesskey' => sesskey(),
    'data-endpoint' => (new moodle_url('/local/privacient/progress.php'))->out(false),
    'data-done' => $alreadydone ? 1 : 0,
]);
echo html_writer::end_div();

echo html_writer::div(
    $alreadydone
        ? get_string('watchcomplete', 'local_privacient')
        : get_string('watchprogress', 'local_privacient', '0'),
    'pv-status ' . ($alreadydone ? 'is-done' : 'is-progress'),
    ['id' => 'privacient-status', 'role' => 'status', 'aria-live' => 'polite']
);

// Back to the portal, not to Moodle's course page: the course page is exactly
// the Moodle surface this whole flow exists to keep learners out of. In the
// native app there is no portal to go back to — it closes the player itself —
// so the link is left out entirely rather than leading somewhere confusing.
if (!\local_privacient\app_client::is_app_request()) {
    $portal = trim((string) get_config('local_privacient', 'portalurl'));
    echo html_writer::link(
        $portal !== '' ? $portal : (new moodle_url('/course/view.php', ['id' => $course->id]))->out(false),
        get_string('backtotraining', 'local_privacient'),
        ['class' => 'pv-back']
    );
}

echo html_writer::end_div();

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
                status.className = 'pv-status ' + (data && data.complete ? 'is-done' : 'is-failed');
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
