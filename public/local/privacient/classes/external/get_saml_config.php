<?php
namespace local_privacient\external;

defined('MOODLE_INTERNAL') || die();

use core_external\{external_api, external_function_parameters, external_single_structure,
    external_multiple_structure, external_value};
use context_system;

/**
 * Read a company's learner SSO configuration.
 *
 * IOMAD's `auth_iomadsaml2` already stores SAML per company: settings carry a
 * `_<companyid>` postfix and IdP entities carry a `companyid` column. This
 * reads that, so the console can show a tenant their own configuration without
 * giving anyone a Moodle admin login.
 */
class get_saml_config extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'companyid' => new external_value(PARAM_INT, 'IOMAD company id'),
        ]);
    }

    public static function execute($companyid): array {
        global $DB;

        ['companyid' => $companyid] = self::validate_parameters(
            self::execute_parameters(), ['companyid' => $companyid]
        );

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/privacient:managesaml', $context);

        if (!$DB->record_exists('local_iomad_companies', ['id' => $companyid])) {
            throw new \moodle_exception('invalidarguments', 'error', '', null, 'Unknown company');
        }

        // The service-provider endpoints the customer's IdP must be told about.
        // Built here rather than in the console because IOMAD is the authority
        // on its own wwwroot, and auth_iomadsaml2 derives the SP name from that
        // host — a console guessing at it would hand out URLs that 404.
        global $CFG;
        $spname = parse_url($CFG->wwwroot, PHP_URL_HOST);
        $sp = [
            'entityid' => "{$CFG->wwwroot}/auth/iomadsaml2/sp/metadata.php",
            'metadataurl' => "{$CFG->wwwroot}/auth/iomadsaml2/sp/metadata.php",
            'acsurl' => "{$CFG->wwwroot}/auth/iomadsaml2/sp/saml2-acs.php/{$spname}",
            'slsurl' => "{$CFG->wwwroot}/auth/iomadsaml2/sp/saml2-logout.php/{$spname}",
        ];

        $metadata = (string) get_config('auth_iomadsaml2', "idpmetadata_{$companyid}");
        $enabledauths = explode(',', (string) get_config('core', 'auth'));

        $idps = $DB->get_records('auth_iomadsaml2_idps', ['companyid' => $companyid]);
        $entities = [];
        foreach ($idps as $idp) {
            $entities[] = [
                'entityid' => (string) $idp->entityid,
                'displayname' => (string) ($idp->displayname ?: $idp->defaultname),
                'metadataurl' => (string) $idp->metadataurl,
                'active' => (bool) $idp->activeidp,
                'isdefault' => (bool) $idp->defaultidp,
            ];
        }

        return [
            'companyid' => $companyid,
            'sp' => $sp,
            'metadata' => $metadata,
            'configured' => $metadata !== '',
            // Site-wide: SAML cannot work for any company until the plugin is on.
            'pluginenabled' => in_array('iomadsaml2', $enabledauths, true),
            'entities' => $entities,
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'companyid' => new external_value(PARAM_INT, 'Company id'),
            'sp' => new external_single_structure([
                'entityid' => new external_value(PARAM_RAW, 'Our SAML entity ID / issuer'),
                'metadataurl' => new external_value(PARAM_RAW, 'Where our SP metadata is published'),
                'acsurl' => new external_value(PARAM_RAW, 'Assertion Consumer Service (reply) URL'),
                'slsurl' => new external_value(PARAM_RAW, 'Single Logout Service URL'),
            ]),
            'metadata' => new external_value(PARAM_RAW, 'Configured IdP metadata URL or XML'),
            'configured' => new external_value(PARAM_BOOL, 'Whether metadata has been set'),
            'pluginenabled' => new external_value(PARAM_BOOL, 'Whether the SAML auth plugin is enabled site-wide'),
            'entities' => new external_multiple_structure(new external_single_structure([
                'entityid' => new external_value(PARAM_RAW, 'IdP entity id'),
                'displayname' => new external_value(PARAM_RAW, 'Name shown on the login button'),
                'metadataurl' => new external_value(PARAM_RAW, 'Source metadata URL'),
                'active' => new external_value(PARAM_BOOL, 'Accepting logins'),
                'isdefault' => new external_value(PARAM_BOOL, 'Used when no IdP is chosen'),
            ])),
        ]);
    }
}
