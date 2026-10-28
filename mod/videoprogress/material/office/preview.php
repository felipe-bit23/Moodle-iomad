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
 * Tokenized Office document endpoint used by Google Docs Viewer.
 *
 * Google Docs Viewer cannot access Moodle pluginfile URLs that require a user session. This endpoint exposes only
 * the selected material file through a long random token so Google can retrieve the document for preview.
 *
 * @package videoprogressmaterial_office
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require('../../../../config.php');

$materialid = required_param("materialid", PARAM_INT);
$token = required_param("token", PARAM_ALPHANUMEXT);

$material = $DB->get_record("videoprogress_materials", [
    "id" => $materialid,
    "plugin" => "office",
    "enabled" => 1,
]);

if (!$material) {
    http_response_code(404);
    exit;
}

$cm = get_coursemodule_from_instance("videoprogress", $material->videoprogressid, 0, false, MUST_EXIST);
$course = $DB->get_record("course", ["id" => $cm->course], "*", MUST_EXIST);
$context = context_module::instance($cm->id);

require_login($course, true, $cm);
require_capability('mod/videoprogress:view', $context);

$config = json_decode($material->configdata ?? '', true) ?: [];
$storedtoken = (string)($config["previewtoken"] ?? '');

if ($storedtoken === '' || !hash_equals($storedtoken, $token)) {
    http_response_code(404);
    exit;
}

$material = $DB->get_record("videoprogress_materials", [
    "id" => $materialid,
    "plugin" => "office",
    "enabled" => 1,
]);
if (!$material) {
    http_response_code(404);
    exit;
}

$config = json_decode($material->configdata ?? '', true) ?: [];
$storedtoken = (string)($config["previewtoken"] ?? '');
if ($storedtoken === '' || !hash_equals($storedtoken, $token)) {
    http_response_code(404);
    exit;
}

$cm = get_coursemodule_from_instance("videoprogress", $material->videoprogressid, 0, false, MUST_EXIST);
$context = context_module::instance($cm->id);
$files = get_file_storage()->get_area_files(
    $context->id, "videoprogressmaterial_office", "document", $material->id, "filename", false);
if (!$files) {
    http_response_code(404);
    exit;
}

$file = reset($files);
header('X-Robots-Tag: noindex, nofollow, noarchive');
send_stored_file($file, 0, 0, false, ["dontdie" => false]);
