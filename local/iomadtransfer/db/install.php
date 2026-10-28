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
 * Post-installation hook for local_iomadtransfer.
 *
 * @package    local_iomadtransfer
 * @copyright  2026 IOMAD Multi-Tenant Consolidation Engine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Provisions the web service token and synchronizes existing IOMAD course groups and enrolments on install.
 *
 * @return bool
 */
function xmldb_local_iomadtransfer_install(): bool {
    try {
        \local_iomadtransfer\security_manager::provision_service_and_token(false);
        \local_iomadtransfer\iomad_consolidator::repair_and_sync_all_iomad_courses();
    } catch (\Throwable $e) {
        // Non-fatal during install.
    }
    return true;
}
