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
 * Core library callbacks and secure backup file serving for local_iomadtransfer.
 *
 * @package    local_iomadtransfer
 * @copyright  2026 IOMAD Multi-Tenant Consolidation Engine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Extends course navigation with a direct shortcut to IOMAD Course Consolidation.
 *
 * @param navigation_node $navigation
 * @param stdClass $course
 * @param context_course $context
 */
function local_iomadtransfer_extend_navigation_course(navigation_node $navigation, stdClass $course, context_course $context): void {
    if (has_capability('local/iomadtransfer:consolidate_iomad', context_system::instance())) {
        $label = get_string('nav_consolidate', 'local_iomadtransfer');
        $url = new moodle_url('/local/iomadtransfer/consolidate.php', ['target_course_id' => $course->id]);
        $icon = new pix_icon('t/restore', $label);
        $navigation->add($label, $url, navigation_node::TYPE_SETTING, null, 'local_iomadtransfer_consolidate', $icon);
    }
}

/**
 * Serves the generated .mbz backup file over Moodle's webservice/pluginfile.php endpoint
 * to the authenticated target IOMAD server.
 *
 * @param stdClass $course
 * @param stdClass $cm
 * @param context $context
 * @param string $filearea
 * @param array $args
 * @param bool $forcedownload
 * @param array $options
 * @return bool
 */
function local_iomadtransfer_pluginfile(
    $course,
    $cm,
    $context,
    string $filearea,
    array $args,
    bool $forcedownload,
    array $options = []
): bool {
    if ($filearea !== 'backup') {
        return false;
    }

    require_login($course, false);

    if (!is_siteadmin() && !has_capability('moodle/backup:backupcourse', $context)) {
        return false;
    }

    $itemid = (int)array_shift($args);
    $filename = array_pop($args);
    $filepath = empty($args) ? '/' : '/' . implode('/', $args) . '/';

    $fs = get_file_storage();
    $file = $fs->get_file($context->id, 'local_iomadtransfer', $filearea, $itemid, $filepath, $filename);
    if (!$file || $file->is_directory()) {
        return false;
    }

    \core_php_time_limit::raise();
    send_stored_file($file, 0, 0, $forcedownload, $options);
    return true;
}
