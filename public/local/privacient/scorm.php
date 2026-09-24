<?php
/**
 * The way into a SCORM module: the learner's own company logo first, then the
 * course.
 *
 * Videos get a branded intro inside our own player (play.php). SCORM content
 * plays in mod_scorm's player, which is not ours to decorate, so the intro
 * happens here instead, one step before it: the same slate — logo, "Security
 * training for <Company>", Start — and on Start the same short logo intro, then
 * straight on to mod_scorm's view.php, which (published with skipview=ALWAYS)
 * drops the learner into the player.
 *
 * launch.php routes SCORM modules here. When no branding resolves (no company,
 * console unreachable and no company name) there is nothing to show, so this
 * page redirects straight through and the learner sees exactly what they did
 * before it existed.
 */
require_once(__DIR__ . '/../../config.php');

$cmid = required_param('cmid', PARAM_INT);

[$course, $cm] = get_course_and_cm_from_cmid($cmid, 'scorm');
// The same gate mod_scorm's view.php applies; the player re-checks on its own.
require_login($course, false, $cm);

$target = new moodle_url('/mod/scorm/view.php', ['id' => $cm->id]);

// Purely cosmetic, exactly as in play.php: any failure means no slate, never a
// learner stuck in front of their training.
$branding = null;
try {
    $branding = \local_privacient\branding::for_company(
        \local_privacient\branding::company_for((int) $USER->id, (int) $course->id)
    );
} catch (\Throwable $e) {
    debugging('SCORM intro branding unavailable: ' . $e->getMessage(), DEBUG_DEVELOPER);
}
if (!$branding) {
    redirect($target);
}

$context = context_module::instance($cm->id);
$PAGE->set_url('/local/privacient/scorm.php', ['cmid' => $cmid]);
$PAGE->set_context($context);
$PAGE->set_cm($cm, $course);
// No Moodle chrome, as on play.php and quiz.php: the learner's frame is the
// Privacient portal (or the app), not Moodle.
$PAGE->set_pagelayout('embedded');
$PAGE->set_title(
    format_string($cm->name) . ' - ' . get_string('portaltitle', 'local_privacient'),
    false
);
$PAGE->set_heading(format_string($course->fullname));
$PAGE->activityheader->disable();

echo $OUTPUT->header();

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
  .pv-title { font-size: 1.75rem; line-height: 1.25; font-weight: 600; color: #18181b; margin: 0 0 20px; }
  /* The frame the slate sits in, sized like the video player's so both kinds
     of training open the same way. */
  .pv-stage {
    position: relative;
    min-height: clamp(300px, 56.25vw, 506px);
    border-radius: 12px; overflow: hidden;
    box-shadow: 0 8px 28px rgba(24, 24, 27, .18);
  }
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
echo \local_privacient\branding::slate_css();

echo html_writer::start_div('pv-wrap');
echo html_writer::tag('h1', format_string($cm->name), ['class' => 'pv-title']);
echo html_writer::start_div('pv-stage');
// Start is a real link to the course, so it works without script; the script
// below intercepts it to play the intro first.
echo \local_privacient\branding::slate_html(
    $branding,
    get_string('introstarttraining', 'local_privacient'),
    $target
);
echo html_writer::end_div();

// Same rule as play.php: back to the portal, and no link at all inside the app.
if (!\local_privacient\app_client::is_app_request()) {
    $portal = trim((string) get_config('local_privacient', 'portalurl'));
    if ($portal !== '') {
        echo html_writer::link($portal, get_string('backtotraining', 'local_privacient'), ['class' => 'pv-back']);
    }
}
echo html_writer::end_div();

$PAGE->requires->js_amd_inline(<<<'JS'
require([], function() {
    var slate = document.getElementById('privacient-slate');
    var start = document.getElementById('privacient-start');
    if (!slate || !start) {
        return;
    }
    var reduced = window.matchMedia &&
        window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var introMs = reduced ? 1200 : 2600;
    var going = false;

    start.addEventListener('click', function(e) {
        e.preventDefault();
        if (going) {
            return;
        }
        going = true;
        slate.style.setProperty('--pv-intro-ms', introMs + 'ms');
        slate.classList.add('is-intro');
        window.setTimeout(function() {
            window.location.assign(start.href);
        }, introMs);
    });

    // Back from the course restores this page from the back-forward cache
    // mid-intro, with Start hidden. Put it back as the learner first saw it.
    window.addEventListener('pageshow', function(e) {
        if (e.persisted) {
            going = false;
            slate.classList.remove('is-intro');
        }
    });
});
JS);

echo $OUTPUT->footer();
