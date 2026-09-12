<?php
namespace local_privacient;

/**
 * Keep learners inside the Privacient portal.
 *
 * Learners are signed in to IOMAD only as a side effect of playing a module:
 * they never chose a Moodle account, have no password for one, and Moodle's own
 * dashboard shows them a system they cannot otherwise use. Landing there — by
 * following an old link, or by Moodle redirecting after logout — reads as a
 * broken product.
 *
 * Only *browsing* pages are redirected. Anything that is or serves training
 * content is left alone, because breaking playback to tidy up navigation would
 * trade a cosmetic problem for a real one.
 */
class hook_callbacks {

    /** Moodle pages a learner has no reason to see. */
    private const BROWSING_PAGES = [
        '/my/',
        '/my/index.php',
        '/my/courses.php',
        '/index.php',
        '/course/index.php',
        '/user/profile.php',
        '/user/index.php',
        '/badges/mybadges.php',
        '/grade/report/overview/index.php',
        '/calendar/view.php',
    ];

    /**
     * Put the product's own icon on learner-facing pages.
     *
     * The theme's favicon is Moodle's, which is the one thing on this page that
     * still says "you are in a different system". The head template emits the
     * theme icon before this hook's output, and a later declaration wins, so no
     * theme override — and no change to what administrators see — is needed.
     *
     * The image ships inside the plugin rather than being fetched from the
     * portal: a tab icon should not depend on another service being reachable.
     */
    /**
     * True for a signed-in learner: not staff, not a bot, a real page render.
     *
     * Shared so the redirect and the chrome changes cannot disagree about who
     * counts as a learner — if they did, someone would be redirected but still
     * shown Moodle's furniture, or the reverse.
     */
    private static function is_learner(): bool {
        global $USER;

        if (CLI_SCRIPT || AJAX_SCRIPT || WS_SERVER || during_initial_install()) {
            return false;
        }
        if (empty($USER->id) || isguestuser() || !isloggedin()) {
            return false;
        }

        $syscontext = \context_system::instance();
        return !is_siteadmin()
            && !has_capability('moodle/site:config', $syscontext)
            && !has_capability('moodle/course:manageactivities', $syscontext)
            && !has_capability('block/iomad_company_admin:company_add', $syscontext);
    }

    /** True when this request is a learner sitting in a SCORM package. */
    private static function is_learner_scorm_page(): bool {
        global $PAGE;

        if (strpos((string) $PAGE->url, '/mod/scorm/') === false) {
            return false;
        }
        return self::is_learner();
    }

    /**
     * True when this request is a learner taking a generated questionnaire.
     *
     * Covers every page of the attempt — the start page, the questions, the
     * summary before submitting and the review afterwards — because a learner
     * who is dropped into Moodle's own styling at any one of them has been
     * dropped into Moodle.
     */
    private static function is_learner_quiz_page(): bool {
        global $PAGE;

        if (strpos((string) $PAGE->url, '/mod/quiz/') === false) {
            return false;
        }
        return self::is_learner();
    }

    /** Either of the two module types whose own pages a learner sees. */
    private static function is_learner_module_page(): bool {
        return self::is_learner_scorm_page() || self::is_learner_quiz_page();
    }

