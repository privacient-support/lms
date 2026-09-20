<?php
/**
 * Remove only the throwaway companies/courses created by the integration
 * checks (shortnames ws-test-* / e2e-*). Never touches anything else.
 *
 * Run from the lms/ directory:
 *   docker cp scripts/privacient-ws-cleanup.php lms:/tmp/
 *   docker exec lms php /tmp/privacient-ws-cleanup.php
 *
 * The shortname prefixes below are the whole safety mechanism: a real tenant's
 * company is named from its slug and will never match them. Widening a pattern
 * here is how you delete a customer's learners and results.
 */
define('CLI_SCRIPT', true);
require('/var/www/html/config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/course/lib.php');

$companies = $DB->get_records_select(
    'local_iomad_companies',
    "shortname LIKE 'ws-test-%' OR shortname LIKE 'e2e-%' OR shortname LIKE 'wsdel-%'
     OR shortname LIKE 'scope-%' OR shortname LIKE 'id-co%' OR shortname LIKE 'id-global-%'
     OR shortname LIKE 'del-%'",
    null,
    'parentid DESC'   // children before their parents
);
foreach ($companies as $company) {
    foreach ([
        'local_iomad_company_certificates',
        'local_iomad_company_courses',
        'local_iomad_company_created_courses',
        'local_iomad_company_shared_courses',
        'local_iomad_company_departments',
        'local_iomad_company_users',
        'local_iomad_company_domains',
        'local_iomad_company_pages',
    ] as $table) {
        if ($DB->get_manager()->table_exists($table)) {
            $DB->delete_records($table, ['companyid' => $company->id]);
        }
    }
    if (!empty($company->coursecategoryid)) {
        if ($cat = core_course_category::get($company->coursecategoryid, IGNORE_MISSING, true)) {
            $cat->delete_full(false);
        }
    }
    $DB->delete_records('local_iomad_companies', ['id' => $company->id]);
    cli_writeln("removed company {$company->id} {$company->shortname}");
}

$courses = $DB->get_records_select(
    'course',
    "shortname LIKE 'ws-%' OR shortname LIKE 'e2e-%'"
);
foreach ($courses as $course) {
    if ((int) $course->id === 1) {
        continue; // never the site front page
    }
    delete_course($course->id, false);
    cli_writeln("removed course {$course->id} {$course->shortname}");
}
fix_course_sortorder();
cli_writeln('cleanup done');
