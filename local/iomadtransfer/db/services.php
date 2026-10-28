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
 * External web services for local_iomadtransfer.
 *
 * @package    local_iomadtransfer
 * @copyright  2026 IOMAD Multi-Tenant Consolidation Engine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_iomadtransfer\external\backend_external;

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_iomadtransfer_ping' => [
        'classname' => backend_external::class,
        'methodname' => 'ping',
        'description' => 'Health check and site capabilities verification between paired platforms',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'local_iomadtransfer_origin_get_courses' => [
        'classname' => backend_external::class,
        'methodname' => 'origin_get_courses',
        'description' => 'List all available courses on the origin Moodle with enrolled student count and estimated size',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'local_iomadtransfer_origin_get_course_templates' => [
        'classname' => backend_external::class,
        'methodname' => 'origin_get_course_templates',
        'description' => 'Export Moodle Workplace tool_certificate templates (pages, elements, and Base64 files) for a course',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'local_iomadtransfer_origin_backup_course' => [
        'classname' => backend_external::class,
        'methodname' => 'origin_backup_course',
        'description' => 'Trigger an asynchronous full course backup including user data, grades, and quiz attempts (excluding groups)',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'local_iomadtransfer_target_backup_completed' => [
        'classname' => backend_external::class,
        'methodname' => 'target_backup_completed',
        'description' => 'Callback invoked by the origin site when the MBZ backup is ready for streaming download',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'local_iomadtransfer_target_backup_error' => [
        'classname' => backend_external::class,
        'methodname' => 'target_backup_error',
        'description' => 'Callback invoked by the origin site if backup generation fails',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
    ],
];

$services = [
    'local_iomadtransfer' => [
        'functions' => [
            'local_iomadtransfer_ping',
            'local_iomadtransfer_origin_get_courses',
            'local_iomadtransfer_origin_get_course_templates',
            'local_iomadtransfer_origin_backup_course',
            'local_iomadtransfer_target_backup_completed',
            'local_iomadtransfer_target_backup_error',
        ],
        'shortname' => 'local_iomadtransfer',
        'downloadfiles' => 1,
        'uploadfiles' => 1,
        'restrictedusers' => 1,
        'enabled' => 1,
    ],
];
