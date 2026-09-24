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
        'local_privacient/brandingurl',
        'Branding URL (optional)',
        'Where the video player fetches the learner\'s company logo and colour for the intro '
            . 'shown before a video, e.g. https://host/frontend/api/internal/training/branding/ '
            . '(keep the trailing slash). Leave blank to derive it from the progress callback URL. '
            . 'Signed with the progress callback secret.',
        '',
        PARAM_URL
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
        'A completion is rejected until the learner has spent at least this long actually '
            . 'playing the video (summed across visits, measured by the server). For MP4 videos '
            . 'the bar is also raised to the completion threshold\'s share of the video\'s real '
            . 'running time; this is the floor, and the whole rule for formats whose length '
            . 'cannot be read (e.g. WebM).',
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
