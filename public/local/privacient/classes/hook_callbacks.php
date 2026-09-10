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
        if (!self::is_learner_scorm_page()) {
            return;
        }
        $portal = trim((string) get_config('local_privacient', 'portalurl'));
        if ($portal === '') {
            return;
        }
        $label = get_string('backtotraining', 'local_privacient');
        $hook->add_html(
            '<div class="pv-scorm-bar">'
            . '<a class="pv-scorm-back" href="' . s($portal) . '">' . s($label) . '</a>'
            . '</div>'
        );
    }

    public static function before_standard_head_html_generation(
        \core\hook\output\before_standard_head_html_generation $hook
    ): void {
        global $PAGE, $OUTPUT;

        $onplugin = strpos((string) $PAGE->url, '/local/privacient/') !== false;
        if (!$onplugin && !self::is_learner_scorm_page()) {
            return;
        }
        // SCORM pages get the product icon too, plus the CSS that removes
        // Moodle's own exit bar and lets the package use the full width.
        if (self::is_learner_scorm_page()) {
            $hook->add_html(self::scorm_chrome_css());
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
  .pv-scorm-bar { display: flex; justify-content: flex-end; padding: 8px 12px 0; }
  .pv-scorm-back {
    display: inline-block; padding: 7px 14px; border: 1px solid #d4d4d8;
    border-radius: 9px; background: #fff; color: #3f3f46; font-size: .85rem;
    font-weight: 500; text-decoration: none;
    font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
  }
  .pv-scorm-back:hover { background: #f4f4f5; color: #18181b; text-decoration: none; }
  /* Nothing else is hidden here. The table of contents is removed properly by
     the activity's own `hidetoc` setting, and #tocbox — which sounds like the
     TOC — actually carries the SCORM API bridge script the package talks to.
     Hiding it blanked the player entirely. */
</style>
CSS;
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

        $path = parse_url((string) qualified_me(), PHP_URL_PATH) ?: '';
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
        if (strpos($path, '/mod/scorm/') === 0) {
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