    /**
     * Replace Moodle's "Exit activity" with a way back to the portal.
     *
     * Moodle's button returns to the course page — the one Moodle surface this
     * whole flow exists to keep learners out of. Hiding it without offering a
     * replacement would strand them in the package with no way out, so the two
     * changes belong together rather than as a tidy-up and a regression.
     */
    public static function before_standard_top_of_body_html_generation(
        \core\hook\output\before_standard_top_of_body_html_generation $hook
    ): void {
        if (!self::is_learner_module_page()) {
            return;
        }
        $portal = trim((string) get_config('local_privacient', 'portalurl'));
        if ($portal === '') {
            return;
        }
        $label = get_string('backtotraining', 'local_privacient');
        $back = '<a class="pv-back" href="' . s($portal) . '">' . s($label) . '</a>';

        // Mid-paper, the back link alone is not enough: Moodle's own question
        // navigator is hidden, so without this a learner has no way of knowing
        // how many questions are left. One question to a page makes that the
        // difference between a short task and an open-ended one.
        $position = self::quiz_attempt_position();
        if ($position === null) {
            $hook->add_html('<div class="pv-back-bar">' . $back . '</div>');
            return;
        }

        [$name, $current, $total] = $position;
        $percent = (int) round(($current / max(1, $total)) * 100);
        $step = get_string('quizstepof', 'local_privacient', (object) [
            'current' => $current,
            'total' => $total,
        ]);
        $hook->add_html(
            '<div class="pv-quiz-head">'
            . '<div class="pv-quiz-head-row">'
            . '<div><span class="pv-quiz-title">' . s($name) . '</span>'
            . '<span class="pv-quiz-step">' . s($step) . '</span></div>'
            . $back
            . '</div>'
            . '<div class="pv-quiz-track" role="progressbar"'
            . ' aria-valuenow="' . $current . '" aria-valuemin="1"'
            . ' aria-valuemax="' . $total . '" aria-label="' . s($step) . '">'
            . '<span style="width:' . $percent . '%"></span>'
            . '</div>'
            . '</div>'
        );
    }

    /**
     * Where in the paper this request is: [quiz name, question number, total].
     *
     * Null for anything that is not a live attempt page — the summary and the
     * review are whole-paper views, and a "question 3 of 5" above them would be
     * describing a position the learner is no longer in.
     */
    private static function quiz_attempt_position(): ?array {
        global $PAGE;

        $path = parse_url((string) $PAGE->url, PHP_URL_PATH) ?: '';
        if (substr($path, -21) !== '/mod/quiz/attempt.php') {
            return null;
        }
        $attemptid = optional_param('attempt', 0, PARAM_INT);
        if (!$attemptid) {
            return null;
        }
        try {
            $attempt = \mod_quiz\quiz_attempt::create($attemptid);
            $all = $attempt->get_slots();
            $here = $attempt->get_slots(optional_param('page', 0, PARAM_INT));
        } catch (\Throwable $e) {
            // A header is not worth failing a page over.
            return null;
        }
        if (!$all || !$here) {
            return null;
        }
        $first = array_search(reset($here), array_values($all), true);
        return [
            $attempt->get_quiz_name(),
            $first === false ? 1 : ((int) $first + 1),
            count($all),
        ];
    }

    public static function before_standard_head_html_generation(
        \core\hook\output\before_standard_head_html_generation $hook
    ): void {
        global $PAGE, $OUTPUT;

        $onplugin = strpos((string) $PAGE->url, '/local/privacient/') !== false;
        if (!$onplugin && !self::is_learner_module_page()) {
            return;
        }
        // Activity pages get the product icon too, plus the CSS that removes
        // Moodle's own chrome and restyles what is left to match the portal.
        if (self::is_learner_scorm_page()) {
            $hook->add_html(self::scorm_chrome_css());
        }
        if (self::is_learner_quiz_page()) {
            $hook->add_html(self::quiz_chrome_css());
        }

        $icon = $OUTPUT->image_url('favicon', 'local_privacient')->out(false);
        // `sizes` and `type` are not decoration: where two icons declare the
        // same relation, browsers pick the better-described candidate rather
        // than simply the last one, so this does not rely on document order.
        // Declaring a second icon is not enough. The theme has already emitted
        // its own in the template above, and with two competing declarations
        // browsers choose inconsistently — which showed up as a broken tab
        // icon rather than either image. Drop the others so exactly one
        // remains, which is the only way to make the outcome deterministic
        // without overriding the theme for administrators too.
        $hook->add_html(
            '<link rel="icon" id="pv-favicon" type="image/png" sizes="256x256" href="'
            . s($icon) . '" />' . "\n"
            . '<script>(function(){try{'
            . 'document.querySelectorAll(\'link[rel~="icon"]\').forEach(function(l){'
            . 'if(l.id!=="pv-favicon"){l.parentNode.removeChild(l);}'
            . '});}catch(e){}})();</script>' . "\n"
        );
    }

