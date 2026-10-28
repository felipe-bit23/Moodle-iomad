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
 * Image support material implementation.
 *
 * @package videoprogressmaterial_image
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace videoprogressmaterial_image;

use context_module;
use mod_videoprogress\material\plugin_base;
use moodle_url;
use MoodleQuickForm;
use stdClass;

/**
 * Displays one uploaded image as support material.
 */
class plugin extends plugin_base {
    /** @var array Safe image extensions supported by the subplugin. */
    private const ACCEPTED_TYPES = ['.jpg', '.jpeg', '.png', '.gif', '.webp'];

    /**
     * get_name
     *
     * @return string
     * @throws \coding_exception
     */
    public function get_name(): string {
        return get_string("pluginname", "videoprogressmaterial_image");
    }

    /**
     * add_form_elements
     *
     * @param MoodleQuickForm $mform
     * @return void
     * @throws \coding_exception
     */
    public function add_form_elements(MoodleQuickForm $mform): void {
        $mform->addElement("filemanager", "imagefile", get_string("imagefile", "videoprogressmaterial_image"), null, [
            "subdirs" => 0,
            "maxfiles" => 1,
            "accepted_types" => self::ACCEPTED_TYPES,
        ]);
        $mform->addHelpButton("imagefile", "imagefile", "videoprogressmaterial_image");
        $mform->addElement("text", "alttext", get_string("alttext", "videoprogressmaterial_image"), ["size" => 80]);
        $mform->setType("alttext", PARAM_TEXT);
        $mform->addHelpButton("alttext", "alttext", "videoprogressmaterial_image");
        $mform->addElement("advcheckbox", "allowdownload", get_string("allowdownload", "videoprogressmaterial_image"));
        $mform->setDefault("allowdownload", 1);
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
        $draftid = file_get_submitted_draft_itemid("imagefile");
        file_prepare_draft_area($draftid, $context->id, "videoprogressmaterial_image", "image",
            $material->id ?? 0, ["subdirs" => 0, "maxfiles" => 1, "accepted_types" => self::ACCEPTED_TYPES]);
        $config = $this->decode_config($material);
        $data->imagefile = $draftid;
        $data->alttext = $config["alttext"] ?? '';
        $data->allowdownload = (!isset($config["allowdownload"]) || !empty($config["allowdownload"])) ? 1 : 0;
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
     * @throws \coding_exception
     */
    public function validation(array $data, array $files, stdClass|null $material, context_module $context): array {
        $draftid = (int)($data["imagefile"] ?? 0);
        if ($draftid && !empty(file_get_draft_area_info($draftid)["filecount"])) {
            return [];
        }
        if ($material && get_file_storage()->get_area_files(
                $context->id, "videoprogressmaterial_image", "image", $material->id, "id", false)) {
            return [];
        }
        return ["imagefile" => get_string("required")];
    }

    /**
     * save
     *
     * @param stdClass $material
     * @param stdClass $data
     * @param context_module $context
     * @return array
     * @throws \coding_exception
     */
    public function save(stdClass $material, stdClass $data, context_module $context): array {
        file_save_draft_area_files($data->imagefile, $context->id, "videoprogressmaterial_image", "image",
            $material->id, ["subdirs" => 0, "maxfiles" => 1, "accepted_types" => self::ACCEPTED_TYPES]);
        return [
            "alttext" => clean_param($data->alttext ?? '', PARAM_TEXT),
            "allowdownload" => !empty($data->allowdownload),
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
        get_file_storage()->delete_area_files($context->id, "videoprogressmaterial_image", "image", $material->id);
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

        return $OUTPUT->render_from_template('videoprogressmaterial_image/card', [
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
     */
    public function render_full(stdClass $material, context_module $context, int $cmid): string {
        global $OUTPUT;

        $config = $this->decode_config($material);
        $file = $this->get_file($material, $context);
        $imageurl = '';
        $downloadurl = '';
        if ($file) {
            $imageurl = moodle_url::make_pluginfile_url(
                $context->id,
                "videoprogressmaterial_image",
                "image",
                $material->id,
                $file->get_filepath(),
                $file->get_filename(),
                false
            )->out(false);
            if (!empty($config["allowdownload"])) {
                $downloadurl = moodle_url::make_pluginfile_url(
                    $context->id,
                    "videoprogressmaterial_image",
                    "image",
                    $material->id,
                    $file->get_filepath(),
                    $file->get_filename(),
                    true
                )->out(false);
            }
        }

        return $OUTPUT->render_from_template('videoprogressmaterial_image/view', [
            "name" => format_string($material->name),
            "hasfile" => (bool)$file,
            "imageurl" => $imageurl,
            "alttext" => trim((string)($config["alttext"] ?? '')) ?: format_string($material->name),
            "allowdownload" => $downloadurl !== '',
            "downloadurl" => $downloadurl,
        ]);
    }

    /**
     * Returns the stored image file.
     */
    private function get_file(stdClass $material, context_module $context): mixed {
        $files = get_file_storage()->get_area_files(
            $context->id, "videoprogressmaterial_image", "image", $material->id, "filename", false);
        return $files ? reset($files) : false;
    }
}
