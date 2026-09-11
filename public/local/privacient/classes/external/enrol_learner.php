<?php
namespace local_privacient\external;

defined('MOODLE_INTERNAL') || die();

use core_external\{external_api, external_function_parameters, external_single_structure, external_value};
use context_system;

/**
 * Ensure a learner exists in Moodle and is enrolled on a course, then mint a
 * single-use key that logs them straight into it.
 *
 * Learners never set a Moodle password: they arrive from a campaign email, so
 * the account is created with an unusable password and reached only through the
 * launch key. That keeps one identity — the console's — rather than asking
 * people to remember a second login.
 */
class enrol_learner extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course to enrol on'),
            'email' => new external_value(PARAM_EMAIL, 'Learner email'),
            'firstname' => new external_value(PARAM_TEXT, 'First name', VALUE_DEFAULT, ''),
            'lastname' => new external_value(PARAM_TEXT, 'Last name', VALUE_DEFAULT, ''),
            'companyid' => new external_value(PARAM_INT, 'IOMAD company to attach the user to', VALUE_DEFAULT, 0),
            'makekey' => new external_value(PARAM_BOOL, 'Also return a launch key', VALUE_DEFAULT, 1),
        ]);
    }

    public static function execute($courseid, $email, $firstname = '', $lastname = '',
                                   $companyid = 0, $makekey = true): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/user/lib.php');
        require_once($CFG->libdir . '/enrollib.php');
        require_once($CFG->libdir . '/moodlelib.php');

        [
            'courseid' => $courseid, 'email' => $email, 'firstname' => $firstname,
            'lastname' => $lastname, 'companyid' => $companyid, 'makekey' => $makekey,
        ] = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid, 'email' => $email, 'firstname' => $firstname,
            'lastname' => $lastname, 'companyid' => $companyid, 'makekey' => $makekey,
        ]);

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/privacient:managecontent', $context);

        $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
        $email = \core_text::strtolower(trim($email));

        $user = $DB->get_record('user', [
            'email' => $email,
            'mnethostid' => $CFG->mnet_localhost_id,
            'deleted' => 0,
        ]);

        if (!$user) {
            $new = new \stdClass();
            $new->username = $email;
            $new->email = $email;
            $new->firstname = $firstname !== '' ? $firstname : explode('@', $email)[0];
            $new->lastname = $lastname !== '' ? $lastname : ' ';
            $new->auth = 'manual';
            $new->confirmed = 1;
            $new->mnethostid = $CFG->mnet_localhost_id;
            // Never used: sign-in happens through the launch key.
            $new->password = hash_internal_user_password(random_string(40));
            $userid = user_create_user($new, false, false);
            $user = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);
        }

        // Attach to the IOMAD company so its reporting sees this learner.
        if ($companyid && $DB->record_exists('local_iomad_companies', ['id' => $companyid])
            && !$DB->record_exists('local_iomad_company_users', [
                'companyid' => $companyid, 'userid' => $user->id,
            ])) {
            $DB->insert_record('local_iomad_company_users', (object) [
                'companyid' => $companyid,
                'userid' => $user->id,
                'managertype' => 0,
                'departmentid' => 0,
                'suspended' => 0,
            ]);
        }

        $studentrole = $DB->get_record('role', ['shortname' => 'student'], '*', MUST_EXIST);
        enrol_try_internal_enrol($course->id, $user->id, $studentrole->id);

        $key = '';
        if ($makekey) {
            $key = self::mint_key($user->id, $course->id);
        }

        return [
            'userid' => (int) $user->id,
            'launchurl' => $key === '' ? '' : "{$CFG->wwwroot}/local/privacient/launch.php?key={$key}",
        ];
    }

    /**
     * One-time login key scoped to a course.
     *
     * Uses Moodle's own `user_key` machinery, the same mechanism `auth_userkey`
     * and the calendar export rely on. Keys expire and are deleted on use.
     */
    public static function mint_key(int $userid, int $courseid): string {
        global $CFG;
        require_once($CFG->libdir . '/moodlelib.php');

        $ttl = (int) (get_config('local_privacient', 'launchttl') ?: 604800); // 7 days
        return create_user_key('local_privacient', $userid, $courseid, null, time() + $ttl);
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'userid' => new external_value(PARAM_INT, 'Moodle user id'),
            'launchurl' => new external_value(PARAM_RAW, 'Single-use launch URL, empty when not requested'),
        ]);
    }
}
