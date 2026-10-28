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
 * plugin.php
 *
 * @package   videoprogressmaterial_pdf
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace videoprogressmaterial_pdf;

use coding_exception;
use context_module;
use core\exception\moodle_exception;
use mod_videoprogress\material\plugin_base;
use moodle_url;
use MoodleQuickForm;
use stdClass;

/**
 * Implements protected PDF support materials with web viewing and configurable download access.
 */
class plugin extends plugin_base {
    /**
     * Returns the localized name exposed to Moodle or the plugin interface.
     *
     * @return string The resolved or formatted string value.
     * @throws coding_exception
     */
    public function get_name(): string {
        return get_string("pluginname", "videoprogressmaterial_pdf");
    }

    /**
     * Adds the subplugin-specific configuration fields to the Moodle form.
     *
     * @param MoodleQuickForm $mform Moodle form instance receiving additional fields.
     * @return void This method does not return a value.
     * @throws coding_exception
     */
    public function add_form_elements(MoodleQuickForm $mform): void {
        $mform->addElement("filemanager", "pdffile", get_string("pdffile", "videoprogressmaterial_pdf"), null, [
            "subdirs" => 0,
            "maxfiles" => 1,
            "accepted_types" => ['.pdf'],
        ]);
        $mform->addElement("selectyesno", "allowdownload", get_string("allowdownload", "videoprogressmaterial_pdf"));
        $mform->setDefault("allowdownload", 1);
    }

    /**
     * Prepares stored configuration and draft file areas for the edit form.
     *
     * @param stdClass $data Validated input or tracking data.
     * @param stdClass|null $material Support material record.
     * @param context_module $context Module context used for permissions and File API access.
     * @return stdClass The loaded, created, or updated database record.
     */
    public function prepare_form_data(stdClass $data, stdClass|null $material, context_module $context): stdClass {
        $draftid = file_get_submitted_draft_itemid("pdffile");
        file_prepare_draft_area($draftid, $context->id, "videoprogressmaterial_pdf", "document",
            $material->id ?? 0, ["subdirs" => 0, "maxfiles" => 1, "accepted_types" => ['.pdf']]);
        $config = $this->decode_config($material);
        $data->pdffile = $draftid;
        $data->allowdownload = !empty($config["allowdownload"]) ? 1 : 0;
        return $data;
    }

    /**
     * Performs server-side validation for submitted Moodle form values.
     *
     * @param array $data Validated input or tracking data.
     * @param array $files Files submitted with the Moodle form.
     * @param stdClass|null $material Support material record.
     * @param context_module $context Module context used for permissions and File API access.
     * @return array Structured data produced by the operation.
     * @throws coding_exception
     */
    public function validation(array $data, array $files, stdClass|null $material, context_module $context): array {
        $draftid = (int)($data["pdffile"] ?? 0);
        if ($draftid && !empty(file_get_draft_area_info($draftid)["filecount"])) {
            return [];
        }
        if ($material && get_file_storage()->get_area_files(
                $context->id, "videoprogressmaterial_pdf", "document", $material->id, "id", false)) {
            return [];
        }
        return ["pdffile" => get_string("required")];
    }

    /**
     * Persists this material type configuration and moves draft files into protected storage.
     *
     * @param stdClass $material Support material record.
     * @param stdClass $data Validated input or tracking data.
     * @param context_module $context Module context used for permissions and File API access.
     * @return array Structured data produced by the operation.
     * @throws coding_exception
     */
    public function save(stdClass $material, stdClass $data, context_module $context): array {
        file_save_draft_area_files($data->pdffile, $context->id, "videoprogressmaterial_pdf", "document",
            $material->id, ["subdirs" => 0, "maxfiles" => 1, "accepted_types" => ['.pdf']]);
        return ["allowdownload" => !empty($data->allowdownload)];
    }

    /**
     * Deletes files owned by this support material subplugin record.
     *
     * @param stdClass $material Support material record.
     * @param context_module $context Module context used for permissions and File API access.
     * @return void This method does not return a value.
     */
    public function delete(stdClass $material, context_module $context): void {
        get_file_storage()->delete_area_files($context->id, "videoprogressmaterial_pdf", "document", $material->id);
    }

    /**
     * Renders the compact support material card through the subplugin Mustache template.
     *
     * @param stdClass $material Support material record.
     * @param context_module $context Module context used for permissions and File API access.
     * @param int $cmid Course module identifier.
     * @return string The resolved or formatted string value.
     * @throws moodle_exception
     */
    public function render_card(stdClass $material, context_module $context, int $cmid): string {
        global $OUTPUT;
        return $OUTPUT->render_from_template('videoprogressmaterial_pdf/card', [
            "name" => format_string($material->name),
            "viewurl" => (new moodle_url('/mod/videoprogress/material/view.php', [
                "id" => $cmid, "materialid" => $material->id,
            ]))->out(false),
        ]);
    }

    /**
     * Renders the complete support material view through the subplugin Mustache template.
     *
     * @param stdClass $material Support material record.
     * @param context_module $context Module context used for permissions and File API access.
     * @param int $cmid Course module identifier.
     * @return string The resolved or formatted string value.
     * @throws coding_exception
     */
    public function render_full(stdClass $material, context_module $context, int $cmid): string {
        global $OUTPUT;
        $config = $this->decode_config($material);
        $viewurl = $this->file_url($material, $context, false);
        return $OUTPUT->render_from_template('videoprogressmaterial_pdf/view', [
            "name" => format_string($material->name),
            "hasfile" => $viewurl !== '',
            "fileurl" => $viewurl,
            "allowdownload" => !empty($config["allowdownload"]),
            "downloadurl" => !empty($config["allowdownload"]) ? $this->file_url($material, $context, true) : '',
        ]);
    }

    /**
     * Builds the protected pluginfile URL for the material file.
     *
     * @param stdClass $material Support material record.
     * @param context_module $context Module context used for permissions and File API access.
     * @param bool $download Whether the generated URL should request a download.
     * @return string The resolved or formatted string value.
     * @throws coding_exception
     */
    private function file_url(stdClass $material, context_module $context, bool $download): string {
        $files = get_file_storage()->get_area_files(
            $context->id, "videoprogressmaterial_pdf", "document", $material->id, "filename", false);
        if (!$files) {
            return '';
        }
        $file = reset($files);
        return moodle_url::make_pluginfile_url(
            $context->id,
            "videoprogressmaterial_pdf",
            "document",
            $material->id,
            $file->get_filepath(),
            $file->get_filename(),
            $download
        )->out(false);
    }
}
