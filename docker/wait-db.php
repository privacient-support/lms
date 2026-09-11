<?php
$h = getenv('MOODLE_DATABASE_HOST');
if ($h === false || $h === '') {
    fwrite(STDERR, "MOODLE_DATABASE_HOST must be set.\n");
    exit(1);
}
$p = (int)(getenv('MOODLE_DATABASE_PORT') ?: '3306');
$u = getenv('MOODLE_DATABASE_USER') ?: 'moodle';
$w = getenv('MOODLE_DATABASE_PASSWORD') ?: '';
$d = getenv('MOODLE_DATABASE_NAME') ?: 'moodle';
$attempts = (int)(getenv('DB_WAIT_ATTEMPTS') ?: '90');
$sleep = (int)(getenv('DB_WAIT_SLEEP') ?: '2');

mysqli_report(MYSQLI_REPORT_OFF);

for ($i = 0; $i < $attempts; $i++) {
    try {
        $m = new mysqli($h, $u, $w, $d, $p);
        if (!$m->connect_error) {
            $m->close();
            fwrite(STDOUT, "Database is reachable.\n");
            exit(0);
        }
    } catch (mysqli_sql_exception $e) {
    }
    sleep($sleep);
}

fwrite(STDERR, "Timed out waiting for database at {$h}:{$p}\n");
exit(1);