    /** Chrome removal for a learner inside a SCORM package. */
    private static function scorm_chrome_css(): string {
        return <<<'CSS'
<style>
  /* Moodle's "Exit activity" returns to the course page, which learners must
     not land on. Replaced by the portal link injected at the top of the body. */
  #page .d-flex.flex-row-reverse > a.btn-secondary { display: none !important; }
  /* :has keeps the empty flex row from leaving a gap where the button was. */
  #page div.d-flex.flex-row-reverse:has(> a.btn-secondary) { margin: 0 !important; }
  .pv-back-bar { display: flex; justify-content: flex-end; padding: 8px 12px 0; }
  .pv-back {
    display: inline-block; padding: 7px 14px; border: 1px solid #d4d4d8;
    border-radius: 9px; background: #fff; color: #3f3f46; font-size: .85rem;
    font-weight: 500; text-decoration: none;
    font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
  }
  .pv-back:hover { background: #f4f4f5; color: #18181b; text-decoration: none; }
  /* Nothing else is hidden here. The table of contents is removed properly by
     the activity's own `hidetoc` setting, and #tocbox — which sounds like the
     TOC — actually carries the SCORM API bridge script the package talks to.
     Hiding it blanked the player entirely. */
</style>
CSS;
    }

    /**
     * Make a generated questionnaire look like the portal it was launched from.
     *
     * mod_quiz does the work — attempts, marking, resuming a dropped
     * connection, completion — and none of that is worth rewriting. What is
     * worth changing is the surface: a learner who has never seen Moodle should
     * not meet it here, halfway through their training, in a different
     * typeface.
     *
     * So this restyles rather than replaces: the same palette, spacing and
     * button shapes as the portal, applied to Moodle's own markup. Anything
     * that navigates deeper into Moodle — the course link, the user menu, the
     * breadcrumb — is removed, because those lead somewhere learners have no
     * account for.
     */
    private static function quiz_chrome_css(): string {
        return <<<'CSS'
<style>
  :root {
    --pv-ink: #18181b; --pv-muted: #71717a; --pv-line: #e4e4e7;
    --pv-bg: #fafafa; --pv-brand: #0f766e; --pv-brand-dark: #115e59;
  }
  body.path-mod-quiz {
    background: var(--pv-bg) !important;
    font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
    color: var(--pv-ink);
  }
  /* Anything that leads back into Moodle. A learner has no course page, no
     profile and no site home — offering them is offering a dead end. */
  body.path-mod-quiz .navbar,
  body.path-mod-quiz #page-header,
  body.path-mod-quiz .secondary-navigation,
  body.path-mod-quiz nav.navbar,
  body.path-mod-quiz #page-footer,
  body.path-mod-quiz footer,
  body.path-mod-quiz .breadcrumb,
  body.path-mod-quiz [data-region="blocks-column"],
  body.path-mod-quiz #block-region-side-pre,
  /* Moodle's quiz navigation panel. It offers jumping between questions and a
     "Finish attempt" link — both already reachable from the main flow, which
     runs one question to a page and ends on a finish button. Keeping it would
     mean a second, differently-styled column of Moodle furniture beside the
     question. */
  body.path-mod-quiz [data-blockregion="side-pre"],
  body.path-mod-quiz #mod_quiz_navblock,
  body.path-mod-quiz a[href="#sb-1"],
  /* The tertiary "Back" button, which returns to mod_quiz's own view page —
     the Moodle surface our landing page exists to replace. */
  body.path-mod-quiz .tertiary-navigation,
  body.path-mod-quiz .activity-header,
  body.path-mod-quiz .drawer-toggles,
  body.path-mod-quiz .usermenu { display: none !important; }

  body.path-mod-quiz #page,
  body.path-mod-quiz #page-content,
  body.path-mod-quiz #region-main-box { padding: 0 !important; margin: 0 !important; }
  body.path-mod-quiz #region-main,
  body.path-mod-quiz .region_main_settings_menu_proxy ~ #region-main {
    max-width: 820px; margin: 0 auto !important; padding: 8px 16px 48px !important;
    border: 0 !important; background: transparent !important; box-shadow: none !important;
    width: 100% !important; flex: 1 1 auto !important;
  }
  /* The grid that held the vanished side column would otherwise keep its gap. */
  body.path-mod-quiz .columnsleft,
  body.path-mod-quiz #page-content > .row { display: block !important; }

  /* Moodle's own autosave warning is styled as a Bootstrap alert; keep it
     legible but quiet, since it appears mid-question when a network blips. */
  body.path-mod-quiz #connection-error,
  body.path-mod-quiz #connection-ok {
    border-radius: 10px; font-size: .85rem;
  }

