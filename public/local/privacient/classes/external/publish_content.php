<?php
namespace local_privacient\external;

defined('MOODLE_INTERNAL') || die();

use core_external\{external_api, external_function_parameters, external_single_structure, external_value};
use context_system;

/**
 * Turn a stored Content Hub item into a playable Moodle activity.
 *
 * A SCORM package sitting in a file area is not training: it is a zip of HTML
 * and JavaScript that only becomes playable — and trackable — once Moodle
 * unpacks it into a `mod_scorm` activity and supplies the SCORM runtime.
 *
 * Core web services expose no "create module" function, which is why this lives
 * in the plugin: it uses Moodle's own `add_moduleinfo()`, the same call the
 * course editor makes.
 */
class publish_content extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'id' => new external_value(PARAM_INT, 'Content item id'),
            'courseid' => new external_value(PARAM_INT, 'Existing course, or 0 to create one', VALUE_DEFAULT, 0),
            'forcenew' => new external_value(
                PARAM_BOOL,
                'Always build a new course, even if this item was published before',
                VALUE_DEFAULT,
                0
            ),
        ]);
    }

    public static function execute($id, $courseid = 0, $forcenew = false): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/course/modlib.php');

        ['id' => $id, 'courseid' => $courseid, 'forcenew' => $forcenew] =
            self::validate_parameters(self::execute_parameters(), [
                'id' => $id, 'courseid' => $courseid, 'forcenew' => $forcenew,
            ]);

        $context = context_system::instance();
        self::validate_context($context);
        require_capability('local/privacient:managecontent', $context);

        $record = $DB->get_record('local_privacient_content', ['id' => $id], '*', MUST_EXIST);
        if (!in_array($record->kind, ['scorm', 'video'], true)) {
            throw new \moodle_exception(
                'invalidarguments', 'error', '', null,
                'Only SCORM packages and videos can be published as activities'
            );
        }

        // Already published and still present: hand back what exists rather
        // than creating a duplicate activity every time this is called.
        //
        // `forcenew` overrides that, because SCORM attempts, the resume
        // bookmark and Moodle's completion record all key on (activity, user).
        // Sharing one activity across campaigns therefore carries last
        // campaign's progress into the next one — and, because completion never
        // changes state, the second campaign can never complete at all.
        if (!$forcenew && !empty($record->courseid) && !empty($record->cmid)
            && $DB->record_exists('course_modules', ['id' => $record->cmid])) {
            return [
                'courseid' => (int) $record->courseid,
                'cmid' => (int) $record->cmid,
                'created' => false,
            ];
        }

        // Reuse the course from a previous partial run rather than leaving a
        // fresh one behind on every retry.
        if (!$forcenew && !$courseid && !empty($record->courseid)
            && $DB->record_exists('course', ['id' => $record->courseid])) {
            $courseid = (int) $record->courseid;
        }
        $course = $courseid
            ? $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST)
            : self::create_course_for($record);

        // Recorded immediately: if activity creation fails below, the retry
        // finds this course instead of creating another.
        if ((int) $record->courseid !== (int) $course->id) {
            $DB->set_field('local_privacient_content', 'courseid', $course->id, ['id' => $record->id]);
        }

        // mod_scorm and mod_resource both consume their file from a DRAFT area,
        // so the stored package is copied into one first.
        $draftid = self::stored_file_to_draft($context, $record);

        $moduleinfo = new \stdClass();
        $moduleinfo->course = $course->id;
        // add_moduleinfo() needs the numeric module id, not only its name.
        $moduleinfo->module = $DB->get_field(
            'modules', 'id',
            ['name' => $record->kind === 'scorm' ? 'scorm' : 'resource'],
            MUST_EXIST
        );
        $moduleinfo->section = 0;
        $moduleinfo->visible = 1;
        $moduleinfo->name = $record->title;
        $moduleinfo->intro = (string) $record->description;
        $moduleinfo->introformat = FORMAT_HTML;
        $moduleinfo->cmidnumber = '';

        if ($record->kind === 'scorm') {
            $moduleinfo->modulename = 'scorm';
            $moduleinfo->scormtype = 'local';
            $moduleinfo->packagefile = $draftid;
            $moduleinfo->width = 100;
            $moduleinfo->height = 500;
            $moduleinfo->popup = 0;
            $moduleinfo->displayattemptstatus = 1;
            $moduleinfo->grademethod = 1;
            $moduleinfo->maxgrade = 100;
            $moduleinfo->maxattempt = 0;
            $moduleinfo->forcenewattempt = 0;
            $moduleinfo->lastattemptlock = 0;
            $moduleinfo->forcecompleted = 0;
            $moduleinfo->auto = 0;
            $moduleinfo->updatefreq = 0;
            // SCORM reports its own completion over the runtime API, so Moodle
            // can be trusted to decide: complete when the SCO says 'completed'.
            $moduleinfo->completion = COMPLETION_TRACKING_AUTOMATIC;
            $moduleinfo->completionview = COMPLETION_VIEW_NOT_REQUIRED;
            $moduleinfo->completionstatusrequired = 4; // 'completed'
            // Go straight into the player rather than showing Moodle's course
            // structure page first. That page is Moodle chrome the learner has
            // no use for, and an extra click between them and the training.
            $moduleinfo->skipview = 2; // SCORM_SKIPVIEW_ALWAYS
            // Moodle renders its own table of contents as a side panel, but a
            // SCORM package ships its own menu — so the learner gets two navs,
            // and Moodle's squeezes the real content into a narrow column.
            $moduleinfo->hidetoc = 1; // SCORM_TOC_HIDDEN
        } else {
            // A video is a file resource, embedded so it plays in the page.
            $moduleinfo->modulename = 'resource';
            $moduleinfo->files = $draftid;
            $moduleinfo->display = 5; // RESOURCELIB_DISPLAY_EMBED
            $moduleinfo->printintro = 1;
            $moduleinfo->showsize = 0;
            $moduleinfo->showtype = 0;
            $moduleinfo->showdate = 0;
            // MANUAL, deliberately. mod_resource's only automatic rule is
            // "viewed this activity", which completes the moment the page
            // opens — it would certify a click, not a viewing. Manual tracking
            // stops Moodle completing it on view; local/privacient/progress.php
            // is what marks it done, once play.php reports a real watch.
            $moduleinfo->completion = COMPLETION_TRACKING_MANUAL;
            $moduleinfo->completionview = COMPLETION_VIEW_NOT_REQUIRED;
        }

        $created = add_moduleinfo($moduleinfo, $course);

        $DB->update_record('local_privacient_content', (object) [
            'id' => $record->id,
            'courseid' => $course->id,
            'cmid' => $created->coursemodule,
            'timemodified' => time(),
        ]);

        return [
            'courseid' => (int) $course->id,
            'cmid' => (int) $created->coursemodule,
            'created' => true,
        ];
    }

    /** A course to hold this item, named after it and uniquely shortnamed. */
    private static function create_course_for(\stdClass $record): \stdClass {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');

        $categoryid = (int) get_config('local_privacient', 'coursecategory');
        if (!$categoryid || !$DB->record_exists('course_categories', ['id' => $categoryid])) {
            $first = $DB->get_records('course_categories', null, 'id ASC', 'id', 0, 1);
            $categoryid = $first ? (int) reset($first)->id : 1;
        }

        $base = 'privacient-' . $record->id;
        $shortname = $base;
        $n = 2;
        while ($DB->record_exists('course', ['shortname' => $shortname])) {
            $shortname = $base . '-' . $n++;
        }

        $data = new \stdClass();
        $data->fullname = $record->title;
        $data->shortname = $shortname;
        $data->category = $categoryid;
        $data->summary = (string) $record->description;
        $data->summaryformat = FORMAT_HTML;
        $data->format = 'singleactivity';
        $data->activitytype = $record->kind === 'scorm' ? 'scorm' : 'resource';
        $data->visible = 1;
        // Completion tracking is what makes progress reportable at all.
        $data->enablecompletion = 1;

        return create_course($data);
    }

    /** Copy the stored file into a fresh draft area and return its item id. */
    private static function stored_file_to_draft($context, \stdClass $record): int {
        global $USER;

        $fs = get_file_storage();
        $files = $fs->get_area_files(
            $context->id, 'local_privacient', 'content', $record->id,
            'itemid, filepath, filename', false
        );
        if (empty($files)) {
            throw new \moodle_exception('nofile', 'error', '', null, 'Stored file is missing');
        }
        $stored = reset($files);

        $draftid = file_get_unused_draft_itemid();
        $fs->create_file_from_storedfile([
            'contextid' => \context_user::instance($USER->id)->id,
            'component' => 'user',
            'filearea' => 'draft',
            'itemid' => $draftid,
            'filepath' => '/',
            'filename' => $stored->get_filename(),
        ], $stored);

        return $draftid;
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'courseid' => new external_value(PARAM_INT, 'Course holding the activity'),
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
            'created' => new external_value(PARAM_BOOL, 'False when it was already published'),
        ]);
    }
}
