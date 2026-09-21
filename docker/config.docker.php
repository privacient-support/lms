<?php
// Copied to /var/www/html/config.php on first container start if config.php is absent.

unset($CFG);
global $CFG;
$CFG = new stdClass();

$CFG->dbtype    = 'mariadb';
$CFG->dblibrary = 'native';
$CFG->dbhost    = getenv('MOODLE_DATABASE_HOST') ?: '';
$CFG->dbname    = getenv('MOODLE_DATABASE_NAME') ?: 'moodle';
$CFG->dbuser    = getenv('MOODLE_DATABASE_USER') ?: 'moodle';
$CFG->dbpass    = getenv('MOODLE_DATABASE_PASSWORD') ?: '';
$CFG->prefix    = 'mdl_';
$CFG->dboptions = [
    'dbpersist' => false,
    'dbport'    => getenv('MOODLE_DATABASE_PORT') ?: '',
    'dbsocket'  => '',
    'dbcollation' => 'utf8mb4_unicode_ci',
];

$CFG->wwwroot   = rtrim(getenv('MOODLE_WWWROOT') ?: 'http://localhost:8080', '/');
$CFG->dataroot  = getenv('MOODLE_DATAROOT') ?: '/var/moodledata';
$CFG->admin     = 'admin';

if (str_starts_with($CFG->wwwroot, 'https://')) {
    $CFG->sslproxy = true;
}

$CFG->directorypermissions = 02777;

require_once(__DIR__ . '/lib/setup.php');
