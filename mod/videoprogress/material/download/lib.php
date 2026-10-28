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
 * File serving callbacks for downloadable support materials.
 *
 * @package videoprogressmaterial_download
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Serves files belonging to an enabled download material.
 */
function videoprogressmaterial_download_pluginfile($course, $cm, $context, string $filearea, array $args,
                                                   bool $forcedownload, array $options = []): bool {
    global $DB;

    if ($context->contextlevel !== CONTEXT_MODULE || $filearea !== "files") {
        return false;
    }

    require_login($course, true, $cm);
    require_capability('mod/videoprogress:view', $context);

    $materialid = (int)array_shift($args);
    $material = $DB->get_record("videoprogress_materials", [
        "id" => $materialid,
        "videoprogressid" => $cm->instance,
        "plugin" => "download",
        "enabled" => 1,
    ]);
    if (!$material) {
        return false;
    }

    $filename = array_pop($args);
    $filepath = '/' . ($args ? implode('/', $args) . '/' : '');
    $file = get_file_storage()->get_file(
        $context->id, "videoprogressmaterial_download", "files", $materialid, $filepath, $filename);
    if (!$file || $file->is_directory()) {
        return false;
    }

    send_stored_file($file, 0, 0, true, $options);
}
