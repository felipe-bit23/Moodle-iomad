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
 * Office support material implementation.
 *
 * @package videoprogressmaterial_office
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace videoprogressmaterial_office;

use coding_exception;
use context_module;
use mod_videoprogress\material\plugin_base;
use moodle_url;
use MoodleQuickForm;
use stdClass;

/**
 * Provides DOC, DOCX, XLS, XLSX, PPT and PPTX files with Google Docs Viewer preview.
 */
class plugin extends plugin_base {
    /** @var array Supported Office extensions. */
    private const ACCEPTED_TYPES = ['.doc', '.docx', '.xls', '.xlsx', '.ppt', '.pptx'];

    /**
     * get_name
     *
     * @return string
     * @throws coding_exception
     */
    public function get_name(): string {
        return get_string("pluginname", "videoprogressmaterial_office");
    }

    /**
     * add_form_elements
     *
     * @param MoodleQuickForm $mform
     * @return void
     * @throws coding_exception
     */
    public function add_form_elements(MoodleQuickForm $mform): void {
        $mform->addElement("filemanager", "officefile", get_string("officefile", "videoprogressmaterial_office"), null, [
            "subdirs" => 0,
            "maxfiles" => 1,
            "accepted_types" => self::ACCEPTED_TYPES,
        ]);
        $mform->addHelpButton("officefile", "officefile", "videoprogressmaterial_office");
        $mform->addElement("advcheckbox", "allowdownload", get_string("allowdownload", "videoprogressmaterial_office"));
        $mform->setDefault("allowdownload", 1);
        $mform->addHelpButton("allowdownload", "allowdownload", "videoprogressmaterial_office");
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
        $draftid = file_get_submitted_draft_itemid("officefile");
        file_prepare_draft_area($draftid, $context->id, "videoprogressmaterial_office", "document",
            $material->id ?? 0, ["subdirs" => 0, "maxfiles" => 1, "accepted_types" => self::ACCEPTED_TYPES]);
        $config = $this->decode_config($material);
        $data->officefile = $draftid;
        $data->allowdownload = !isset($config["allowdownload"]) || !empty($config["allowdownload"]) ? 1 : 0;
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
        $draftid = (int)($data["officefile"] ?? 0);
        if ($draftid && !empty(file_get_draft_area_info($draftid)["filecount"])) {
            return [];
        }
        if ($material && get_file_storage()->get_area_files(
                $context->id, "videoprogressmaterial_office", "document", $material->id, "id", false)) {
            return [];
        }
        return ["officefile" => get_string("required")];
    }

    /**
     * save
     *
     * @param stdClass $material
     * @param stdClass $data
     * @param context_module $context
     * @return array
     * @throws \Random\RandomException
     * @throws coding_exception
     */
    public function save(stdClass $material, stdClass $data, context_module $context): array {
        file_save_draft_area_files($data->officefile, $context->id, "videoprogressmaterial_office", "document",
            $material->id, ["subdirs" => 0, "maxfiles" => 1, "accepted_types" => self::ACCEPTED_TYPES]);

        return [
            "allowdownload" => !empty($data->allowdownload),
            "previewtoken" => bin2hex(random_bytes(24)),
        ];
    }

    /**
     * delete
     *
     * @param stdClass $material
     * @param context_module $context
     * @return void
     */
    public function delete(stdClass $material, context_module $context): void {
        get_file_storage()->delete_area_files($context->id, "videoprogressmaterial_office", "document", $material->id);
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

        return $OUTPUT->render_from_template('videoprogressmaterial_office/card', [
            "name" => format_string($material->name),
            "filetype" => $this->get_file_extension($material, $context),
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
     * @throws \core\exception\moodle_exception
     */
    public function render_full(stdClass $material, context_module $context, int $cmid): string {
        global $OUTPUT;

        $config = $this->decode_config($material);
        $file = $this->get_file($material, $context);
        $previewtoken = (string)($config["previewtoken"] ?? '');
        $previewurl = '';
        $viewerurl = '';
        $downloadurl = '';

        if ($file && $previewtoken !== '') {
            $previewurl = (new moodle_url('/mod/videoprogress/material/office/preview.php', [
                "materialid" => $material->id,
                "token" => $previewtoken,
            ]))->out(false);
            $viewerurl = 'https://docs.google.com/gview?embedded=1&url=' . rawurlencode($previewurl);
        }

        if ($file && !empty($config["allowdownload"])) {
            $downloadurl = moodle_url::make_pluginfile_url(
                $context->id,
                "videoprogressmaterial_office",
                "document",
                $material->id,
                $file->get_filepath(),
                $file->get_filename(),
                true
            )->out(false);
        }

        return $OUTPUT->render_from_template('videoprogressmaterial_office/view', [
            "name" => format_string($material->name),
            "filename" => $file ? $file->get_filename() : '',
            "hasfile" => (bool)$file,
            "haspreview" => $viewerurl !== '',
            "viewerurl" => $viewerurl,
            "allowdownload" => $downloadurl !== '',
            "downloadurl" => $downloadurl,
        ]);
    }

    /**
     * get_file
     *
     * Returns the stored Office document.
     */
    private function get_file(stdClass $material, context_module $context): mixed {
        $files = get_file_storage()->get_area_files(
            $context->id, "videoprogressmaterial_office", "document", $material->id, "filename", false);
        return $files ? reset($files) : false;
    }

    /**
     * get_file_extension
     *
     * Returns a short uppercase file extension for the card badge.
     */
    private function get_file_extension(stdClass $material, context_module $context): string {
        $file = $this->get_file($material, $context);
        if (!$file) {
            return get_string("officebadge", "videoprogressmaterial_office");
        }
        $extension = pathinfo($file->get_filename(), PATHINFO_EXTENSION);
        return strtoupper($extension ?: get_string("officebadge", "videoprogressmaterial_office"));
    }
}