  body.path-mod-quiz h1, body.path-mod-quiz h2 {
    font-size: 1.4rem; font-weight: 600; letter-spacing: -.01em; margin: 0 0 12px;
  }

  /* Each question as a card, the way every list in the portal is drawn. */
  body.path-mod-quiz .que {
    background: #fff; border: 1px solid var(--pv-line); border-radius: 12px;
    padding: 20px 22px; margin: 0 0 16px;
    box-shadow: 0 1px 2px rgba(0,0,0,.04);
  }
  body.path-mod-quiz .que .info {
    background: transparent; border: 0; padding: 0 0 8px; min-width: 0; float: none;
  }
  body.path-mod-quiz .que .no {
    font-size: .7rem; font-weight: 600; text-transform: uppercase;
    letter-spacing: .05em; color: var(--pv-muted); background: transparent; padding: 0;
  }
  body.path-mod-quiz .que .qno { color: var(--pv-ink); }
  /* Marks and the flag are Moodle's furniture, not the learner's business. */
  body.path-mod-quiz .que .grade,
  body.path-mod-quiz .que .questionflag,
  body.path-mod-quiz .que .state { display: none !important; }
  body.path-mod-quiz .que .content { margin: 0 !important; }
  /* Moodle tints the question body with a state colour, which inside a card
     reads as a warning rather than as the question itself. */
  body.path-mod-quiz .que .formulation,
  body.path-mod-quiz .que .ablock {
    background: transparent !important; border: 0 !important;
    box-shadow: none !important; padding: 0 !important; margin: 0 !important;
    color: inherit !important;
  }
  body.path-mod-quiz .que .qtext {
    font-size: 1.12rem; font-weight: 600; line-height: 1.45;
    letter-spacing: -.005em; margin: 0 0 18px; color: var(--pv-ink);
  }

  /* Options as tappable rows rather than bare checkboxes with loose text.
     Moodle labels its choices with aria-labelledby and no <label>, so the
     words beside a box are not clickable on their own; pv-quiz.js makes the
     whole row the hit area and marks the chosen one. */
  body.path-mod-quiz .que .answer { display: grid; gap: 10px; }
  body.path-mod-quiz .que .answer > div {
    display: flex; align-items: center; gap: 12px; cursor: pointer;
    border: 1px solid var(--pv-line); border-radius: 12px;
    padding: 14px 16px; margin: 0; background: #fff;
    transition: border-color .12s, background .12s, box-shadow .12s;
  }
  body.path-mod-quiz .que .answer > div:hover {
    background: #fafafa; border-color: #a1a1aa;
  }
  body.path-mod-quiz .que .answer > div.checked,
  body.path-mod-quiz .que .answer > div.pv-checked {
    border-color: var(--pv-brand); background: #f0fdfa;
    box-shadow: 0 0 0 1px var(--pv-brand) inset;
  }
  body.path-mod-quiz .que .answer > div > .d-flex { align-items: center; margin: 0; }
  body.path-mod-quiz .que .answer .answernumber {
    color: var(--pv-muted); font-weight: 600; font-size: .9rem;
  }
  body.path-mod-quiz .que .answer > div.pv-checked .answernumber { color: var(--pv-brand); }
  body.path-mod-quiz .que .answer .flex-fill { font-size: .98rem; line-height: 1.45; }
  body.path-mod-quiz .que .answer label { cursor: pointer; margin: 0; font-weight: 400; }
  body.path-mod-quiz .que .answer input[type="radio"],
  body.path-mod-quiz .que .answer input[type="checkbox"] {
    margin: 0; flex: 0 0 auto; width: 18px; height: 18px;
    accent-color: var(--pv-brand); cursor: pointer;
  }

