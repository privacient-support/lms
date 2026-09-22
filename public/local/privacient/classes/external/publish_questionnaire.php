<?php
namespace local_privacient\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

defined('MOODLE_INTERNAL') || die();

require_once($GLOBALS['CFG']->dirroot . '/course/lib.php');
require_once($GLOBALS['CFG']->dirroot . '/course/modlib.php');
require_once($GLOBALS['CFG']->dirroot . '/question/engine/bank.php');
require_once($GLOBALS['CFG']->dirroot . '/mod/quiz/locallib.php');

/**
 * Turn a questionnaire into a real Moodle quiz.
 *
 * The console owns the questions; Moodle owns the sitting of them. That split
 * is deliberate: authoring in a spreadsheet and reusing one question across
 * many quizzes is a console job, while presenting a paper, marking it, handling
 * a lost connection halfway through and recording a grade are all things
 * mod_quiz has done for twenty years and we should not rewrite.
 *
 * Everything the questionnaire specifies has a native equivalent, which is why
 * this is a translation rather than a simulation:
 *
 *   - the pool becomes a question category, private to this quiz's course;
 *   - "ask N at random" becomes N random-question slots drawn from it, so
 *     Moodle picks per attempt and two learners sit different papers;
 *   - "shuffle the options" is the quiz's own shuffleanswers, set on the quiz
 *     and on each question;
 *   - the pass mark becomes the grade item's gradepass, and completion is tied
 *     to passing — which is what lets a learning path hold the next step shut
 *     until somebody has actually passed, rather than merely opened the quiz.
 */
