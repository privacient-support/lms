<?php
/**
 * Provision the Privacient web-service account for IOMAD.
 *
 * Creates a dedicated system role holding only the capabilities the console's
 * allowlisted functions require, a service account carrying that role, an
 * external service listing exactly those functions, and a permanent token.
 *
 * Deliberately NOT the admin token: the token is site-wide, so its blast radius
 * should be the capability set below and nothing more.
 *
 * Run from the lms/ directory:
 *   docker cp scripts/privacient-ws-setup.php lms:/tmp/
 *   docker exec lms php /tmp/privacient-ws-setup.php
 *
 * Idempotent — safe to re-run; reuses whatever already exists, and only ever
 * ADDS missing functions to the service. Re-run it after adding a web-service
 * function to the plugin, otherwise the function exists but the token cannot
 * reach it and every call returns a bare "Access control exception".
 *
 * Prints IOMAD_API_URL / IOMAD_API_TOKEN at the end — the two values
 * privacient-frontend/.env.local needs.
 */

define('CLI_SCRIPT', true);
require('/var/www/html/config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/accesslib.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->libdir . '/externallib.php');

$syscontext = context_system::instance();

// 1. Protocol -----------------------------------------------------------
set_config('enablewebservices', 1);
$protocols = get_config('core', 'webserviceprotocols');
$enabled = $protocols ? explode(',', $protocols) : [];
if (!in_array('rest', $enabled, true)) {
    $enabled[] = 'rest';
    set_config('webserviceprotocols', implode(',', array_filter($enabled)));
}
cli_writeln('web services: enabled, protocols=' . get_config('core', 'webserviceprotocols'));

// 2. Role ---------------------------------------------------------------
$capabilities = [
    'webservice/rest:use',
    'block/iomad_company_admin:company_add',
    'block/iomad_company_admin:managecourses',
    'block/iomad_company_admin:viewcourses',
    'block/iomad_company_admin:suspendcompanies',
    'block/iomad_company_admin:company_delete',
    'local/privacient:managecontent',
    'local/privacient:managesaml',
    'local/privacient:viewcontent',
    // Lets the service account upload through webservice/upload.php.
    'moodle/user:manageownfiles',
    'moodle/course:create',
    'moodle/course:delete',
    'moodle/course:manageactivities',
    'moodle/course:activityvisibility',
    'moodle/site:manageblocks',
    'moodle/user:create',
    'moodle/user:update',
    'moodle/role:assign',
    'enrol/manual:enrol',
    'moodle/course:view',
    'moodle/course:update',
    'moodle/course:viewhiddencourses',
    'moodle/course:visibility',
    'moodle/category:manage',
];

$roleshort = 'privacientws';
$role = $DB->get_record('role', ['shortname' => $roleshort]);
if (!$role) {
    $roleid = create_role(
        'Privacient web service',
        $roleshort,
        'Least-privilege account for the Privacient console web-service calls.'
    );
    $role = $DB->get_record('role', ['id' => $roleid], '*', MUST_EXIST);
    cli_writeln("role: created {$roleshort} (id {$role->id})");
} else {
    cli_writeln("role: reusing {$roleshort} (id {$role->id})");
}
set_role_contextlevels($role->id, [CONTEXT_SYSTEM]);
foreach ($capabilities as $cap) {
    if (!get_capability_info($cap)) {
        cli_writeln("  ! capability not installed, skipped: {$cap}");
        continue;
    }
    assign_capability($cap, CAP_ALLOW, $role->id, $syscontext->id, true);
}
cli_writeln('role: capabilities assigned (' . count($capabilities) . ' requested)');

// 3. Service account ----------------------------------------------------
$username = 'privacient_ws';
$user = $DB->get_record('user', ['username' => $username, 'mnethostid' => $CFG->mnet_localhost_id]);
if (!$user) {
    $new = new stdClass();
    $new->username = $username;
    $new->firstname = 'Privacient';
    $new->lastname = 'Web Service';
    $new->email = 'privacient-ws@localhost.invalid';
    $new->auth = 'webservice';   // cannot log in interactively
    $new->confirmed = 1;
    $new->mnethostid = $CFG->mnet_localhost_id;
    $new->password = hash_internal_user_password(random_string(32));
    $userid = user_create_user($new, false, false);
    $user = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);
    cli_writeln("user: created {$username} (id {$user->id})");
} else {
    cli_writeln("user: reusing {$username} (id {$user->id})");
}
role_assign($role->id, $user->id, $syscontext->id);

