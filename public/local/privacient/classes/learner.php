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
