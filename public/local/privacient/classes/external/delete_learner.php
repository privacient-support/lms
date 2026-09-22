<?php
namespace local_privacient\external;

defined('MOODLE_INTERNAL') || die();

use core_external\{external_api, external_function_parameters, external_multiple_structure,
    external_single_structure, external_value};
use context_system;

/**
 * Remove a console team member from the learning platform.
 *
 * The console deletes a team member "from everywhere"; this is the LMS half.
 */
class delete_learner extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'email' => new external_value(PARAM_EMAIL, 'Learner email'),
            'companyid' => new external_value(PARAM_INT, 'IOMAD company id'),
            'courseids' => new external_multiple_structure(
                new external_value(PARAM_INT, 'Course the company enrolled them on'),
                'Courses to unenrol from when the account itself is kept',
                VALUE_DEFAULT,
                []
            ),
        ]);
    }

    public static function execute($email, $companyid, $courseids = []): array {
        [
            'email' => $email, 'companyid' => $companyid, 'courseids' => $courseids,
        ] = self::validate_parameters(self::execute_parameters(), [
            'email' => $email, 'companyid' => $companyid, 'courseids' => $courseids,
        ]);

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/privacient:managecontent', $context);

        $outcome = \local_privacient\learner::remove($email, (int) $companyid, array_map('intval', $courseids));
        return ['outcome' => $outcome];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'outcome' => new external_value(PARAM_ALPHA,
                'deleted (account removed), detached (kept: belongs elsewhere too), or notfound'),
        ]);
    }
}
