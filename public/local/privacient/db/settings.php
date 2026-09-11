<?php
defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_privacient', 'Privacient console');

    $settings->add(new admin_setting_configtext(
        'local_privacient/callbackurl',
        'Progress callback URL',
        'Where training progress is pushed as it happens, e.g. https://host/frontend/api/internal/training/progress. Leave blank to disable.',
        '',
        PARAM_URL
    ));
    $settings->add(new admin_setting_configpasswordunmask(
        'local_privacient/callbacksecret',
        'Progress callback secret',
        'Shared secret used to sign callbacks (HMAC-SHA256 over "{timestamp}.{body}").',
        ''
    ));
    $settings->add(new admin_setting_configtext(
        'local_privacient/launchttl',
        'Launch link lifetime (seconds)',
        'How long a learner launch link stays valid. Default one week.',
        604800,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_privacient/watchthreshold',
        'Video completion threshold (%)',
        'How much of a video must actually be played before it counts as watched. '
            . 'Coverage is measured in whole seconds, so skipping ahead does not count.',
        95,
        PARAM_INT
    ));
    $settings->add(new admin_setting_configtext(
        'local_privacient/minwatchseconds',
        'Minimum watch time (seconds)',
        'A completion reported sooner than this after the player opened is rejected. '
            . 'Stops a client claiming a full watch the instant the page loads.',
        10,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_privacient/portalurl',
        'Learner portal URL',
        'Where learners live, e.g. https://host/frontend/portal/dashboard/. When set, '
            . 'learners who reach Moodle\'s own dashboard are returned here, and the '
            . 'video player links back to it. Leave blank to leave Moodle navigation alone.',
        '',
        PARAM_URL
    ));

    $ADMIN->add('localplugins', $settings);
}