// 4. External service ---------------------------------------------------
$functions = [
    'block_iomad_company_admin_create_companies',
    'block_iomad_company_admin_edit_companies',
    'block_iomad_company_admin_get_companies',
    'block_iomad_company_admin_suspend_company',
    'block_iomad_company_admin_get_company_courses',
    'block_iomad_company_admin_assign_courses',
    'block_iomad_company_admin_unassign_courses',
    'core_course_create_courses',
    'core_course_get_courses',
    'core_course_delete_courses',
    // From the local_privacient plugin. It lives at public/local/privacient
    // in this repo and is upgraded in place — anything not yet installed is
    // reported and skipped rather than failing the run.
    //
    // This list must stay in step with the plugin's own db/services.php:
    // declaring a function there does NOT expose it on this service.
    'local_privacient_delete_company',
    'local_privacient_add_content',
    'local_privacient_list_content',
    'local_privacient_delete_content',
    'local_privacient_update_content',
    'local_privacient_set_poster',
    'local_privacient_publish_content',
    'local_privacient_publish_questionnaire',
    'local_privacient_enrol_learner',
    'local_privacient_ensure_learner',
    'local_privacient_delete_learner',
    'local_privacient_get_saml_config',
    'local_privacient_set_saml_config',
];

// IP restriction. The console calls from a known backend, so the token's blast
// radius shrinks further if this service only answers from that host. Left
// empty by default so nobody is locked out on first setup; set PRIVACIENT_WS_IP
// (a comma/space-separated list of IPs or CIDR ranges, e.g. "203.0.113.4" or
// "10.0.0.0/24") in the environment to pin it. This only sets the allowlist; it
// never rotates or prints the token.
$iprestriction = trim((string) getenv('PRIVACIENT_WS_IP'));

$shortname = 'privacient_training';
$service = $DB->get_record('external_services', ['shortname' => $shortname]);
if (!$service) {
    $service = new stdClass();
    $service->name = 'Privacient Training';
    $service->shortname = $shortname;
    $service->enabled = 1;
    $service->restrictedusers = 1;   // only explicitly authorised users
    $service->downloadfiles = 1;
    $service->uploadfiles = 1;
    $service->iprestriction = $iprestriction;
    $service->timecreated = time();
    $service->id = $DB->insert_record('external_services', $service);
    cli_writeln("service: created {$shortname} (id {$service->id})");
} else {
    $service->enabled = 1;
    $service->restrictedusers = 1;
    $service->downloadfiles = 1;
    $service->uploadfiles = 1;
    // Only touch the allowlist when one was provided, so re-running without the
    // env var does not silently clear an allowlist set out of band.
    if ($iprestriction !== '') {
        $service->iprestriction = $iprestriction;
    }
    $DB->update_record('external_services', $service);
    cli_writeln("service: reusing {$shortname} (id {$service->id})");
}
if ($iprestriction !== '') {
    cli_writeln("service: IP restriction set to {$iprestriction}");
}

$added = 0;
foreach ($functions as $fn) {
    if (!$DB->record_exists('external_functions', ['name' => $fn])) {
        cli_writeln("  ! function not installed, skipped: {$fn}");
        continue;
    }
    if (!$DB->record_exists('external_services_functions', [
        'externalserviceid' => $service->id, 'functionname' => $fn,
    ])) {
        $DB->insert_record('external_services_functions', (object) [
            'externalserviceid' => $service->id,
            'functionname' => $fn,
        ]);
        $added++;
    }
}
cli_writeln("service: {$added} function(s) added");

if (!$DB->record_exists('external_services_users', [
    'externalserviceid' => $service->id, 'userid' => $user->id,
])) {
    $DB->insert_record('external_services_users', (object) [
        'externalserviceid' => $service->id,
        'userid' => $user->id,
        'timecreated' => time(),
    ]);
    cli_writeln('service: authorised user added');
}

// 5. Token --------------------------------------------------------------
$existing = $DB->get_record('external_tokens', [
    'externalserviceid' => $service->id,
    'userid' => $user->id,
    'tokentype' => EXTERNAL_TOKEN_PERMANENT,
]);
if ($existing) {
    $token = $existing->token;
    cli_writeln('token: reusing existing');
} else {
    $token = \core_external\util::generate_token(
        EXTERNAL_TOKEN_PERMANENT,
        $service,
        $user->id,
        $syscontext,
        0,
        '',
        'Privacient console'
    );
    cli_writeln('token: created');
}

cli_writeln('');
cli_writeln('IOMAD_API_URL=' . $CFG->wwwroot);
cli_writeln('IOMAD_API_TOKEN=' . $token);
