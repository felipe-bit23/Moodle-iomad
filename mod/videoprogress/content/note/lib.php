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
 * lib.php
 *
 * @package   videoprogresscontent_note
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Serves protected embedded files used by synchronized explanatory notes.
 *
 * @param mixed $course Course record.
 * @param mixed $cm Course module record.
 * @param mixed $context Module context.
 * @param string $filearea Requested File API area.
 * @param array $args Remaining pluginfile path arguments.
 * @param bool $forcedownload Whether Moodle requested a forced download.
 * @param array $options Additional file-serving options.
 * @return bool Whether Moodle should accept the result.
 */
function videoprogresscontent_note_pluginfile($course, $cm, $context, string $filearea, array $args,
                                              bool $forcedownload, array $options = []): bool {
    global $DB;
    if ($context->contextlevel !== CONTEXT_MODULE || $filearea !== "message") {
        return false;
    }
    require_login($course, true, $cm);
    require_capability('mod/videoprogress:view', $context);
    $itemid = (int)array_shift($args);
    $sql = "SELECT i.id
              FROM {videoprogress_pointitems} i
              JOIN {videoprogress_points} p ON p.id = i.pointid
             WHERE i.id = :itemid AND i.plugin = :plugin AND i.enabled = 1
                   AND p.videoprogressid = :activityid AND p.enabled = 1";
    if (!$DB->record_exists_sql($sql, ["itemid" => $itemid, "plugin" => "note", "activityid" => $cm->instance])) {
        return false;
    }
    $filename = array_pop($args);
    $filepath = '/' . ($args ? implode('/', $args) . '/' : '');
    $file = get_file_storage()->get_file(
        $context->id, "videoprogresscontent_note", "message", $itemid, $filepath, $filename);
    if (!$file || $file->is_directory()) {
        return false;
    }
    send_stored_file($file, 0, 0, false, $options);
}
