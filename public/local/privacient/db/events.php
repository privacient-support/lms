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
];
