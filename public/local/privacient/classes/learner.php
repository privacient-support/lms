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

        // Never adopt or mutate a pre-existing account that reaches beyond this
        // company's learners: a site admin or guest, an account linked to
        // another company or holding a managing role, a web-service token
        // holder, or anyone with a system/category-level role. Such an account
        // is not this company's to claim, and flipping its auth to SAML would
        // hand that company's IdP a login it must never have. Only a genuinely
        // new account, or one already a plain learner of THIS company, is
        // linked. The callers surface the thrown failure rather than silently
        // adopting.
        if ($user && self::is_privileged_or_shared($user, $companyid)) {
            throw new \moodle_exception(
                'nopermissions', 'error', '', null,
                'That email belongs to an account this company may not adopt'
            );
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
     * Nothing happens unless the account is linked to $companyid as a learner:
     * the caller names a company, and an account that is not that company's
     * learner is not that company's to remove. (An earlier version deleted
     * any account with no company link at all, which reached platform staff
     * and the console's own web-service account.)
     *
     * @return string 'deleted', 'detached' or 'notfound'
     */
    public static function remove(string $email, int $companyid, array $courseids): string {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/user/lib.php');
        require_once($CFG->libdir . '/enrollib.php');

        if ($companyid <= 0) {
            return 'notfound';
        }
        $email = \core_text::strtolower(trim($email));
        $user = $DB->get_record('user', [
            'email' => $email, 'mnethostid' => $CFG->mnet_localhost_id, 'deleted' => 0,
        ]) ?: $DB->get_record('user', [
            'username' => $email, 'mnethostid' => $CFG->mnet_localhost_id, 'deleted' => 0,
        ]);
        if (!$user || !$DB->record_exists('local_iomad_company_users', [
            'companyid' => $companyid, 'userid' => $user->id, 'managertype' => 0,
        ])) {
            return 'notfound';
        }

        if (!self::is_privileged_or_shared($user, $companyid)) {
            delete_user($user);
            return 'deleted';
        }

        // Only courses this company owns: the caller's list is a hint, not an
        // authority, so another customer's enrolments stay untouched.
        $companycourses = $DB->get_fieldset_select(
            'local_iomad_company_courses', 'courseid', 'companyid = ?', [$companyid]
        );
        $courseids = array_intersect(array_map('intval', $courseids), array_map('intval', $companycourses));

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
     * True when deleting this account could reach beyond one company's
     * learner: a site admin or guest, anyone with a system- or category-level
     * role, a web-service token holder, or an account also linked to another
     * company or holding a managing role in one.
     */
    private static function is_privileged_or_shared(\stdClass $user, int $companyid): bool {
        global $DB;

        if (is_siteadmin($user) || isguestuser($user)) {
            return true;
        }
        if ($DB->record_exists_select(
            'local_iomad_company_users',
            'userid = ? AND (companyid <> ? OR managertype <> 0)',
            [$user->id, $companyid]
        )) {
            return true;
        }
        if ($DB->record_exists('external_tokens', ['userid' => $user->id])) {
            return true;
        }
        return $DB->record_exists_sql(
            'SELECT 1
               FROM {role_assignments} ra
               JOIN {context} ctx ON ctx.id = ra.contextid
              WHERE ra.userid = ? AND ctx.contextlevel IN (?, ?)',
            [$user->id, CONTEXT_SYSTEM, CONTEXT_COURSECAT]
        );
    }

    /**
     * True only when this account may sign in as a learner of $companyid.
     *
     * The gate for company-scoped SSO: a non-suspended plain learner
     * (managertype = 0) of exactly this company, never a site admin or guest,
     * and never anyone holding a system- or category-level role. This is what
     * stops one company's IdP vouching for a site admin, a manager, or another
     * tenant's user once the assertion has been verified.
     */
    public static function is_company_learner(\stdClass $user, int $companyid): bool {
        global $DB;

        if ($companyid <= 0 || empty($user->id)) {
            return false;
        }
        if (is_siteadmin($user) || isguestuser($user)) {
            return false;
        }
        if (!$DB->record_exists('local_iomad_company_users', [
            'companyid' => $companyid,
            'userid' => $user->id,
            'managertype' => 0,
            'suspended' => 0,
        ])) {
            return false;
        }
        if ($DB->record_exists_sql(
            'SELECT 1
               FROM {role_assignments} ra
               JOIN {context} ctx ON ctx.id = ra.contextid
              WHERE ra.userid = ? AND ctx.contextlevel IN (?, ?)',
            [$user->id, CONTEXT_SYSTEM, CONTEXT_COURSECAT]
        )) {
            return false;
        }
        return true;
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
