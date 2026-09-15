<?php
defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_privacient_delete_company' => [
        'classname'   => 'local_privacient\external\delete_company',
        'description' => 'Delete an IOMAD company and everything belonging to it.',
        'type'        => 'write',
        'ajax'        => false,
        'capabilities' => 'block/iomad_company_admin:company_delete',
    ],
    'local_privacient_add_content' => [
        'classname'   => 'local_privacient\\external\\add_content',
        'description' => 'Promote an uploaded draft file into the Content Hub.',
        'type'        => 'write',
        'ajax'        => false,
        'capabilities' => 'local/privacient:managecontent',
    ],
    'local_privacient_update_content' => [
        'classname'   => 'local_privacient\\external\\update_content',
        'description' => 'Edit a Content Hub item\'s title, description, tags and kind.',
        'type'        => 'write',
        'ajax'        => false,
        'capabilities' => 'local/privacient:managecontent',
    ],
    'local_privacient_list_content' => [
        'classname'   => 'local_privacient\\external\\list_content',
        'description' => 'List Content Hub items.',
        'type'        => 'read',
        'ajax'        => false,
        'capabilities' => 'local/privacient:viewcontent',
    ],
    'local_privacient_publish_content' => [
        'classname'   => 'local_privacient\\external\\publish_content',
        'description' => 'Publish a Content Hub item as a playable Moodle activity.',
        'type'        => 'write',
        'ajax'        => false,
        'capabilities' => 'local/privacient:managecontent',
    ],
    'local_privacient_publish_questionnaire' => [
        'classname'   => 'local_privacient\\external\\publish_questionnaire',
        'description' => 'Generate a Moodle quiz from a questionnaire definition.',
        'type'        => 'write',
        'ajax'        => false,
        'capabilities' => 'local/privacient:managecontent',
    ],
    'local_privacient_enrol_learner' => [
        'classname'   => 'local_privacient\\external\\enrol_learner',
        'description' => 'Ensure a learner exists, enrol them, and mint a launch key.',
        'type'        => 'write',
        'ajax'        => false,
        'capabilities' => 'local/privacient:managecontent',
    ],
    'local_privacient_ensure_learner' => [
        'classname'   => 'local_privacient\\external\\ensure_learner',
        'description' => 'Create a Moodle user for a console team member and attach them to the IOMAD company.',
        'type'        => 'write',
        'ajax'        => false,
        'capabilities' => 'local/privacient:managecontent',
    ],
    'local_privacient_set_poster' => [
        'classname'   => 'local_privacient\\external\\set_poster',
        'description' => 'Attach or remove a Content Hub cover image.',
        'type'        => 'write',
        'ajax'        => false,
        'capabilities' => 'local/privacient:managecontent',
    ],
    'local_privacient_get_saml_config' => [
        'classname'   => 'local_privacient\\external\\get_saml_config',
        'description' => "Read a company's learner SAML configuration.",
        'type'        => 'read',
        'ajax'        => false,
        'capabilities' => 'local/privacient:managesaml',
    ],
    'local_privacient_set_saml_config' => [
        'classname'   => 'local_privacient\\external\\set_saml_config',
        'description' => "Configure a company's learner SAML identity provider.",
        'type'        => 'write',
        'ajax'        => false,
        'capabilities' => 'local/privacient:managesaml',
    ],
    'local_privacient_delete_content' => [
        'classname'   => 'local_privacient\\external\\delete_content',
        'description' => 'Delete a Content Hub item.',
        'type'        => 'write',
        'ajax'        => false,
        'capabilities' => 'local/privacient:managecontent',
    ],
];