  /* Buttons: the portal's shapes and colours. */
  body.path-mod-quiz .btn,
  body.path-mod-quiz input[type="submit"] {
    border-radius: 9px !important; font-weight: 600 !important;
    font-size: .9rem !important; padding: 9px 18px !important; box-shadow: none !important;
  }
  body.path-mod-quiz .btn-primary,
  body.path-mod-quiz input[type="submit"].btn-primary {
    background: var(--pv-brand) !important; border-color: var(--pv-brand) !important; color: #fff !important;
  }
  body.path-mod-quiz .btn-primary:hover { background: var(--pv-brand-dark) !important; border-color: var(--pv-brand-dark) !important; }
  body.path-mod-quiz .btn-secondary {
    background: #fff !important; border-color: var(--pv-line) !important; color: #3f3f46 !important;
  }
  /* The forward button carries the whole flow, so it sits alone on its own
     line at the end of the card rather than tucked under the last option. */
  body.path-mod-quiz .submitbtns {
    display: flex; justify-content: flex-end; margin-top: 20px;
  }
  body.path-mod-quiz .mod_quiz-next-nav { padding: 11px 26px !important; }

  /* The start page's summary table, and the review's, as a plain card. */
  body.path-mod-quiz .generaltable, body.path-mod-quiz .quizattemptsummary {
    background: #fff; border: 1px solid var(--pv-line); border-radius: 12px;
    overflow: hidden; border-collapse: separate; border-spacing: 0;
  }
  body.path-mod-quiz .generaltable th, body.path-mod-quiz .generaltable td {
    border: 0; border-bottom: 1px solid #f4f4f5; padding: 10px 14px; font-size: .9rem;
  }
  body.path-mod-quiz .generaltable th { background: #fafafa; font-weight: 500; color: var(--pv-muted); }

  body.path-mod-quiz .quizstartbuttondiv { margin: 16px 0; }
  body.path-mod-quiz #quiz-timer-wrapper, body.path-mod-quiz .quizattemptcounts {
    color: var(--pv-muted); font-size: .85rem;
  }
  /* Feedback after an attempt. */
  body.path-mod-quiz .quizreviewsummary td { font-size: .9rem; }
  body.path-mod-quiz .que.correct { border-color: #a7f3d0; }
  body.path-mod-quiz .que.incorrect { border-color: #fecaca; }
  body.path-mod-quiz .feedback, body.path-mod-quiz .outcome {
    border-radius: 10px; border: 1px solid var(--pv-line); background: #fafafa;
    padding: 12px 14px; font-size: .9rem;
  }

  .pv-back-bar { display: flex; justify-content: flex-end; padding: 10px 16px 0;
    max-width: 820px; margin: 0 auto; }
  .pv-back {
    display: inline-block; padding: 7px 14px; border: 1px solid var(--pv-line);
    border-radius: 9px; background: #fff; color: #3f3f46; font-size: .85rem;
    font-weight: 500; text-decoration: none; white-space: nowrap;
  }
  .pv-back:hover { background: #f4f4f5; color: var(--pv-ink); text-decoration: none; }

  /* The header for a paper in progress: what it is, how far in, a way out. */
  .pv-quiz-head { max-width: 820px; margin: 0 auto; padding: 18px 16px 4px; }
  .pv-quiz-head-row {
    display: flex; align-items: center; justify-content: space-between; gap: 16px;
  }
  .pv-quiz-title {
    display: block; font-size: 1rem; font-weight: 600; color: var(--pv-ink);
  }
  .pv-quiz-step {
    display: block; font-size: .8rem; color: var(--pv-muted); margin-top: 3px;
  }
  .pv-quiz-track {
    height: 6px; border-radius: 999px; background: #e4e4e7;
    margin-top: 14px; overflow: hidden;
  }
  .pv-quiz-track > span {
    display: block; height: 100%; border-radius: 999px;
    background: var(--pv-brand); transition: width .25s ease;
  }
  @media (max-width: 560px) {
    .pv-quiz-head-row { align-items: flex-start; }
    body.path-mod-quiz .que { padding: 16px 14px; }
  }
</style>
<script>
/* Moodle marks a choice's text with aria-labelledby rather than wrapping it in
   a <label>, so only the box itself is clickable — a 18px target beside a full
   line of text. This makes the row the target and marks the chosen one, which
   is also the only way the selected state becomes visible at all. */
(function () {
  function sync(root) {
    root.querySelectorAll('.que .answer > div').forEach(function (row) {
      var input = row.querySelector('input[type="radio"],input[type="checkbox"]');
      row.classList.toggle('pv-checked', !!(input && input.checked));
    });
  }
  document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('responseform');
    if (!form) { return; }
    form.addEventListener('click', function (e) {
      var row = e.target.closest ? e.target.closest('.que .answer > div') : null;
      if (!row) { return; }
      var input = row.querySelector('input[type="radio"],input[type="checkbox"]');
      if (!input || e.target === input) { sync(form); return; }
      if (input.type === 'checkbox') {
        input.checked = !input.checked;
      } else {
        input.checked = true;
      }
      // Dispatched so mod_quiz's autosave sees the change as it would a click.
      input.dispatchEvent(new Event('change', { bubbles: true }));
      sync(form);
    });
    form.addEventListener('change', function () { sync(form); });
    sync(form);
  });
})();
</script>
CSS;
    }

