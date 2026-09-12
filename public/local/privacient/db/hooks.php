<?php
defined('MOODLE_INTERNAL') || die();

$callbacks = [
    [
        // Injects the portal link that replaces Moodle's "Exit activity".
        'hook' => \core\hook\output\before_standard_top_of_body_html_generation::class,
        'callback' => \local_privacient\hook_callbacks::class
            . '::before_standard_top_of_body_html_generation',
        'priority' => 0,
    ],
    [
        // Runs after the theme has emitted its own icon, so this one wins.
        'hook' => \core\hook\output\before_standard_head_html_generation::class,
        'callback' => \local_privacient\hook_callbacks::class . '::before_standard_head_html_generation',
        'priority' => 0,
    ],
    [
        // Fires before any output, so the learner never sees a flash of
        // Moodle's dashboard before being sent back to the portal.
        'hook' => \core\hook\output\before_http_headers::class,
        'callback' => \local_privacient\hook_callbacks::class . '::before_http_headers',
        'priority' => 0,
    ],
    [
        // Earliest point at which the database is up: the assertion consumer
        // needs its company resolved before auth_iomadsaml2 is constructed.
        'hook' => \core\hook\after_config::class,
        'callback' => \local_privacient\hook_callbacks::class . '::after_config',
        'priority' => 0,
    ],
];
