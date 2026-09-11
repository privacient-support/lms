<?php
namespace local_privacient\external;

defined('MOODLE_INTERNAL') || die();

use core_external\{external_api, external_function_parameters, external_multiple_structure,
    external_single_structure, external_value};
use context_system;

/** List Content Hub items, optionally filtered by kind or a search term. */
class list_content extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'kind' => new external_value(PARAM_ALPHA, 'Filter by kind', VALUE_DEFAULT, ''),
            'search' => new external_value(PARAM_TEXT, 'Search title, description and tags', VALUE_DEFAULT, ''),
            'tenantid' => new external_value(
                PARAM_INT,
                'Which tenant library to read: -1 every tenant, 0 global only, >0 that tenant',
                VALUE_DEFAULT,
                0
            ),
            'includeglobal' => new external_value(
                PARAM_BOOL,
                'Also return the global library alongside the tenant one',
                VALUE_DEFAULT,
                1
            ),
        ]);
    }

    public static function execute($kind = '', $search = '', $tenantid = 0, $includeglobal = true): array {
        global $DB;

        [
            'kind' => $kind, 'search' => $search,
            'tenantid' => $tenantid, 'includeglobal' => $includeglobal,
        ] = self::validate_parameters(self::execute_parameters(), [
            'kind' => $kind, 'search' => $search,
            'tenantid' => $tenantid, 'includeglobal' => $includeglobal,
        ]);

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/privacient:viewcontent', $context);

        $where = [];
        $params = [];

        // Tenant visibility. The console resolves who may see what and passes
        // the answer down; this service account has no session to reason from.
        if ((int) $tenantid < 0) {
            // Everything, for a superadmin.
        } else if ((int) $tenantid === 0) {
            $where[] = 'tenantid = 0';
        } else if ($includeglobal) {
            $where[] = '(tenantid = :tid OR tenantid = 0)';
            $params['tid'] = (int) $tenantid;
        } else {
            $where[] = 'tenantid = :tid';
            $params['tid'] = (int) $tenantid;
        }
        if ($kind !== '') {
            $where[] = 'kind = :kind';
            $params['kind'] = $kind;
        }
        if (trim($search) !== '') {
            $like = '%' . $DB->sql_like_escape(trim($search)) . '%';
            $where[] = '(' . $DB->sql_like('title', ':t', false) . ' OR '
                . $DB->sql_like('description', ':d', false) . ' OR '
                . $DB->sql_like('tags', ':g', false) . ')';
            $params['t'] = $like;
            $params['d'] = $like;
            $params['g'] = $like;
        }

        $records = $DB->get_records_select(
            'local_privacient_content',
            implode(' AND ', $where) ?: '',
            $params,
            'timecreated DESC, id DESC'
        );

        $items = [];
        foreach ($records as $r) {
            $items[] = [
                'id' => (int) $r->id,
                'tenantid' => (int) $r->tenantid,
                'kind' => $r->kind,
                'title' => $r->title,
                'description' => (string) $r->description,
                'tags' => (string) $r->tags,
                'filename' => (string) $r->filename,
                'posterfilename' => (string) $r->posterfilename,
                'mimetype' => (string) $r->mimetype,
                'filesize' => (int) $r->filesize,
                'createdby' => (string) $r->createdby,
                'timecreated' => (int) $r->timecreated,
            ];
        }
        return ['items' => $items, 'contextid' => (int) $context->id];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'contextid' => new external_value(PARAM_INT, 'System context id, needed to build file URLs'),
            'items' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'Item id'),
                    'tenantid' => new external_value(PARAM_INT, 'Owning tenant, 0 = global'),
                    'kind' => new external_value(PARAM_ALPHA, 'Kind'),
                    'title' => new external_value(PARAM_TEXT, 'Title'),
                    'description' => new external_value(PARAM_RAW, 'Description'),
                    'tags' => new external_value(PARAM_RAW, 'Comma separated tags'),
                    'filename' => new external_value(PARAM_FILE, 'File name'),
                    'posterfilename' => new external_value(PARAM_RAW, 'Cover image name, empty when none'),
                    'mimetype' => new external_value(PARAM_RAW, 'MIME type'),
                    'filesize' => new external_value(PARAM_INT, 'Size in bytes'),
                    'createdby' => new external_value(PARAM_RAW, 'Who imported it'),
                    'timecreated' => new external_value(PARAM_INT, 'Unix time'),
                ])
            ),
        ]);
    }
}