    /**
     * Work out whose IdP a returning SAML message belongs to.
     *
     * `auth_iomadsaml2` scopes identity providers per company and finds the
     * company through `iomad::get_my_companyid()`, which reads
     * `$SESSION->currenteditingcompany`. That works on the way out — the
     * learner's browser is on our own site when the sign-in starts. It cannot
     * work on the way back: the identity provider returns the assertion as a
     * cross-site form POST, and Moodle sets its session cookie `SameSite=Lax`,
     * which browsers withhold from exactly that kind of request. So the session
     * is empty at the assertion consumer, `get_my_companyid()` answers -1, no
     * IdP rows match, and SimpleSAMLphp fails with
     * `METADATANOTFOUND('%ENTITYID%' => 'https://sts.windows.net/…')`.
     *
     * The message itself carries the answer. Its `Issuer` is the IdP's entity
     * id, and that maps to exactly one row in `auth_iomadsaml2_idps` — so the
     * company is read from the request rather than from a cookie that is not
     * there.
     *
     * This selects *which IdP's metadata to load*, nothing more. The assertion
     * is still signature-checked against that IdP's own certificate by
     * SimpleSAMLphp, so naming someone else's issuer buys an attacker only a
     * signature check they cannot pass.
     */
    public static function after_config(\core\hook\after_config $hook): void {
        global $CFG, $DB, $SESSION;

        if (CLI_SCRIPT || during_initial_install()) {
            return;
        }
        // Already known — either from the session, or because a real person is
        // signed in. Nothing to infer.
        if (!empty($SESSION->currenteditingcompany) || !empty($CFG->foundcompanyid)) {
            return;
        }
        // Only the SP's own endpoints, so no ordinary page pays for this.
        $path = parse_url((string) me(), PHP_URL_PATH) ?: '';
        if (strpos($path, '/auth/iomadsaml2/sp/') === false) {
            return;
        }

        $message = $_POST['SAMLResponse'] ?? $_POST['SAMLRequest'] ?? '';
        if (!is_string($message) || $message === '') {
            return;
        }
        // The HTTP-POST binding is plain base64 — no deflate, unlike redirect.
        $xml = base64_decode($message, true);
        if ($xml === false || $xml === '') {
            return;
        }

        $entityid = self::saml_issuer($xml);
        if ($entityid === null) {
            return;
        }

        $idp = $DB->get_record('auth_iomadsaml2_idps', ['entityid' => $entityid, 'activeidp' => 1]);
        if ($idp && $idp->companyid > 0) {
            // The name IOMAD looks for; it copies this into the session and
            // unsets it itself on first read.
            $CFG->foundcompanyid = (int) $idp->companyid;
        }
    }

