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
 * @package   videoprogressmaterial_pdf
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Serves a protected PDF and enforces its configured download behavior.
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
function videoprogressmaterial_pdf_pluginfile($course, $cm, $context, string $filearea, array $args,
                                              bool $forcedownload, array $options = []): bool {
    global $DB;
    if ($context->contextlevel !== CONTEXT_MODULE || $filearea !== "document") {
        return false;
    }
    require_login($course, true, $cm);
    require_capability('mod/videoprogress:view', $context);
    $materialid = (int)array_shift($args);
    $material = $DB->get_record("videoprogress_materials", [
        "id" => $materialid,
        "videoprogressid" => $cm->instance,
        "plugin" => "pdf",
        "enabled" => 1,
    ]);
    if (!$material) {
        return false;
    }
    $config = json_decode($material->configdata ?? '', true) ?: [];
    if (empty($config["allowdownload"])) {
        $forcedownload = false;
    }
    $filename = array_pop($args);
    $filepath = '/' . ($args ? implode('/', $args) . '/' : '');
    $file = get_file_storage()->get_file(
        $context->id, "videoprogressmaterial_pdf", "document", $materialid, $filepath, $filename);
    if (!$file || $file->is_directory()) {
        return false;
    }
    send_stored_file($file, 0, 0, $forcedownload, $options);
}
