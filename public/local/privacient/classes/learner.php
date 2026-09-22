<?php
namespace local_privacient;

defined('MOODLE_INTERNAL') || die();

/**
 * Create a Moodle user for a console roster entry and attach them to the
 * IOMAD company. Does not enrol on a course — that happens when training
 * is assigned. SAML needs the user and the company link to exist first.
 */
class learner {

    public static function ensure(string $email, string $firstname, string $lastname, int $companyid): \stdClass {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/user/lib.php');
        require_once($CFG->libdir . '/moodlelib.php');

        $email = \core_text::strtolower(trim($email));
        $user = $DB->get_record('user', [
            'email' => $email,
            'mnethostid' => $CFG->mnet_localhost_id,
            'deleted' => 0,
        ]);
        if (!$user) {
            $user = $DB->get_record('user', [
                'username' => $email,
                'mnethostid' => $CFG->mnet_localhost_id,
                'deleted' => 0,
            ]);
        }

        $auth = self::auth_for_company($companyid);

        if (!$user) {
            $new = new \stdClass();
            $new->username = $email;
            $new->email = $email;
            $new->firstname = $firstname !== '' ? $firstname : explode('@', $email)[0];
            $new->lastname = $lastname !== '' ? $lastname : ' ';
            $new->auth = $auth;
            $new->confirmed = 1;
            $new->mnethostid = $CFG->mnet_localhost_id;
            $new->password = hash_internal_user_password(random_string(40));
            $userid = user_create_user($new, false, false);
            $user = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);
        } else if ($auth === 'iomadsaml2' && $user->auth === 'manual') {
            // Imported before SSO was turned on. Leave them as iomadsaml2 so
            // the plugin does not reject a successful Azure assertion.
            $DB->set_field('user', 'auth', 'iomadsaml2', ['id' => $user->id]);
            $user->auth = 'iomadsaml2';
        }

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

        return $user;
    }

    /**
     * Take a console team member off the learning platform.
     *
     * The account is deleted outright (Moodle's delete_user drops enrolments,
     * grades, completions and sessions with it) only when it is plainly this
     * company's learner: attached to no other company and in no managing role
     * anywhere. An email can legitimately be a learner in two customers, or an
     * IOMAD manager too; deleting that account would reach into someone else's
     * data. Such an account is only detached from this company — unenrolled
     * from the courses this company put them on and unlinked from the company.
     *
     * @return string 'deleted', 'detached' or 'notfound'
     */
    public static function remove(string $email, int $companyid, array $courseids): string {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/user/lib.php');
        require_once($CFG->libdir . '/enrollib.php');

        $email = \core_text::strtolower(trim($email));
        $user = $DB->get_record('user', [
            'email' => $email, 'mnethostid' => $CFG->mnet_localhost_id, 'deleted' => 0,
        ]) ?: $DB->get_record('user', [
            'username' => $email, 'mnethostid' => $CFG->mnet_localhost_id, 'deleted' => 0,
        ]);
        if (!$user) {
            return 'notfound';
        }

        $links = $DB->get_records('local_iomad_company_users', ['userid' => $user->id]);
        $elsewhere = false;
        foreach ($links as $link) {
            if ((int) $link->companyid !== $companyid || (int) $link->managertype !== 0) {
                $elsewhere = true;
            }
        }

        if (!$elsewhere && !is_siteadmin($user) && !isguestuser($user)) {
            delete_user($user);
            return 'deleted';
        }

        foreach (array_unique($courseids) as $courseid) {
            foreach (enrol_get_instances($courseid, false) as $instance) {
                $plugin = enrol_get_plugin($instance->enrol);
                if ($plugin && $DB->record_exists('user_enrolments', [
                    'enrolid' => $instance->id, 'userid' => $user->id,
                ])) {
                    $plugin->unenrol_user($instance, $user->id);
                }
            }
        }
        $DB->delete_records('local_iomad_company_users', [
            'companyid' => $companyid, 'userid' => $user->id, 'managertype' => 0,
        ]);
        return 'detached';
    }

    /**
     * SAML companies sign in through iomadsaml2; everyone else is manual.
     * Learners never hold a Moodle password either way.
     */
    private static function auth_for_company(int $companyid): string {
        if ($companyid > 0 && (string) get_config('auth_iomadsaml2', "idpmetadata_{$companyid}") !== '') {
            return 'iomadsaml2';
        }
        return 'manual';
    }
}
