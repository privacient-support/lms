<?php
defined('MOODLE_INTERNAL') || die();

$capabilities = [
    // Import, edit and remove Content Hub material.
    'local/privacient:managecontent' => [
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => ['manager' => CAP_ALLOW],
    ],
    // Configure a company's learner SSO (SAML). Separate from content rights:
    // it changes who can sign in, which is a different kind of power from
    // deciding what they watch.
    'local/privacient:managesaml' => [
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => ['manager' => CAP_ALLOW],
    ],
    // Browse and download it.
    'local/privacient:viewcontent' => [
        'captype' => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => ['manager' => CAP_ALLOW, 'user' => CAP_ALLOW],
    ],
];