    /**
     * The entity id of whoever sent a SAML message.
     *
     * Parsed with entity loading off and errors captured: this is attacker-
     * reachable XML arriving before anything has authenticated it, so it is
     * read for one string and nothing else.
     */
    private static function saml_issuer(string $xml): ?string {
        // A SAML response can be several kilobytes; nothing legitimate is
        // megabytes. Refusing early bounds the work an unauthenticated caller
        // can make this do.
        if (strlen($xml) > 512 * 1024) {
            return null;
        }

        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument();
        // NOT LIBXML_NOENT. Despite the name, that flag *substitutes* entities
        // rather than rejecting them — which is precisely what turns this
        // parser into an XXE and a billion-laughs primitive, on a code path
        // reachable before anything has authenticated. Without it libxml leaves
        // entity references untouched, and the Issuer this reads is plain text.
        //
        // LIBXML_NONET additionally refuses network fetches, and DTD loading is
        // off by default in libxml 2.9+.
        $ok = $document->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$ok) {
            return null;
        }

        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('saml', 'urn:oasis:names:tc:SAML:2.0:assertion');
        // The response's own Issuer first; some providers put one only on the
        // assertion inside it.
        foreach (['/*/saml:Issuer', '//saml:Issuer'] as $query) {
            $nodes = $xpath->query($query);
            if ($nodes && $nodes->length > 0) {
                $value = trim((string) $nodes->item(0)->textContent);
                if ($value !== '') {
                    return $value;
                }
            }
        }
        return null;
    }

    public static function before_http_headers(\core\hook\output\before_http_headers $hook): void {
        global $CFG, $USER, $PAGE;

        $portal = trim((string) get_config('local_privacient', 'portalurl'));
        if ($portal === '') {
            return; // Nothing configured to send them to.
        }

        // Staff use Moodle deliberately; only learners are redirected.
        if (!self::is_learner()) {
            return;
        }

        // The *requested* URL, not qualified_me(). qualified_me() answers with
        // $PAGE->url, which a script that dies before setting one — an error
        // page, most of all — leaves as the site root. Read as "/index.php",
        // that matched the browsing list below and turned every learner-facing
        // Moodle error into a silent bounce to the portal, hiding the fault.
        $path = parse_url((string) me(), PHP_URL_PATH) ?: '';
        // Strip the Moodle subdirectory, if any, so the comparison is stable.
        $root = parse_url($CFG->wwwroot, PHP_URL_PATH) ?: '';
        if ($root !== '' && $root !== '/' && strpos($path, $root) === 0) {
            $path = substr($path, strlen($root));
        }
        if ($path === '' ) {
            $path = '/';
        }

        // A bare site root is the same "where am I?" page as /my/.
        if ($path === '/') {
            $path = '/index.php';
        }

        // SCORM is played by Moodle's own mod_scorm, which we do not control and
        // should not reimplement — it handles attempts, resume and the SCORM
        // runtime. What we can do is take Moodle's chrome off it, so a learner
        // moving from the portal into a package does not suddenly find
        // themselves in a differently-branded system with navigation they
        // cannot use.
        // mod_quiz's own landing page is Moodle's: a left-aligned heading, a
        // grades table and an "Attempt quiz" button. Learners get ours instead
        // — and get it wherever they arrive from, because Moodle links back to
        // view.php from the attempt page, the summary and the review, and any
        // one of those would otherwise hand them the page we replaced.
        if ($path === '/mod/quiz/view.php') {
            $cmid = optional_param('id', 0, PARAM_INT);
            if (!$cmid) {
                // Some links carry the quiz instance rather than the module.
                $quizid = optional_param('q', 0, PARAM_INT);
                $cm = $quizid
                    ? get_coursemodule_from_instance('quiz', $quizid, 0, false, IGNORE_MISSING)
                    : false;
                $cmid = $cm ? (int) $cm->id : 0;
            }
            if ($cmid) {
                redirect(new \moodle_url('/local/privacient/quiz.php', ['cmid' => $cmid]));
            }
        }

        if (strpos($path, '/mod/scorm/') === 0 || strpos($path, '/mod/quiz/') === 0) {
            $PAGE->set_pagelayout('embedded');
            if (isset($PAGE->activityheader)) {
                $PAGE->activityheader->disable();
            }
            // Match the portal's tab titles. Moodle's default appends the course
            // shortname and the site name — "… | privacient-79 | IntelliPhish" —
            // which names two systems the learner never signed in to.
            $name = !empty($PAGE->cm->name)
                ? format_string($PAGE->cm->name)
                : $PAGE->title;
            $PAGE->set_title(
                $name . ' - ' . get_string('portaltitle', 'local_privacient'),
                false
            );
            return;
        }

        if (!in_array($path, self::BROWSING_PAGES, true)) {
            return;
        }

        redirect(new \moodle_url($portal));
    }
}
