<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Contains function with the definition of upgrade steps for the plugin.
 *
 * @package   mod_adele
 * @copyright 2024 Wunderbyte GmbH <info@wunderbyte.at>
 * @copyright 2026 Ralf Erlebach
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Defines upgrade steps for the plugin.
 *
 * @param mixed $oldversion
 * @return bool True on success.
 */
function xmldb_adele_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();
    if ($oldversion < 2024022100) {
        // Changing type of field participantslist on table adele to char.
        $table = new xmldb_table('adele');
        $field = new xmldb_field('participantslist', XMLDB_TYPE_CHAR, '256', null, XMLDB_NOTNULL, null, '0', 'userlist');

        // Launch change of type for field participantslist.
        $dbman->change_field_type($table, $field);

        // Adele savepoint reached.
        upgrade_mod_savepoint(true, 2024022100, 'adele');
    }

    if ($oldversion < 2025030400) {
        $table = new xmldb_table('adele');
        $field = new xmldb_field(
            'completionlearningpathfinished',
            XMLDB_TYPE_INTEGER,
            '1',
            null,
            XMLDB_NOTNULL,
            null,
            '0',
            'introformat'
        );

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2025030400, 'adele');
    }

    if ($oldversion < 2026072301) {
        // Adds hostenrolmentmode so teachers can scale back what
        // participantslist options 2/3 grant in the host course (visible /
        // hidden / no enrolment) instead of always enrolling actively.
        // Default 'visible' preserves existing behaviour for activities that
        // predate this setting.
        $table = new xmldb_table('adele');
        $field = new xmldb_field(
            'hostenrolmentmode',
            XMLDB_TYPE_CHAR,
            '10',
            null,
            XMLDB_NOTNULL,
            null,
            'visible',
            'completionlearningpathfinished'
        );

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026072301, 'adele');
    }

    if ($oldversion < 2026100730) {
        // How teachers reach the node courses they cannot open yet (issue
        // local_adele #600). Every existing activity starts with the least
        // privileged mode, existing_only - no migration grants more.
        $table = new xmldb_table('adele');
        $field = new xmldb_field(
            'teachercourseaccessmode',
            XMLDB_TYPE_CHAR,
            '32',
            null,
            XMLDB_NOTNULL,
            null,
            'existing_only',
            'hostenrolmentmode'
        );

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026100730, 'adele');
    }

    if ($oldversion < 2026100732) {
        // Who chose a teacher enrolment mode with the authority for it (local_adele
        // #600, TP4). Nobody yet: no activity has such a mode before this version.
        $table = new xmldb_table('adele');
        $field = new xmldb_field(
            'teachercourseaccessgrantor',
            XMLDB_TYPE_INTEGER,
            '10',
            null,
            XMLDB_NOTNULL,
            null,
            '0',
            'teachercourseaccessmode'
        );
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }
        upgrade_mod_savepoint(true, 2026100732, 'adele');
    }

    return true;
}
