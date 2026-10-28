<?php
// This file is part of the mod_coursecertificate plugin for Moodle - http://moodle.org/
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
 * Course certificate module upgrade code.
 *
 * @package     mod_coursecertificate
 * @copyright   2020 Mikel Martín <mikel@moodle.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Course certificate module upgrade code.
 *
 * @param int $oldversion the version we are upgrading from
 * @return bool always true
 */
function xmldb_coursecertificate_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2020061603) {

        // Changing the default of field template on table coursecertificate to 0.
        $table = new xmldb_table('coursecertificate');
        $field = new xmldb_field('template', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'introformat');

        // Launch change of default for field template.
        $dbman->change_field_default($table, $field);

        // Coursecertificate savepoint reached.
        upgrade_mod_savepoint(true, 2020061603, 'coursecertificate');
    }

    if ($oldversion < 2025031812) {
        // Trema-style multi-company group selector, active template alignment, and group-scoped ZIP download.
        upgrade_mod_savepoint(true, 2025031812, 'coursecertificate');
    }

    return true;
}
