<?php
/**
 * Push training progress to the console the moment it happens.
 *
 * Polling would leave completion minutes stale and re-query Moodle constantly
 * for learners who have not moved. Observing the events Moodle already fires
 * costs nothing until something actually changes.
 */
defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        // A learner opened a SCO — the training has been started.
        'eventname' => '\mod_scorm\event\sco_launched',
        'callback' => '\local_privacient\observer::sco_launched',
    ],
    [
        // Completion state changed for any activity, SCORM included.
        'eventname' => '\core\event\course_module_completion_updated',
        'callback' => '\local_privacient\observer::completion_updated',
    ],
    [
        'eventname' => '\core\event\course_completed',
        'callback' => '\local_privacient\observer::course_completed',
    ],
    [
        // A learner opened a questionnaire paper.
        'eventname' => '\mod_quiz\event\attempt_started',
        'callback' => '\local_privacient\observer::quiz_attempt_started',
    ],
    [
        // A paper has been marked. Not attempt_submitted: that fires before
        // mod_quiz grades the attempt, so the gradebook still holds the
        // previous attempt's mark — or nothing at all on a first sitting.
        //
        // Completion alone cannot carry this. A quiz with a pass mark stays
        // incomplete when a learner fails, so Moodle writes no completion row
        // and fires no completion event, and a learner who had sat the paper
        // twice was indistinguishable from one who had never opened it.
        'eventname' => '\mod_quiz\event\attempt_graded',
        'callback' => '\local_privacient\observer::quiz_attempt_graded',
    ],
];
