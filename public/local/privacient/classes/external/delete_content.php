<?php
namespace local_privacient\external;

defined('MOODLE_INTERNAL') || die();

use core_external\{external_api, external_function_parameters, external_single_structure, external_value};
use context_system;

/** Remove a Content Hub item and its stored file. */
class delete_content extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'id' => new external_value(PARAM_INT, 'Content item id'),
            'tenantid' => new external_value(
                PARAM_INT,
                'Tenant the caller may delete from: -1 any, 0 global, >0 that tenant',
                VALUE_DEFAULT,
                -1
            ),
        ]);
    }

    public static function execute($id, $tenantid = -1): array {
        global $DB;

        ['id' => $id, 'tenantid' => $tenantid] = self::validate_parameters(
            self::execute_parameters(), ['id' => $id, 'tenantid' => $tenantid]
        );

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/privacient:managecontent', $context);

        $record = $DB->get_record('local_privacient_content', ['id' => $id]);
        if (!$record) {
            return ['deleted' => false];
        }

        // Ownership check, so a tenant cannot delete the global library or
        // another tenant's material even if it learns the id.
        if ((int) $tenantid >= 0 && (int) $record->tenantid !== (int) $tenantid) {
            // The reason goes in $a, not in debuginfo. Passing null there
            // rendered the raw "{$a}" placeholder to the user and buried the
            // real cause in debuginfo, which is hidden unless debugging is on —
            // so a scope mismatch read as an unexplained permissions failure.
            throw new \moodle_exception(
                'nopermissions',
                'error',
                '',
                'delete content belonging to a different library'
            );
        }

        $fs = get_file_storage();
        $fs->delete_area_files($context->id, 'local_privacient', 'content', $id);
        // The cover image lives in a sibling area under the same itemid.
        $fs->delete_area_files($context->id, 'local_privacient', 'poster', $id);
        $DB->delete_records('local_privacient_content', ['id' => $id]);

        return ['deleted' => true];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'deleted' => new external_value(PARAM_BOOL, 'Whether it was removed'),
        ]);
    }
}
