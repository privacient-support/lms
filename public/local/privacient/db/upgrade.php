<?php
/**
 * Upgrade steps.
 *
 * `db/install.xml` is only read when a plugin is installed for the first time.
 * Anything added later has to be applied here too, or existing installations
 * silently miss it.
 */
defined('MOODLE_INTERNAL') || die();

function xmldb_local_privacient_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026091004) {
        $table = new xmldb_table('local_privacient_content');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('kind', XMLDB_TYPE_CHAR, '32', null, XMLDB_NOTNULL, null, 'other');
        $table->add_field('title', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('description', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('tags', XMLDB_TYPE_CHAR, '500', null, null, null, null);
        $table->add_field('filename', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('mimetype', XMLDB_TYPE_CHAR, '128', null, null, null, null);
        $table->add_field('filesize', XMLDB_TYPE_INTEGER, '20', null, null, null, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('createdby', XMLDB_TYPE_CHAR, '255', null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('kind', XMLDB_INDEX_NOTUNIQUE, ['kind']);
        $table->add_index('timecreated', XMLDB_INDEX_NOTUNIQUE, ['timecreated']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026091004, 'local', 'privacient');
    }

    if ($oldversion < 2026091006) {
        // Content is either global (tenantid 0, shared with every tenant) or
        // owned by exactly one console tenant and invisible to the others.
        $table = new xmldb_table('local_privacient_content');
        $field = new xmldb_field('tenantid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'id');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        $index = new xmldb_index('tenantid', XMLDB_INDEX_NOTUNIQUE, ['tenantid']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }
        upgrade_plugin_savepoint(true, 2026091006, 'local', 'privacient');
    }

    if ($oldversion < 2026091007) {
        // Optional cover image per item. The file lives in the `poster`
        // filearea keyed by the same itemid; this only records its name.
        $table = new xmldb_table('local_privacient_content');
        $field = new xmldb_field('posterfilename', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'filename');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026091007, 'local', 'privacient');
    }

    if ($oldversion < 2026091008) {
        $table = new xmldb_table('local_privacient_content');
        $field = new xmldb_field('cmid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'courseid');
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_plugin_savepoint(true, 2026091008, 'local', 'privacient');
    }

    return true;
}
