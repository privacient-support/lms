<?php
namespace local_privacient\external;

defined('MOODLE_INTERNAL') || die();

use core_external\{external_api, external_function_parameters, external_single_structure, external_value};
use context_system;

/**
 * Attach (or replace) a Content Hub item's cover image.
 *
 * Separate from `add_content` because the console streams each file straight
 * through to Moodle's upload endpoint — one request carries one file — so the
 * poster arrives as its own upload and is linked afterwards.
 */
class set_poster extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'id' => new external_value(PARAM_INT, 'Content item id'),
            'draftitemid' => new external_value(PARAM_INT, 'Draft item id holding the image; 0 removes the poster'),
            'tenantid' => new external_value(
                PARAM_INT,
                'Library the caller may write to: 0 global, >0 that tenant. '
                    . 'A missing or negative value is rejected, never treated as "any".',
                VALUE_DEFAULT,
                -1
            ),
        ]);
    }

    public static function execute($id, $draftitemid, $tenantid = -1): array {
        global $DB, $USER;

        ['id' => $id, 'draftitemid' => $draftitemid, 'tenantid' => $tenantid] =
            self::validate_parameters(self::execute_parameters(), [
                'id' => $id, 'draftitemid' => $draftitemid, 'tenantid' => $tenantid,
            ]);

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/privacient:managecontent', $context);

        $record = $DB->get_record('local_privacient_content', ['id' => $id]);
        if (!$record) {
            throw new \moodle_exception('invalidrecord', 'error', '', null, 'No such content item');
        }
        // Same ownership rule as delete, fail closed: a missing/negative
        // tenantid is rejected rather than matching every tenant, so a tenant
        // may not restyle the global library or another tenant's material.
        if ((int) $tenantid < 0 || (int) $record->tenantid !== (int) $tenantid) {
            // $a carries the reason; null there renders a literal "{$a}".
            throw new \moodle_exception(
                'nopermissions',
                'error',
                '',
                'change a cover image belonging to a different library'
            );
        }

        $fs = get_file_storage();
        // Replacing: the area holds at most one image.
        $fs->delete_area_files($context->id, 'local_privacient', 'poster', $id);

        if ((int) $draftitemid === 0) {
            $DB->set_field('local_privacient_content', 'posterfilename', null, ['id' => $id]);
            $DB->set_field('local_privacient_content', 'timemodified', time(), ['id' => $id]);
            return ['posterfilename' => ''];
        }

        $usercontext = \context_user::instance($USER->id);
        $draftfiles = $fs->get_area_files(
            $usercontext->id, 'user', 'draft', $draftitemid, 'itemid, filepath, filename', false
        );
        if (empty($draftfiles)) {
            throw new \moodle_exception('nofile', 'error', '', null, 'No uploaded image found for that draft id');
        }
        $draft = reset($draftfiles);

        if (strpos((string) $draft->get_mimetype(), 'image/') !== 0) {
            throw new \moodle_exception(
                'invalidarguments', 'error', '', null,
                'A poster must be an image'
            );
        }

        $fs->create_file_from_storedfile([
            'contextid' => $context->id,
            'component' => 'local_privacient',
            'filearea' => 'poster',
            'itemid' => $id,
            'filepath' => '/',
            'filename' => $draft->get_filename(),
        ], $draft);
        $fs->delete_area_files($usercontext->id, 'user', 'draft', $draftitemid);

        $DB->set_field('local_privacient_content', 'posterfilename', $draft->get_filename(), ['id' => $id]);
        $DB->set_field('local_privacient_content', 'timemodified', time(), ['id' => $id]);

        return ['posterfilename' => $draft->get_filename()];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'posterfilename' => new external_value(PARAM_FILE, 'Stored poster name, empty when removed'),
        ]);
    }
}
