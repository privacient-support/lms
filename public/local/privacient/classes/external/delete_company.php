<?php
namespace local_privacient\external;

defined('MOODLE_INTERNAL') || die();

use core_external\{external_api, external_function_parameters, external_single_structure, external_value};
use block_iomad_company_admin\event\company_deleted;
use local_iomad\custom_context\context_company;
use local_iomad\iomad;

/**
 * Delete an IOMAD company over the web service.
 *
 * IOMAD deletes a company by firing `company_deleted`, which its own observer
 * turns into the `deletecompanytask` adhoc task — that task is what removes the
 * company's users, departments, course assignments, licences and category. This
 * mirrors exactly what the Manage Companies UI does
 * (blocks/iomad_company_admin/classes/forms/company_delete_form.php), including
 * the same capability check, so nothing here reimplements the cleanup.
 *
 * Deletion is therefore ASYNCHRONOUS: this returns once the task is queued, and
 * cron performs the work. The company is renamed "Deleting <name>" meanwhile.
 */
class delete_company extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'companyid' => new external_value(PARAM_INT, 'Company ID to delete'),
        ]);
    }

    public static function execute($companyid): array {
        global $DB, $USER;

        ['companyid' => $companyid] = self::validate_parameters(
            self::execute_parameters(),
            ['companyid' => $companyid]
        );

        // Already gone: report success so a retry after a partial failure is
        // not an error.
        if (!$DB->get_record('local_iomad_companies', ['id' => $companyid])) {
            return ['queued' => false, 'message' => 'Company does not exist'];
        }

        $companycontext = context_company::instance($companyid);
        self::validate_context($companycontext);
        iomad::require_capability(
            'block/iomad_company_admin:company_delete',
            $companycontext
        );

        // Refuse while child companies remain, matching the control plane: the
        // adhoc task would otherwise orphan them.
        //
        // Children that are THEMSELVES already queued for deletion do not
        // count. Deletion here is asynchronous, so deleting a parent moments
        // after its last child would otherwise always fail — the child's task
        // has not run yet — and leave the parent stranded in IOMAD while the
        // console believes the customer is gone.
        $children = $DB->get_records('local_iomad_companies', ['parentid' => $companyid], '', 'id');
        $blocking = [];
        foreach ($children as $child) {
            if (!self::deletion_is_queued((int) $child->id)) {
                $blocking[] = $child->id;
            }
        }
        if (!empty($blocking)) {
            return [
                'queued' => false,
                'message' => 'Company still has child companies: ' . implode(', ', $blocking),
            ];
        }

        $event = company_deleted::create([
            'context'  => $companycontext,
            'objectid' => $companyid,
            'userid'   => $USER->id,
            'other'    => ['companyid' => $companyid],
        ]);
        $event->trigger();

        return ['queued' => true, 'message' => 'Deletion scheduled'];
    }

    /**
     * Is a deletion task already queued for this company?
     *
     * IOMAD stores the target in the task's custom data as {"companyid":N}.
     * Matching on that is exact, unlike inspecting the "Deleting ..." name the
     * company is renamed to, which is a translated string.
     */
    private static function deletion_is_queued(int $companyid): bool {
        global $DB;

        $tasks = $DB->get_records(
            'task_adhoc',
            ['classname' => '\\local_iomad\\task\\deletecompanytask'],
            '',
            'id, customdata'
        );
        foreach ($tasks as $task) {
            $data = json_decode((string) $task->customdata);
            if (!empty($data->companyid) && (int) $data->companyid === $companyid) {
                return true;
            }
        }
        return false;
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'queued'  => new external_value(PARAM_BOOL, 'Whether deletion was scheduled'),
            'message' => new external_value(PARAM_TEXT, 'Human readable outcome'),
        ]);
    }
}
