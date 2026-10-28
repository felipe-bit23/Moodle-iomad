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
 * English language strings for local_iomadtransfer.
 *
 * @package    local_iomadtransfer
 * @copyright  2026 IOMAD Multi-Tenant Consolidation Engine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'IOMAD Course Transfer & Consolidator';
$string['nav_summary'] = 'Summary & Service Token';
$string['nav_sites'] = 'Paired Moodle Platforms';
$string['nav_consolidate'] = 'Consolidate & Transfer Courses';
$string['nav_logs'] = 'Execution Logs & Progress';
$string['nav_settings'] = 'Plugin Settings';

$string['iomadtransfer:manage'] = 'Manage IOMAD Course Transfer platforms and tokens';
$string['iomadtransfer:restore'] = 'Restore remote courses';
$string['iomadtransfer:consolidate_iomad'] = 'Consolidate remote courses, users, grades and attempts into IOMAD';
$string['iomadtransfer:view_logs'] = 'View transfer execution logs';

$string['settings_general_heading'] = 'General Transfer & Security Configuration';
$string['settings_general_desc'] = 'Configure timeouts, backup limits, and user identity matching between origin Moodles and IOMAD.';
$string['setting_max_backup_size_mb'] = 'Maximum backup size (MB)';
$string['setting_max_backup_size_mb_desc'] = 'Maximum size in MB allowed for an incoming .mbz backup file.';
$string['setting_request_timeout'] = 'Web Service timeout (seconds)';
$string['setting_request_timeout_desc'] = 'HTTP timeout in seconds for REST calls between paired Moodle servers.';
$string['setting_ignorecurlsecurity'] = 'Bypass cURL private IP blocking';
$string['setting_ignorecurlsecurity_desc'] = 'Allow cURL requests to internal or same-server Moodle hosts.';
$string['setting_user_match_field'] = 'User matching field';
$string['setting_user_match_field_desc'] = 'Field used to match or deduplicate users across platforms (email recommended).';

$string['status_error'] = 'Error';
$string['status_not_started'] = 'Queued';
$string['status_in_progress'] = 'Requested';
$string['status_backup'] = 'Creating Backup';
$string['status_download'] = 'Downloading MBZ';
$string['status_downloaded'] = 'Downloaded';
$string['status_restore'] = 'Restoring Course';
$string['status_consolidating'] = 'Consolidating IOMAD Data';
$string['status_completed'] = 'Completed';

$string['mode_consolidate_iomad'] = 'Consolidate into Existing IOMAD Master Course (Users, Grades, Completion & Quiz Attempts without duplicating activities or deleting IOMAD groups)';
$string['mode_new_course'] = 'Create as a New Course in Target Category (assigning to IOMAD Company if selected)';
$string['mode_replace_course'] = 'Replace Existing Course Content (preserving IOMAD groups)';
$string['mode_merge_course'] = 'Standard Moodle Merge into Existing Course';

$string['cachedef_remote_courses'] = 'Cache of remote courses retrieved from paired Origin Moodle platforms.';

$string['privacy:metadata:local_iomadtransfer_request'] = 'Information about course transfer and IOMAD consolidation requests.';
$string['privacy:metadata:local_iomadtransfer_request:userid'] = 'The ID of the user who launched the transfer request.';
$string['privacy:metadata:local_iomadtransfer_request:siteurl'] = 'The URL of the paired Moodle platform.';
$string['privacy:metadata:local_iomadtransfer_request:origin_course_id'] = 'The origin course ID.';
$string['privacy:metadata:local_iomadtransfer_request:target_course_id'] = 'The target course ID.';
$string['privacy:metadata:local_iomadtransfer_request:companyid'] = 'The target IOMAD company ID.';
$string['privacy:metadata:local_iomadtransfer_request:status'] = 'The current state of the transfer.';
$string['privacy:metadata:local_iomadtransfer_request:timecreated'] = 'Timestamp when the request was created.';

$string['privacy:metadata:local_iomadtransfer_origin'] = 'Paired Origin Moodle platforms registered by administrators.';
$string['privacy:metadata:local_iomadtransfer_origin:userid'] = 'The ID of the administrator who registered the origin site.';
$string['privacy:metadata:local_iomadtransfer_origin:host'] = 'The remote Moodle host URL.';
$string['privacy:metadata:local_iomadtransfer_origin:timecreated'] = 'Timestamp when the origin site was registered.';

$string['privacy:metadata:local_iomadtransfer_target'] = 'Authorized Target Moodle/IOMAD platforms registered by administrators.';
$string['privacy:metadata:local_iomadtransfer_target:userid'] = 'The ID of the administrator who registered the target site.';
$string['privacy:metadata:local_iomadtransfer_target:host'] = 'The remote Moodle host URL.';
$string['privacy:metadata:local_iomadtransfer_target:timecreated'] = 'Timestamp when the target site was registered.';

$string['privacy:metadata:remote_moodle'] = 'Course backups and enrolled student records exchanged with paired Moodle platforms.';
$string['privacy:metadata:remote_moodle:userid'] = 'The user ID initiating or included in the course transfer.';
$string['privacy:metadata:remote_moodle:courseid'] = 'The course ID being transferred or consolidated.';
