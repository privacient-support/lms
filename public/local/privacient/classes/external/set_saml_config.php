<?php
namespace local_privacient\external;

defined('MOODLE_INTERNAL') || die();

use core_external\{external_api, external_function_parameters, external_single_structure,
    external_multiple_structure, external_value};
use context_system;

/**
 * Configure a company's learner SSO from the console.
 *
 * The metadata is handed to `auth_iomadsaml2`'s own setting class rather than
 * parsed here. That class fetches the document, validates it, extracts each
 * entity's id, display name and logo, and reconciles the company's rows in
 * `auth_iomadsaml2_idps` — reimplementing any of that would mean writing a
 * second SAML metadata parser and keeping it in step with the first.
 *
 * Nothing in this file verifies a SAML assertion. That stays where it belongs,
 * in SimpleSAMLphp inside the auth plugin: XML signature checking is where SAML
 * implementations get broken into, and it is not a thing to hand-roll.
 */
class set_saml_config extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'companyid' => new external_value(PARAM_INT, 'IOMAD company id'),
            'metadata' => new external_value(PARAM_RAW, 'IdP metadata URL, or the metadata XML itself'),
            'displayname' => new external_value(PARAM_TEXT, 'Label for the sign-in button', VALUE_DEFAULT, ''),
            'active' => new external_value(PARAM_BOOL, 'Accept logins through this IdP', VALUE_DEFAULT, 1),
        ]);
    }

    public static function execute($companyid, $metadata, $displayname = '', $active = true): array {
        global $CFG, $DB;
        require_once($CFG->libdir . '/adminlib.php');

        [
            'companyid' => $companyid, 'metadata' => $metadata,
            'displayname' => $displayname, 'active' => $active,
        ] = self::validate_parameters(self::execute_parameters(), [
            'companyid' => $companyid, 'metadata' => $metadata,
            'displayname' => $displayname, 'active' => $active,
        ]);

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/privacient:managesaml', $context);

        if (!$DB->record_exists('local_iomad_companies', ['id' => $companyid])) {
            throw new \moodle_exception('invalidarguments', 'error', '', null, 'Unknown company');
        }

        \local_privacient\saml_sp::ensure_entity_id((int) $companyid);
        \local_privacient\saml_sp::allow_roster_sso((int) $companyid);

        $metadata = trim($metadata);
        if ($metadata === '') {
            throw new \moodle_exception(
                'invalidarguments', 'error', '', null,
                'Provide the identity provider metadata URL or XML'
            );
        }

        // auth_iomadsaml2 looks up privatekeypass_{companyid} and passes the
        // result straight to SimpleSAMLphp. get_config() returns boolean false
        // when that key is missing, which SSP rejects ("not a valid string
        // value or null") and SSO dies before the IdP is contacted. The SP
        // certificate is site-wide, so copy the site passphrase when the
        // company does not have its own.
        $sitepass = get_config('auth_iomadsaml2', 'privatekeypass');
        if (is_string($sitepass) && get_config('auth_iomadsaml2', "privatekeypass_{$companyid}") === false) {
            set_config("privatekeypass_{$companyid}", $sitepass, 'auth_iomadsaml2');
        }

        // The company postfix is how auth_iomadsaml2 keeps tenants apart.
        $setting = new \auth_iomadsaml2\admin\setting_idpmetadata("_{$companyid}");
        $error = $setting->write_setting($metadata);
        if (!empty($error)) {
            // The plugin returns a human-readable reason: unreachable URL,
            // malformed XML, no IdP entity in the document.
            throw new \moodle_exception('invalidarguments', 'error', '', null, $error);
        }

        // The parser creates rows inactive by default; a company that has just
        // configured an IdP means to use it.
        $idps = $DB->get_records('auth_iomadsaml2_idps', ['companyid' => $companyid]);
        $first = true;
        $entities = [];
        foreach ($idps as $idp) {
            $idp->activeidp = $active ? 1 : 0;
            // Exactly one default, or the login page cannot choose.
            $idp->defaultidp = ($first && $active) ? 1 : 0;
            if ($displayname !== '' && $first) {
                $idp->displayname = $displayname;
            }
            $DB->update_record('auth_iomadsaml2_idps', $idp);
            $entities[] = [
                'entityid' => (string) $idp->entityid,
                'displayname' => (string) ($idp->displayname ?: $idp->defaultname),
            ];
            $first = false;
        }

        if (empty($entities)) {
            throw new \moodle_exception(
                'invalidarguments', 'error', '', null,
                'No identity provider was found in that metadata'
            );
        }

        // SAML is dead site-wide until the plugin is in the enabled auth list.
        // Enabling it does not force it on anyone: users keep their own auth
        // method, and only companies with an active IdP get a SAML button.
        $enabled = array_filter(explode(',', (string) get_config('core', 'auth')));
        if (!in_array('iomadsaml2', $enabled, true)) {
            $enabled[] = 'iomadsaml2';
            set_config('auth', implode(',', $enabled));
            \core\session\manager::gc();
        }
        \core_plugin_manager::reset_caches();

        return ['companyid' => $companyid, 'entities' => $entities];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'companyid' => new external_value(PARAM_INT, 'Company id'),
            'entities' => new external_multiple_structure(new external_single_structure([
                'entityid' => new external_value(PARAM_RAW, 'IdP entity id'),
                'displayname' => new external_value(PARAM_RAW, 'Name shown on the login button'),
            ])),
        ]);
    }
}
