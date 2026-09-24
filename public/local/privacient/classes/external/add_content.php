<?php
namespace local_privacient\external;

defined('MOODLE_INTERNAL') || die();

use core_external\{external_api, external_function_parameters, external_single_structure, external_value};
use context_system;

/**
 * Move a freshly uploaded draft file into the Content Hub.
 *
 * The client uploads through Moodle's own `webservice/upload.php`, which
 * streams multipart data into the caller's draft area and returns an itemid.
 * Draft files are transient — Moodle garbage-collects them — so this promotes
 * one into the durable `local_privacient/content` area and records its
 * metadata. `core_files_upload` cannot do this: it refuses any target other
 * than the draft area, and carries content as base64 in a POST field.
 */
class add_content extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'draftitemid' => new external_value(PARAM_INT, 'Draft item id from webservice/upload.php'),
            'kind' => new external_value(PARAM_ALPHA, 'scorm|video|questionnaire|pdf|comic|poster|wallpaper|other'),
            'title' => new external_value(PARAM_TEXT, 'Display title'),
            'description' => new external_value(PARAM_TEXT, 'Description', VALUE_DEFAULT, ''),
            'tags' => new external_value(PARAM_TEXT, 'Comma separated tags', VALUE_DEFAULT, ''),
            // Declared last to match the execute() signature: Moodle binds
            // these positionally, not by name.
            'tenantid' => new external_value(PARAM_INT, 'Owning console tenant; 0 = global library', VALUE_DEFAULT, 0),
        ]);
    }

    public static function execute($draftitemid, $kind, $title, $description = '', $tags = '', $tenantid = 0): array {
        global $DB, $USER;

        [
            'draftitemid' => $draftitemid,
            'kind' => $kind,
            'title' => $title,
            'description' => $description,
            'tags' => $tags,
            'tenantid' => $tenantid,
        ] = self::validate_parameters(self::execute_parameters(), [
            'draftitemid' => $draftitemid,
            'kind' => $kind,
            'title' => $title,
            'description' => $description,
            'tags' => $tags,
            'tenantid' => $tenantid,
        ]);

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/privacient:managecontent', $context);

        $allowed = ['scorm', 'video', 'questionnaire', 'pdf', 'comic', 'poster', 'wallpaper', 'other'];
        if (!in_array($kind, $allowed, true)) {
            $kind = 'other';
        }

        $fs = get_file_storage();
        $usercontext = \context_user::instance($USER->id);
        $draftfiles = $fs->get_area_files(
            $usercontext->id, 'user', 'draft', $draftitemid, 'itemid, filepath, filename', false
        );
        if (empty($draftfiles)) {
            throw new \moodle_exception('nofile', 'error', '', null, 'No uploaded file found for that draft id');
        }
        $draft = reset($draftfiles);

        // Insert first: the row id becomes the file's itemid, which is what
        // ties a stored file to its metadata.
        $now = time();
        $record = (object) [
            // Ownership is decided by the console, which knows who is signed
            // in; this service account cannot tell one tenant from another.
            'tenantid' => max(0, (int) $tenantid),
            'kind' => $kind,
            'title' => $title !== '' ? $title : $draft->get_filename(),
            'description' => $description,
            'tags' => $tags,
            'filename' => $draft->get_filename(),
            'mimetype' => $draft->get_mimetype(),
            'filesize' => $draft->get_filesize(),
            'courseid' => null,
            'createdby' => $USER->email ?: $USER->username,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $record->id = $DB->insert_record('local_privacient_content', $record);

        $fs->create_file_from_storedfile([
            'contextid' => $context->id,
            'component' => 'local_privacient',
            'filearea' => 'content',
            'itemid' => $record->id,
            'filepath' => '/',
            'filename' => $draft->get_filename(),
        ], $draft);

        // The draft copy has served its purpose.
        $fs->delete_area_files($usercontext->id, 'user', 'draft', $draftitemid);

        return [
            'id' => (int) $record->id,
            'tenantid' => (int) $record->tenantid,
            'kind' => $record->kind,
            'title' => $record->title,
            'filename' => $record->filename,
            'mimetype' => (string) $record->mimetype,
            'filesize' => (int) $record->filesize,
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'Content item id'),
            'tenantid' => new external_value(PARAM_INT, 'Owning tenant, 0 = global'),
            'kind' => new external_value(PARAM_ALPHA, 'Content kind'),
            'title' => new external_value(PARAM_TEXT, 'Title'),
            'filename' => new external_value(PARAM_FILE, 'Stored file name'),
            'mimetype' => new external_value(PARAM_RAW, 'MIME type'),
            'filesize' => new external_value(PARAM_INT, 'Size in bytes'),
        ]);
    }
}
