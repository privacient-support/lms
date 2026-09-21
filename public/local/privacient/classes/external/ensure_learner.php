<?php
namespace local_privacient\external;

defined('MOODLE_INTERNAL') || die();

use core_external\{external_api, external_function_parameters, external_single_structure, external_value};
use context_system;

/**
 * Put a console team member on the IOMAD company, without a course enrolment.
 */
class ensure_learner extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'email' => new external_value(PARAM_EMAIL, 'Learner email'),
            'firstname' => new external_value(PARAM_TEXT, 'First name', VALUE_DEFAULT, ''),
            'lastname' => new external_value(PARAM_TEXT, 'Last name', VALUE_DEFAULT, ''),
            'companyid' => new external_value(PARAM_INT, 'IOMAD company id'),
        ]);
    }

    public static function execute($email, $firstname = '', $lastname = '', $companyid = 0): array {
        [
            'email' => $email, 'firstname' => $firstname,
            'lastname' => $lastname, 'companyid' => $companyid,
        ] = self::validate_parameters(self::execute_parameters(), [
            'email' => $email, 'firstname' => $firstname,
            'lastname' => $lastname, 'companyid' => $companyid,
        ]);

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/privacient:managecontent', $context);

        $user = \local_privacient\learner::ensure($email, $firstname, $lastname, (int) $companyid);
        return ['userid' => (int) $user->id];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'userid' => new external_value(PARAM_INT, 'Moodle user id'),
        ]);
    }
}
