<?php
/**
 * Serve Content Hub files.
 *
 * Reached through `webservice/pluginfile.php` with a web-service token, which
 * is how the console streams a file without ever copying it out of Moodle.
 * The console proxies this server-side so the token never reaches a browser.
 */
defined('MOODLE_INTERNAL') || die();

function local_privacient_pluginfile(
    $course,
    $cm,
    $context,
    $filearea,
    array $args,
    $forcedownload,
    array $options = []
) {
    // `content` is the material itself; `poster` its optional cover image.
    if ($context->contextlevel !== CONTEXT_SYSTEM
        || !in_array($filearea, ['content', 'poster'], true)) {
        return false;
    }
    require_capability('local/privacient:viewcontent', $context);

    $itemid = (int) array_shift($args);
    $filename = array_pop($args);
    $filepath = $args ? '/' . implode('/', $args) . '/' : '/';

    $fs = get_file_storage();
    $file = $fs->get_file(
        $context->id,
        'local_privacient',
        $filearea,
        $itemid,
        $filepath,
        $filename
    );
    if (!$file || $file->is_directory()) {
        return false;
    }
    // Short-lived cache: the file is immutable once stored, but access is
    // per-user so it must not be cached by shared proxies.
    send_stored_file($file, 60, 0, $forcedownload, $options);
}
