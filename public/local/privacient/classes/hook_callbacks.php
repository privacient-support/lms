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

    public static function before_http_headers(\core\hook\output\before_http_headers $hook): void {
        global $CFG, $USER, $PAGE;

        $portal = trim((string) get_config('local_privacient', 'portalurl'));
        if ($portal === '') {
            return; // Nothing configured to send them to.
        }

        // Never interfere with anything that is not a normal page render.
        if (CLI_SCRIPT || AJAX_SCRIPT || WS_SERVER || during_initial_install()) {
            return;
        }
        if (empty($USER->id) || isguestuser() || !isloggedin()) {
            return;
        }

        // Staff use Moodle deliberately; only learners are redirected.
        $syscontext = \context_system::instance();
        if (is_siteadmin()
            || has_capability('moodle/site:config', $syscontext)
            || has_capability('moodle/course:manageactivities', $syscontext)
            || has_capability('block/iomad_company_admin:company_add', $syscontext)) {
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

        if (!in_array($path, self::BROWSING_PAGES, true)) {
            return;
        }

        redirect(new \moodle_url($portal));
    }
}