class publish_questionnaire extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'name' => new external_value(PARAM_TEXT, 'Quiz name shown to learners'),
            'intro' => new external_value(PARAM_RAW, 'Optional description', VALUE_DEFAULT, ''),
            'questioncount' => new external_value(PARAM_INT, 'How many to ask each learner'),
            'passpercent' => new external_value(PARAM_INT, 'Percentage needed to pass'),
            'shuffleoptions' => new external_value(PARAM_BOOL, 'Shuffle answer options', VALUE_DEFAULT, true),
            'maxattempts' => new external_value(PARAM_INT, 'Tries allowed; 0 is unlimited', VALUE_DEFAULT, 0),
            'courseid' => new external_value(PARAM_INT, 'Existing course, or 0 to make one', VALUE_DEFAULT, 0),
            'questions' => new external_multiple_structure(
                new external_single_structure([
                    'question' => new external_value(PARAM_RAW, 'The question text'),
                    'options' => new external_multiple_structure(
                        new external_value(PARAM_RAW, 'One answer option')
                    ),
                    'correct' => new external_multiple_structure(
                        new external_value(PARAM_INT, 'Index into options of a correct answer')
                    ),
                    'explanation' => new external_value(PARAM_RAW, 'Shown after answering', VALUE_DEFAULT, ''),
                ])
            ),
            'companyid' => new external_value(
                PARAM_INT,
                'When >0, the reused course must belong to this company; fail closed otherwise',
                VALUE_DEFAULT,
                0
            ),
        ]);
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'courseid' => new external_value(PARAM_INT, 'Course holding the quiz'),
            'cmid' => new external_value(PARAM_INT, 'Course module id of the quiz'),
            'categoryid' => new external_value(PARAM_INT, 'Question category holding the pool'),
            'added' => new external_value(PARAM_INT, 'Questions written into the pool'),
        ]);
    }

    /**
     * @param string $name
     * @param string $intro
     * @param int $questioncount
     * @param int $passpercent
     * @param bool $shuffleoptions
     * @param int $maxattempts
     * @param int $courseid
     * @param array $questions
     * @return array
     */
    public static function execute(
        string $name,
        string $intro,
        int $questioncount,
        int $passpercent,
        bool $shuffleoptions,
        int $maxattempts,
        int $courseid,
        array $questions,
        int $companyid = 0
    ): array {
        global $CFG, $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'name' => $name,
            'intro' => $intro,
            'questioncount' => $questioncount,
            'passpercent' => $passpercent,
            'shuffleoptions' => $shuffleoptions,
            'maxattempts' => $maxattempts,
            'courseid' => $courseid,
            'questions' => $questions,
            'companyid' => $companyid,
        ]);

        self::validate_context(\context_system::instance());
        require_capability('local/privacient:managecontent', \context_system::instance());

        // Tenant scoping: reusing an existing course adds a quiz to it, so when
        // the caller names a company the target must be that company's own
        // course. Fail closed on a mismatch. Creating a fresh course is exempt
        // — it carries no company link yet.
        if ((int) $params['companyid'] > 0 && (int) $params['courseid'] > 0
                && !$DB->record_exists('local_iomad_company_courses', [
                    'companyid' => (int) $params['companyid'], 'courseid' => (int) $params['courseid'],
                ])) {
            throw new \moodle_exception(
                'nopermissions', 'error', '', null,
                'That course does not belong to the given company'
            );
        }

        if (empty($params['questions'])) {
            throw new \moodle_exception('invalidparameter', 'debug', '', null,
                'A questionnaire needs at least one question.');
        }
        // Asking for more than the pool holds would silently serve a shorter
        // paper than the pass mark was calculated against, so the marks would
        // be wrong rather than the quiz merely being small.
        $count = max(1, min((int) $params['questioncount'], count($params['questions'])));
        $passpercent = max(1, min(100, (int) $params['passpercent']));

        $reusing = (bool) $params['courseid'];
        $course = $reusing
            ? $DB->get_record('course', ['id' => $params['courseid']], '*', MUST_EXIST)
            : self::create_course_for($params['name'], $params['intro']);

        // Everything from here can fail — a missing capability, a malformed
        // question — and a course created moments ago would then be left
        // behind with nothing pointing at it. The caller records the course id
        // only on success, so cleaning up here is the only chance to do it.
        try {

            // The quiz itself. Graded out of 100 so the pass mark stored against it
            // is the percentage the author typed, with no arithmetic in between to
            // get wrong.
            $moduleinfo = new \stdClass();
            $moduleinfo->course = $course->id;
            $moduleinfo->module = $DB->get_field('modules', 'id', ['name' => 'quiz'], MUST_EXIST);
            $moduleinfo->modulename = 'quiz';
            $moduleinfo->section = 0;
            $moduleinfo->visible = 1;
            $moduleinfo->name = $params['name'];
            $moduleinfo->intro = (string) $params['intro'];
            $moduleinfo->introformat = FORMAT_HTML;
            $moduleinfo->cmidnumber = '';
            $moduleinfo->grade = 100;
            $moduleinfo->gradepass = $passpercent;
            $moduleinfo->grademethod = QUIZ_GRADEHIGHEST;
            // 0 is Moodle's own value for unlimited, so it passes straight
            // through. Clamped to a sane ceiling because this arrives over the
            // wire, and an accidental 9999 is not a limit anybody meant.
            $moduleinfo->attempts = max(0, min(20, (int) $params['maxattempts']));
            $moduleinfo->preferredbehaviour = 'deferredfeedback';
            $moduleinfo->shuffleanswers = $params['shuffleoptions'] ? 1 : 0;
            $moduleinfo->questionsperpage = 1;
            $moduleinfo->navmethod = 'free';
            $moduleinfo->timeopen = 0;
            $moduleinfo->timeclose = 0;
            $moduleinfo->timelimit = 0;
            $moduleinfo->overduehandling = 'autosubmit';
            $moduleinfo->graceperiod = 0;
            $moduleinfo->canredoquestions = 0;
            $moduleinfo->attemptonlast = 0;
            $moduleinfo->sumgrades = 0;
            $moduleinfo->decimalpoints = 2;
            $moduleinfo->questiondecimalpoints = -1;
            $moduleinfo->reviewattempt = 0x10010;
            $moduleinfo->reviewcorrectness = 0x10010;
            $moduleinfo->reviewmarks = 0x10010;
            $moduleinfo->reviewspecificfeedback = 0x10010;
            $moduleinfo->reviewgeneralfeedback = 0x10010;
            $moduleinfo->reviewrightanswer = 0x10010;
            $moduleinfo->reviewoverallfeedback = 0x10010;
            $moduleinfo->showuserpicture = 0;
            $moduleinfo->showblocks = 0;
            $moduleinfo->quizpassword = '';
            $moduleinfo->subnet = '';
            $moduleinfo->browsersecurity = '-';
            $moduleinfo->delay1 = 0;
            $moduleinfo->delay2 = 0;
            // Completion means *passing*, not attempting. In a learning path the
            // next step opens on completion, so anything weaker would let somebody
            // walk past a quiz by opening and abandoning it.
            $moduleinfo->completion = COMPLETION_TRACKING_AUTOMATIC;
            $moduleinfo->completionview = COMPLETION_VIEW_NOT_REQUIRED;
            $moduleinfo->completionusegrade = 1;
            $moduleinfo->completionpassgrade = 1;
            $moduleinfo->completionattemptsexhausted = 0;

            $created = add_moduleinfo($moduleinfo, $course);
            $cmid = (int) $created->coursemodule;

            // The pool lives in this course's own question bank, not the site's, so
            // deleting the course takes the questions with it and one campaign's
            // pool can never turn up in another's quiz.
            $modcontext = \context_module::instance($cmid);
            $categoryid = self::create_category($modcontext, $params['name']);

            $added = 0;
            foreach ($params['questions'] as $q) {
                if (self::create_multichoice($q, $categoryid, $modcontext, (bool) $params['shuffleoptions'])) {
                    $added++;
                }
            }

            // A question with fewer than two options, or none marked correct,
            // cannot be asked and was skipped. If that leaves nothing, the quiz
            // would be a real activity with an empty pool — it would open, draw
            // nothing, and mark everyone at zero. Refuse instead; the catch
            // below removes the course.
            if ($added === 0) {
                throw new \moodle_exception('invalidparameter', 'debug', '', null,
                    'None of the questions could be written: each needs at least '
                    . 'two options and one marked correct.');
            }
            // Draw no more than were actually written. Capping on the number
            // *submitted* would serve short papers whenever a question was
            // skipped, and the pass mark would then be measured against a
            // paper of a different length than intended.
            $count = min($count, $added);

            // N random slots rather than N fixed ones: Moodle then draws for each
            // attempt, which is what makes two learners' papers differ.
            $quizobj = \mod_quiz\quiz_settings::create($created->instance);
            $structure = $quizobj->get_structure();
            $structure->add_random_questions(0, $count, [
                'filter' => [
                    'category' => [
                        'jointype' => \core_question\local\bank\condition::JOINTYPE_DEFAULT,
                        'values' => [$categoryid],
                        'filteroptions' => ['includesubcategories' => false],
                    ],
                ],
            ]);

            // Each slot is worth one mark; the quiz is then scaled to its grade of
            // 100, so the pass mark keeps meaning what it says however many are
            // asked.
            $quizobj->get_grade_calculator()->recompute_quiz_sumgrades();

            rebuild_course_cache($course->id, true);

            return [
                'courseid' => (int) $course->id,
                'cmid' => $cmid,
                'categoryid' => $categoryid,
                'added' => $added,
            ];
        } catch (\Throwable $e) {
            if (!$reusing) {
                // Best effort: the original failure is what the caller needs to
                // see, so a problem tidying up must not replace it.
                try {
                    require_once($GLOBALS['CFG']->dirroot . '/course/lib.php');
                    delete_course($course, false);
                } catch (\Throwable $ignored) {
                    // Nothing useful to do; the orphan is the lesser problem.
                }
            }
            throw $e;
        }
    }

    /** A course to hold this quiz, uniquely shortnamed like the others. */
    private static function create_course_for(string $name, string $intro): \stdClass {
        global $DB;

        $categoryid = (int) get_config('local_privacient', 'coursecategory');
        if (!$categoryid || !$DB->record_exists('course_categories', ['id' => $categoryid])) {
            $first = $DB->get_records('course_categories', null, 'id ASC', 'id', 0, 1);
            $categoryid = $first ? (int) reset($first)->id : 1;
        }

        $base = 'privacient-quiz-' . time();
        $shortname = $base;
        $n = 2;
        while ($DB->record_exists('course', ['shortname' => $shortname])) {
            $shortname = $base . '-' . $n++;
        }

        $data = new \stdClass();
        $data->fullname = $name;
        $data->shortname = $shortname;
        $data->category = $categoryid;
        $data->summary = $intro;
        $data->summaryformat = FORMAT_HTML;
        $data->format = 'singleactivity';
        $data->activitytype = 'quiz';
        $data->visible = 1;
        $data->enablecompletion = 1;

        return create_course($data);
    }

    /** The question category this quiz draws from. */
    private static function create_category(\context $context, string $name): int {
        global $DB;

        $category = new \stdClass();
        $category->name = $name;
        $category->contextid = $context->id;
        $category->info = 'Created by Privacient from a questionnaire.';
        $category->infoformat = FORMAT_HTML;
        $category->stamp = make_unique_id_code();
        $category->parent = 0;
        $category->sortorder = 999;
        $category->idnumber = null;

        return (int) $DB->insert_record('question_categories', $category);
    }

    /**
     * Write one multiple-choice question into the pool.
     *
     * Built the way Moodle's own import does — a question row, a bank entry, a
     * version, then the qtype's save_question_options — rather than writing the
     * answer tables directly. The qtype owns the shape of its own options, and
     * bypassing it is how a question ends up unopenable in the editor later.
     */
    private static function create_multichoice(
        array $q,
        int $categoryid,
        \context $context,
        bool $shuffle
    ): bool {
        global $DB, $USER;

        $options = array_values(array_filter(
            array_map(static fn($o) => (string) $o, $q['options']),
            static fn($o) => trim($o) !== ''
        ));
        if (count($options) < 2) {
            return false;
        }
        $correct = array_values(array_unique(array_filter(
            array_map('intval', $q['correct']),
            static fn($i) => $i >= 0 && $i < count($options)
        )));
        if (empty($correct)) {
            return false;
        }

        $question = new \stdClass();
        $question->parent = 0;
        // Moodle shows the name in the bank; the text itself is the most useful
        // thing to see there, trimmed to fit the column.
        $question->name = \core_text::substr(trim(strip_tags((string) $q['question'])), 0, 250);
        if ($question->name === '') {
            $question->name = 'Question';
        }
        $question->questiontext = (string) $q['question'];
        $question->questiontextformat = FORMAT_HTML;
        $question->generalfeedback = (string) ($q['explanation'] ?? '');
        $question->generalfeedbackformat = FORMAT_HTML;
        $question->defaultmark = 1;
        $question->penalty = 0;
        $question->qtype = 'multichoice';
        $question->length = 1;
        $question->stamp = make_unique_id_code();
        $question->timecreated = time();
        $question->timemodified = time();
        $question->createdby = $USER->id;
        $question->modifiedby = $USER->id;
        $question->id = $DB->insert_record('question', $question);

        // One bank entry per question, holding its versions. The insert id is
        // used directly: looking it up afterwards by category and owner would
        // match whichever sibling came first.
        $entryid = $DB->insert_record('question_bank_entries', (object) [
            'questioncategoryid' => $categoryid,
            'idnumber' => null,
            'ownerid' => $USER->id,
        ]);

        $DB->insert_record('question_versions', (object) [
            'questionbankentryid' => $entryid,
            'questionid' => $question->id,
            'version' => 1,
            'status' => \core_question\local\bank\question_version_status::QUESTION_STATUS_READY,
        ]);

        // Form-shaped data, which is what save_question_options consumes.
        $single = count($correct) === 1;
        $question->context = $context;
        $question->category = $categoryid;
        $question->single = $single ? 1 : 0;
        $question->shuffleanswers = $shuffle ? 1 : 0;
        $question->answernumbering = 'abc';
        $question->shownumcorrect = $single ? 0 : 1;
        $question->showstandardinstruction = 0;
        $question->correctfeedback = ['text' => '', 'format' => FORMAT_HTML];
        $question->partiallycorrectfeedback = ['text' => '', 'format' => FORMAT_HTML];
        $question->incorrectfeedback = ['text' => '', 'format' => FORMAT_HTML];
        $question->answer = [];
        $question->fraction = [];
        $question->feedback = [];

        // With one right answer it takes the whole mark. With several, each
        // takes an equal share and each wrong one costs the same share, so
        // ticking everything scores zero rather than full marks.
        $share = 1 / count($correct);
        foreach ($options as $i => $text) {
            $isright = in_array($i, $correct, true);
            $question->answer[$i] = ['text' => $text, 'format' => FORMAT_HTML];
            $question->fraction[$i] = $isright
                ? ($single ? 1.0 : round($share, 7))
                : ($single ? 0.0 : round(-$share, 7));
            $question->feedback[$i] = ['text' => '', 'format' => FORMAT_HTML];
        }

        $result = \question_bank::get_qtype('multichoice')->save_question_options($question);
        if (!empty($result->error)) {
            throw new \moodle_exception('cannotsavequestion', 'question', '', null, $result->error);
        }

        return true;
    }
}
