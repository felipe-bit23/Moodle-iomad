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
 * Download support material implementation.
 *
 * @package videoprogressmaterial_download
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace videoprogressmaterial_download;

use coding_exception;
use context_module;
use mod_videoprogress\material\plugin_base;
use moodle_url;
use MoodleQuickForm;
use stdClass;

/**
 * Provides one or more files as downloadable support material.
 */
class plugin extends plugin_base {
    /**
     * get_name
     *
     * @return string
     * @throws coding_exception
     */
    public function get_name(): string {
        return get_string("pluginname", "videoprogressmaterial_download");
    }

    /**
     * add_form_elements
     *
     * @param MoodleQuickForm $mform
     * @return void
     * @throws coding_exception
     */
    public function add_form_elements(MoodleQuickForm $mform): void {
        $mform->addElement("filemanager", "downloadfiles", get_string("files", "videoprogressmaterial_download"), null, [
            "subdirs" => 0,
            "maxfiles" => 20,
            "accepted_types" => '*',
        ]);
        $mform->addHelpButton("downloadfiles", "files", "videoprogressmaterial_download");
    }

    /**
     * prepare_form_data
     *
     * @param stdClass $data
     * @param stdClass|null $material
     * @param context_module $context
     * @return stdClass
     */
    public function prepare_form_data(stdClass $data, stdClass|null $material, context_module $context): stdClass {
        $draftid = file_get_submitted_draft_itemid("downloadfiles");
        file_prepare_draft_area($draftid, $context->id, "videoprogressmaterial_download", "files",
            $material->id ?? 0, ["subdirs" => 0, "maxfiles" => 20, "accepted_types" => '*']);
        $data->downloadfiles = $draftid;
        return $data;
    }

    /**
     * validation
     *
     * @param array $data
     * @param array $files
     * @param stdClass|null $material
     * @param context_module $context
     * @return array
     * @throws coding_exception
     */
    public function validation(array $data, array $files, stdClass|null $material, context_module $context): array {
        $draftid = (int)($data["downloadfiles"] ?? 0);
        if ($draftid && !empty(file_get_draft_area_info($draftid)["filecount"])) {
            return [];
        }
        if ($material && get_file_storage()->get_area_files(
                $context->id, "videoprogressmaterial_download", "files", $material->id, "id", false)) {
            return [];
        }
        return ["downloadfiles" => get_string("required")];
    }

    /**
     * save
     *
     * @param stdClass $material
     * @param stdClass $data
     * @param context_module $context
     * @return array
     * @throws coding_exception
     */
    public function save(stdClass $material, stdClass $data, context_module $context): array {
        file_save_draft_area_files($data->downloadfiles, $context->id, "videoprogressmaterial_download", "files",
            $material->id, ["subdirs" => 0, "maxfiles" => 20, "accepted_types" => '*']);
        return [];
    }

    /**
     * delete
     *
     * @param stdClass $material
     * @param context_module $context
     * @return void
     */
    public function delete(stdClass $material, context_module $context): void {
        get_file_storage()->delete_area_files($context->id, "videoprogressmaterial_download", "files", $material->id);
    }

    /**
     * render_card
     *
     * @param stdClass $material
     * @param context_module $context
     * @param int $cmid
     * @return string
     * @throws \core\exception\moodle_exception
     */
    public function render_card(stdClass $material, context_module $context, int $cmid): string {
        global $OUTPUT;
        return $OUTPUT->render_from_template('videoprogressmaterial_download/card', [
            "name" => format_string($material->name),
            "viewurl" => (new moodle_url('/mod/videoprogress/material/view.php', [
                "id" => $cmid,
                "materialid" => $material->id,
            ]))->out(false),
        ]);
    }

    /**
     * render_full
     *
     * @param stdClass $material
     * @param context_module $context
     * @param int $cmid
     * @return string
     * @throws coding_exception
     */
    public function render_full(stdClass $material, context_module $context, int $cmid): string {
        global $OUTPUT;

        $items = [];
        $files = get_file_storage()->get_area_files(
            $context->id, "videoprogressmaterial_download", "files", $material->id, "filename", false);
        foreach ($files as $file) {
            $items[] = [
                "filename" => $file->get_filename(),
                "filesize" => display_size($file->get_filesize()),
                "downloadurl" => moodle_url::make_pluginfile_url(
                    $context->id,
                    "videoprogressmaterial_download",
                    "files",
                    $material->id,
                    $file->get_filepath(),
                    $file->get_filename(),
                    true
                )->out(false),
            ];
        }

        return $OUTPUT->render_from_template('videoprogressmaterial_download/view', [
            "name" => format_string($material->name),
            "hasfiles" => !empty($items),
            "files" => $items,
        ]);
    }
}
