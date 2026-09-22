<?php
/**
 * The page a learner meets before a questionnaire.
 *
 * mod_quiz's own view.php is a Moodle page: a left-aligned heading, a grades
 * table, and an "Attempt quiz" button among site furniture. A learner arriving
 * from the portal has never seen Moodle and should not start here, so this
 * replaces it — the same treatment the video player gets, for the same reason.
 *
 * Only the *landing* is replaced. Sitting the paper, marking it, resuming a
 * dropped connection and recording the grade all remain mod_quiz's job; the
 * form below simply posts to Moodle's own startattempt.php.
 */
require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');
// mod_quiz's locallib does not pull gradelib in, and the pass mark lives on the
// grade item rather than on the quiz row.
require_once($CFG->libdir . '/gradelib.php');

$cmid = required_param('cmid', PARAM_INT);

[$course, $cm] = get_course_and_cm_from_cmid($cmid, 'quiz');
// Set before require_login, so that anything it decides to do — an enrolment
// prompt, a login redirect — carries this page as the place to come back to.
$PAGE->set_url('/local/privacient/quiz.php', ['cmid' => $cmid]);
require_login($course, false, $cm);

$context = context_module::instance($cm->id);
require_capability('mod/quiz:view', $context);
$quizobj = \mod_quiz\quiz_settings::create($cm->instance, $USER->id);
$quiz = $quizobj->get_quiz();

$PAGE->set_context($context);
$PAGE->set_cm($cm, $course);
$PAGE->set_pagelayout('embedded');
$PAGE->set_title(
    format_string($cm->name) . ' - ' . get_string('portaltitle', 'local_privacient'),
    false
);
$PAGE->set_heading(format_string($course->fullname));
if (isset($PAGE->activityheader)) {
    $PAGE->activityheader->disable();
}

// What this learner has done so far. An unfinished attempt is resumed rather
// than restarted — a half-answered paper is not something to throw away.
$attempts = quiz_get_user_attempts($quiz->id, $USER->id, 'all', true);
$inprogress = null;
$best = null;
foreach ($attempts as $attempt) {
    if ($attempt->state === \mod_quiz\quiz_attempt::IN_PROGRESS) {
        $inprogress = $attempt;
    } else if ($attempt->sumgrades !== null) {
        $scaled = quiz_rescale_grade($attempt->sumgrades, $quiz, false);
        if ($best === null || $scaled > $best) {
            $best = $scaled;
        }
    }
}
$used = count($attempts);
$allowed = (int) $quiz->attempts; // 0 is unlimited.
$exhausted = $allowed > 0 && $used >= $allowed && !$inprogress;

$gradeitem = grade_get_grades($course->id, 'mod', 'quiz', $quiz->id, $USER->id);
$item = $gradeitem ? reset($gradeitem->items) : null;
$passmark = $item && $item->gradepass > 0
    ? (int) round(($item->gradepass / max(1, (float) $item->grademax)) * 100)
    : 0;
$passed = $best !== null && $passmark > 0 && $best >= $passmark;

$questioncount = count($quizobj->get_structure()->get_slots());
$portal = trim((string) get_config('local_privacient', 'portalurl'));

// In the native app there is no portal behind this page to return to — the app
// closes the player itself — so the link at the foot is left out. See
// local_privacient\app_client.
if (\local_privacient\app_client::is_app_request()) {
    $portal = '';
}

