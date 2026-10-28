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
 * Upgrade steps for local_iomadtransfer.
 *
 * @package    local_iomadtransfer
 * @copyright  2026 IOMAD Multi-Tenant Consolidation Engine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Executes upgrade steps for local_iomadtransfer.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_local_iomadtransfer_upgrade(int $oldversion): bool {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026102302) {
        // 1. Ensure database indexes exist for enterprise query performance.
        $table = new xmldb_table('local_iomadtransfer_request');
        if ($dbman->table_exists($table)) {
            $indexes = [
                new xmldb_index('idx_status_direction', XMLDB_INDEX_NOTUNIQUE, ['status', 'direction']),
                new xmldb_index('idx_target_company', XMLDB_INDEX_NOTUNIQUE, ['target_course_id', 'companyid']),
                new xmldb_index('idx_origin_course', XMLDB_INDEX_NOTUNIQUE, ['origin_course_id']),
                new xmldb_index('idx_userid', XMLDB_INDEX_NOTUNIQUE, ['userid']),
            ];
            foreach ($indexes as $idx) {
                if (!$dbman->index_exists($table, $idx)) {
                    $dbman->add_index($table, $idx);
                }
            }
        }

        $origtable = new xmldb_table('local_iomadtransfer_origin');
        if ($dbman->table_exists($origtable)) {
            $origindexes = [
                new xmldb_index('idx_default_company', XMLDB_INDEX_NOTUNIQUE, ['default_companyid']),
                new xmldb_index('idx_userid', XMLDB_INDEX_NOTUNIQUE, ['userid']),
            ];
            foreach ($origindexes as $idx) {
                if (!$dbman->index_exists($origtable, $idx)) {
                    $dbman->add_index($origtable, $idx);
                }
            }
        }

        $targtable = new xmldb_table('local_iomadtransfer_target');
        if ($dbman->table_exists($targtable)) {
            $idx = new xmldb_index('idx_userid', XMLDB_INDEX_NOTUNIQUE, ['userid']);
            if (!$dbman->index_exists($targtable, $idx)) {
                $dbman->add_index($targtable, $idx);
            }
        }

        // 2. Provision service role/token and self-heal IOMAD course enrolments and groups.
        try {
            \local_iomadtransfer\security_manager::provision_service_and_token(false);
            \local_iomadtransfer\iomad_consolidator::repair_and_sync_all_iomad_courses();
        } catch (\Throwable $e) {
            // Non-fatal during upgrade.
        }

        upgrade_plugin_savepoint(true, 2026102302, 'local', 'iomadtransfer');
    }

    if ($oldversion < 2026102602) {
        upgrade_plugin_savepoint(true, 2026102602, 'local', 'iomadtransfer');
    }

    if ($oldversion < 2026102701) {
        upgrade_plugin_savepoint(true, 2026102701, 'local', 'iomadtransfer');
    }

    if ($oldversion < 2026102702) {
        upgrade_plugin_savepoint(true, 2026102702, 'local', 'iomadtransfer');
    }

    if ($oldversion < 2026102703) {
        upgrade_plugin_savepoint(true, 2026102703, 'local', 'iomadtransfer');
    }

    if ($oldversion < 2026102704) {
        try {
            \local_iomadtransfer\security_manager::provision_service_and_token(false);
        } catch (\Throwable $e) {
            // Non-fatal during upgrade.
        }
        upgrade_plugin_savepoint(true, 2026102704, 'local', 'iomadtransfer');
    }

    if ($oldversion < 2026102705) {
        try {
            \local_iomadtransfer\security_manager::provision_service_and_token(false);
        } catch (\Throwable $e) {
            // Non-fatal during upgrade.
        }
        upgrade_plugin_savepoint(true, 2026102705, 'local', 'iomadtransfer');
    }

    if ($oldversion < 2026102706) {
        upgrade_plugin_savepoint(true, 2026102706, 'local', 'iomadtransfer');
    }

    if ($oldversion < 2026102707) {
        upgrade_plugin_savepoint(true, 2026102707, 'local', 'iomadtransfer');
    }

    if ($oldversion < 2026102708) {
        try {
            \local_iomadtransfer\security_manager::provision_service_and_token(false);
            \local_iomadtransfer\iomad_consolidator::repair_and_sync_all_iomad_courses();
        } catch (\Throwable $e) {
            // Non-fatal during upgrade.
        }
        upgrade_plugin_savepoint(true, 2026102708, 'local', 'iomadtransfer');
    }

    if ($oldversion < 2026102709) {
        upgrade_plugin_savepoint(true, 2026102709, 'local', 'iomadtransfer');
    }

    return true;
}
