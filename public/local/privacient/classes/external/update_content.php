<?php
namespace local_privacient\external;

defined('MOODLE_INTERNAL') || die();

use core_external\{external_api, external_function_parameters, external_single_structure, external_value};
use context_system;

/**
 * Edit a Content Hub item's metadata — title, description, tags and kind.
 *
 * Only the metadata changes; the stored file and its poster are untouched. Kind
 * is a categorisation the admin chose at upload, so allowing it to be corrected
 * here is safe — nothing about the file itself depends on it.
 */
class update_content extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'id' => new external_value(PARAM_INT, 'Content item id'),
            'title' => new external_value(PARAM_TEXT, 'Display title'),
            'description' => new external_value(PARAM_TEXT, 'Description', VALUE_DEFAULT, ''),
            'tags' => new external_value(PARAM_TEXT, 'Comma separated tags', VALUE_DEFAULT, ''),
            'kind' => new external_value(
                PARAM_ALPHA,
                'scorm|video|questionnaire|pdf|poster|wallpaper|other; blank leaves it unchanged',
                VALUE_DEFAULT,
                ''
            ),
            // Library the caller may edit: -1 any, 0 global, >0 that tenant.
            // Declared last to match the execute() signature (Moodle binds
            // these positionally).
            'tenantid' => new external_value(
                PARAM_INT,
                'Library the caller may edit: -1 any, 0 global, >0 that tenant',
                VALUE_DEFAULT,
                -1
            ),
        ]);
    }

    public static function execute($id, $title, $description = '', $tags = '', $kind = '', $tenantid = -1): array {
        global $DB;

        [
            'id' => $id,
            'title' => $title,
            'description' => $description,
            'tags' => $tags,
            'kind' => $kind,
            'tenantid' => $tenantid,
        ] = self::validate_parameters(self::execute_parameters(), [
            'id' => $id,
            'title' => $title,
            'description' => $description,
            'tags' => $tags,
            'kind' => $kind,
            'tenantid' => $tenantid,
        ]);

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/privacient:managecontent', $context);

        $record = $DB->get_record('local_privacient_content', ['id' => $id]);
        if (!$record) {
            throw new \moodle_exception('invalidrecord', 'error', '', null, 'No such content item');
        }

        // Ownership check, so a tenant cannot rename or retag the global library
        // or another tenant's material even if it learns the id — same rule as
        // delete and set_poster.
        if ((int) $tenantid >= 0 && (int) $record->tenantid !== (int) $tenantid) {
            throw new \moodle_exception(
                'nopermissions',
                'error',
                '',
                'edit content belonging to a different library'
            );
        }

        $title = trim((string) $title);
        if ($title === '') {
            throw new \moodle_exception('invalidarguments', 'error', '', null, 'A title is required');
        }

        $record->title = $title;
        $record->description = (string) $description;
        $record->tags = (string) $tags;

        $allowed = ['scorm', 'video', 'questionnaire', 'pdf', 'poster', 'wallpaper', 'other'];
        if ($kind !== '' && in_array($kind, $allowed, true)) {
            $record->kind = $kind;
        }
        $record->timemodified = time();
        $DB->update_record('local_privacient_content', $record);

        return [
            'id' => (int) $record->id,
            'tenantid' => (int) $record->tenantid,
            'kind' => (string) $record->kind,
            'title' => (string) $record->title,
            'description' => (string) $record->description,
            'tags' => (string) $record->tags,
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Content item id'),
            'tenantid' => new external_value(PARAM_INT, 'Owning tenant, 0 = global'),
            'kind' => new external_value(PARAM_ALPHA, 'Content kind'),
            'title' => new external_value(PARAM_TEXT, 'Title'),
            'description' => new external_value(PARAM_TEXT, 'Description'),
            'tags' => new external_value(PARAM_TEXT, 'Comma separated tags'),
        ]);
    }
}