echo $OUTPUT->header();
?>
<style>
  body, #page, #page-content, #region-main { background: #f4f7fb; }
  #region-main { border: 0; box-shadow: none; padding: 0; }
  .pvq {
    max-width: 640px; margin: 0 auto; padding: 40px 20px 56px;
    font-family: system-ui, -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    color: #18181b;
  }
  .pvq-card {
    background: #fff; border: 1px solid #e4e4e7; border-radius: 16px;
    padding: 32px; text-align: center; box-shadow: 0 1px 3px rgba(24,24,27,.06);
  }
  .pvq-badge {
    display: inline-block; padding: 4px 12px; border-radius: 999px;
    background: #ccfbf1; color: #0f766e; font-size: .72rem; font-weight: 700;
    letter-spacing: .06em; text-transform: uppercase;
  }
  .pvq-title { font-size: 1.6rem; font-weight: 600; letter-spacing: -.01em; margin: 14px 0 8px; }
  .pvq-intro { color: #52525b; font-size: .95rem; margin: 0 0 24px; }
  /* The three numbers that answer "what am I about to do?" */
  .pvq-facts {
    display: grid; grid-template-columns: repeat(3, 1fr); gap: 1px;
    background: #e4e4e7; border: 1px solid #e4e4e7; border-radius: 12px;
    overflow: hidden; margin: 0 0 26px;
  }
  .pvq-fact { background: #fff; padding: 14px 8px; }
  .pvq-fact dt {
    font-size: .68rem; font-weight: 600; text-transform: uppercase;
    letter-spacing: .05em; color: #71717a; margin: 0 0 4px;
  }
  .pvq-fact dd { margin: 0; font-size: 1.25rem; font-weight: 600; }
  .pvq-btn {
    display: inline-block; padding: 13px 30px; border-radius: 10px;
    background: #0f766e; color: #fff; font-size: .98rem; font-weight: 600;
    border: 0; cursor: pointer; text-decoration: none;
  }
  .pvq-btn:hover { background: #115e59; color: #fff; text-decoration: none; }
  .pvq-note { margin: 16px 0 0; font-size: .85rem; color: #71717a; }
  .pvq-result {
    display: flex; align-items: center; justify-content: center; gap: 10px;
    padding: 14px 18px; border-radius: 12px; margin: 0 0 22px; font-size: .95rem;
  }
  .pvq-result.is-pass { background: #ecfdf5; color: #065f46; border: 1px solid #a7f3d0; }
  .pvq-result.is-fail { background: #fffbeb; color: #92400e; border: 1px solid #fde68a; }
  .pvq-score { font-size: 1.5rem; font-weight: 700; }
  .pvq-back { display: block; margin-top: 26px; text-align: center; }
  .pvq-back a { color: #52525b; font-size: .88rem; text-decoration: none; }
  .pvq-back a:hover { color: #18181b; text-decoration: underline; }
  @media (max-width: 560px) {
    .pvq { padding: 24px 14px 40px; }
    .pvq-card { padding: 24px 18px; }
    .pvq-facts { grid-template-columns: 1fr; }
  }
</style>
<div class="pvq">
  <div class="pvq-card">
    <span class="pvq-badge"><?php echo s(get_string('quizbadge', 'local_privacient')); ?></span>
    <h1 class="pvq-title"><?php echo format_string($cm->name); ?></h1>

    <?php if ($best !== null) { ?>
      <div class="pvq-result <?php echo $passed ? 'is-pass' : 'is-fail'; ?>">
        <span class="pvq-score"><?php echo (int) round($best); ?>%</span>
        <span>
          <?php
          echo $passed
              ? s(get_string('quizpassed', 'local_privacient'))
              : s(get_string('quizfailed', 'local_privacient', $passmark));
          ?>
        </span>
      </div>
    <?php } else { ?>
      <p class="pvq-intro"><?php echo s(get_string('quizintro', 'local_privacient')); ?></p>
    <?php } ?>

    <dl class="pvq-facts">
      <div class="pvq-fact">
        <dt><?php echo s(get_string('quizquestions', 'local_privacient')); ?></dt>
        <dd><?php echo $questioncount; ?></dd>
      </div>
      <div class="pvq-fact">
        <dt><?php echo s(get_string('quizpassmark', 'local_privacient')); ?></dt>
        <dd><?php echo $passmark > 0 ? $passmark . '%' : '&mdash;'; ?></dd>
      </div>
      <div class="pvq-fact">
        <dt><?php echo s(get_string('quizattempts', 'local_privacient')); ?></dt>
        <dd>
          <?php
          echo $allowed > 0
              ? s(get_string('quizattemptsleft', 'local_privacient', max(0, $allowed - $used)))
              : s(get_string('quizattemptsunlimited', 'local_privacient'));
          ?>
        </dd>
      </div>
    </dl>

    <?php if ($exhausted) { ?>
      <p class="pvq-note"><?php echo s(get_string('quizexhausted', 'local_privacient')); ?></p>
    <?php } else if ($inprogress) { ?>
      <a class="pvq-btn" href="<?php
        echo (new moodle_url('/mod/quiz/attempt.php', [
            'attempt' => $inprogress->id, 'cmid' => $cmid,
        ]))->out(false);
      ?>"><?php echo s(get_string('quizresume', 'local_privacient')); ?></a>
      <p class="pvq-note"><?php echo s(get_string('quizresumenote', 'local_privacient')); ?></p>
    <?php } else { ?>
      <form method="post" action="<?php echo (new moodle_url('/mod/quiz/startattempt.php'))->out(false); ?>">
        <input type="hidden" name="cmid" value="<?php echo $cmid; ?>" />
        <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>" />
        <button type="submit" class="pvq-btn">
          <?php
          echo s($best !== null
              ? get_string('quizretake', 'local_privacient')
              : get_string('quizstart', 'local_privacient'));
          ?>
        </button>
      </form>
    <?php } ?>
  </div>

  <?php if ($portal !== '') { ?>
    <p class="pvq-back">
      <a href="<?php echo s($portal); ?>">
        <?php echo s(get_string('backtotraining', 'local_privacient')); ?>
      </a>
    </p>
  <?php } ?>
</div>
<?php
echo $OUTPUT->footer();
