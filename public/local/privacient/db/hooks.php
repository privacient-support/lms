<?php
defined('MOODLE_INTERNAL') || die();

$callbacks = [
    [
        // Fires before any output, so the learner never sees a flash of
        // Moodle's dashboard before being sent back to the portal.
        'hook' => \core\hook\output\before_http_headers::class,
        'callback' => \local_privacient\hook_callbacks::class . '::before_http_headers',
        'priority' => 0,
    ],
];
