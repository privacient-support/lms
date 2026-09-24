<?php
/**
 * Log a learner in from a campaign link and drop them on their course.
 *
 * The key is created by `local_privacient_enrol_learner` and carries the target
 * course as its instance. It is consumed on use, so a forwarded link cannot be
 * replayed — which matters because these arrive by email.
 */
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/moodlelib.php');

$keyvalue = required_param('key', PARAM_ALPHANUM);

// validate_user_key() matches on the instance as well as the value, and the
// instance here is the target course — which the caller does not know. So the
// record is located first, then validated properly for expiry and IP.
$stored = $DB->get_record('user_private_key', [
    'script' => 'local_privacient',
    'value' => $keyvalue,
]);
if (!$stored) {
    throw new moodle_exception('invalidkey');
}
$key = validate_user_key($keyvalue, 'local_privacient', $stored->instance);

$user = $DB->get_record('user', ['id' => $key->userid, 'deleted' => 0], '*', MUST_EXIST);
$courseid = (int) $key->instance;

// A launch key is a passwordless login, so honour it only for an account that
// is still a genuine, active learner of the company that owns this course. A
// key minted while the account was a plain learner must not still let it in
// after the account has been suspended, promoted to a manager or site admin,
// or moved to another company — nor may a site admin ever be logged in this
// way. Fail closed: deny unless a company that owns the course counts this
// user among its non-suspended plain learners.
if (is_siteadmin($user) || !empty($user->suspended) || $user->auth === 'nologin') {
    throw new moodle_exception('nopermissions', 'error', '', null,
        'This launch link is no longer valid for that account');
}
$owners = $DB->get_fieldset_select(
    'local_iomad_company_courses', 'companyid', 'courseid = ?', [$courseid]
);
$islearner = false;
foreach ($owners as $ownercompanyid) {
    if (\local_privacient\learner::is_company_learner($user, (int) $ownercompanyid)) {
        $islearner = true;
        break;
    }
}
if (!$islearner) {
    throw new moodle_exception('nopermissions', 'error', '', null,
        'This launch link is no longer valid for that account');
}

// Single use: consume before establishing the session.
$DB->delete_records('user_private_key', ['id' => $key->id]);

complete_user_login($user);

// Straight to the activity, never through /course/view.php.
//
// That page is gated by IOMAD on company membership, so a learner whose tenant
// is not linked to an IOMAD company is bounced off it to the site home even
// with a valid session and an active enrolment — which looks exactly like "the
// training did not launch". Going directly to the activity sidesteps the gate,
// and also keeps learners off the one Moodle page this flow exists to avoid.
$target = new moodle_url('/my/');
if ($courseid) {
    // Read the module from the course itself rather than from the content row.
    // One content item now has a course per campaign, so `content.courseid`
    // names only the most recent — every earlier campaign would fail to route.
    $item = $DB->get_record_sql(
        "SELECT cm.id AS cmid, m.name AS modname
           FROM {course_modules} cm
           JOIN {modules} m ON m.id = cm.module
          WHERE cm.course = :courseid AND cm.deletioninprogress = 0
       ORDER BY cm.id ASC",
        ['courseid' => $courseid],
        IGNORE_MULTIPLE
    );

    $cmexists = !empty($item->cmid);

    if ($cmexists && $item->modname === 'resource') {
        // Our own player: it is what measures the watch and reports it to
        // progress.php.
        $target = new moodle_url('/local/privacient/play.php', ['cmid' => $item->cmid]);
    } else if ($cmexists && $item->modname === 'quiz') {
        // A generated questionnaire. Our own landing page rather than
        // mod_quiz's view.php, which is a Moodle page down to its layout, and
        // rather than the course page, which is IOMAD's. Sitting the paper is
        // still mod_quiz's job; only the way in belongs to us.
        $target = new moodle_url('/local/privacient/quiz.php', ['cmid' => $item->cmid]);
    } else if ($cmexists && $item->modname === 'scorm') {
        // Our branded intro first — the learner's company logo — which then
        // continues to mod_scorm's view.php; with skipview set that drops the
        // learner straight into player.php, itself an 'embedded' page. With no
        // branding to show, scorm.php redirects straight through.
        $target = new moodle_url('/local/privacient/scorm.php', ['cmid' => $item->cmid]);
    } else {
        $target = new moodle_url('/course/view.php', ['id' => $courseid]);
    }
}
redirect($target);
