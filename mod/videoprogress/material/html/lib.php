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
 * @package   videoprogressmaterial_html
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Serves protected files embedded in HTML support materials.
 *
 * @param mixed $course Course record.
 * @param mixed $cm Course module record.
 * @param mixed $context Module context.
 * @param string $filearea Requested File API area.
 * @param array $args Remaining pluginfile path arguments.
 * @param bool $forcedownload Whether Moodle requested a forced download.
 * @param array $options Additional file-serving options.
 * @return bool Whether Moodle should accept the result.
 * @throws coding_exception
 * @throws dml_exception
 * @throws moodle_exception
 * @throws require_login_exception
 * @throws required_capability_exception
 */
function videoprogressmaterial_html_pluginfile($course, $cm, $context, string $filearea, array $args,
                                               bool $forcedownload, array $options = []): bool {
    global $DB;
    if ($context->contextlevel !== CONTEXT_MODULE || $filearea !== "content") {
        return false;
    }
    require_login($course, true, $cm);
    require_capability('mod/videoprogress:view', $context);
    $materialid = (int)array_shift($args);
    if (!$DB->record_exists("videoprogress_materials", [
        "id" => $materialid, "videoprogressid" => $cm->instance, "plugin" => "html", "enabled" => 1])) {
        return false;
    }
    $filename = array_pop($args);
    $filepath = '/' . ($args ? implode('/', $args) . '/' : '');
    $file = get_file_storage()->get_file(
        $context->id, "videoprogressmaterial_html", "content", $materialid, $filepath, $filename);
    if (!$file || $file->is_directory()) {
        return false;
    }
    send_stored_file($file, 0, 0, false, $options);
}
