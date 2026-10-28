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
 * @package   videoprogressmaterial_html
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace videoprogressmaterial_html;

use coding_exception;
use context;
use context_module;
use core\exception\moodle_exception;
use mod_videoprogress\material\plugin_base;
use moodle_url;
use MoodleQuickForm;
use stdClass;

/**
 * Implements HTML editor support materials with protected embedded files.
 */
class plugin extends plugin_base {
    /**
     * Returns the localized name exposed to Moodle or the plugin interface.
     *
     * @return string The resolved or formatted string value.
     * @throws coding_exception
     */
    public function get_name(): string {
        return get_string("pluginname", "videoprogressmaterial_html");
    }

    /**
     * Adds the subplugin-specific configuration fields to the Moodle form.
     *
     * @param MoodleQuickForm $mform Moodle form instance receiving additional fields.
     * @return void This method does not return a value.
     * @throws coding_exception
     */
    public function add_form_elements(MoodleQuickForm $mform): void {
        global $PAGE;
        $mform->addElement("editor", "body_editor", get_string("body", "videoprogressmaterial_html"), null,
            self::editor_options($PAGE->context));
        $mform->addRule("body_editor", null, "required", null, "client");
    }

    /**
     * Prepares stored configuration and draft file areas for the edit form.
     *
     * @param stdClass $data Validated input or tracking data.
     * @param stdClass|null $material Support material record.
     * @param context_module $context Module context used for permissions and File API access.
     * @return stdClass The loaded, created, or updated database record.
     * @throws coding_exception
     */
    public function prepare_form_data(stdClass $data, stdClass|null $material, context_module $context): stdClass {
        $config = $this->decode_config($material);
        $data->body = $config["body"] ?? '';
        $data->bodyformat = $config["bodyformat"] ?? FORMAT_HTML;
        return file_prepare_standard_editor($data, "body", self::editor_options($context), $context,
            "videoprogressmaterial_html", "content", $material->id ?? 0);
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
        $body = trim((string)($data["body_editor"]["text"] ?? ''));
        return $body === '' ? ["body_editor" => get_string("required")] : [];
    }

    /**
     * Persists this material type configuration and moves draft files into protected storage.
     *
     * @param stdClass $material Support material record.
     * @param stdClass $data Validated input or tracking data.
     * @param context_module $context Module context used for permissions and File API access.
     * @return array Structured data produced by the operation.
     */
    public function save(stdClass $material, stdClass $data, context_module $context): array {
        $data = file_postupdate_standard_editor($data, "body", self::editor_options($context), $context,
            "videoprogressmaterial_html", "content", $material->id);
        return ["body" => $data->body, "bodyformat" => $data->bodyformat];
    }

    /**
     * Deletes files owned by this support material subplugin record.
     *
     * @param stdClass $material Support material record.
     * @param context_module $context Module context used for permissions and File API access.
     * @return void This method does not return a value.
     */
    public function delete(stdClass $material, context_module $context): void {
        get_file_storage()->delete_area_files($context->id, "videoprogressmaterial_html", "content", $material->id);
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
        return $OUTPUT->render_from_template('videoprogressmaterial_html/card', [
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
        $body = file_rewrite_pluginfile_urls($config["body"] ?? '', 'pluginfile.php', $context->id,
            "videoprogressmaterial_html", "content", $material->id);
        return $OUTPUT->render_from_template('videoprogressmaterial_html/view', [
            "name" => format_string($material->name),
            "body" => format_text($body, $config["bodyformat"] ?? FORMAT_HTML, ["context" => $context]),
        ]);
    }

    /**
     * Returns the secure Moodle editor and embedded-file configuration for this subplugin.
     *
     * @param context $context Module context used for permissions and File API access.
     * @return array Structured data produced by the operation.
     */
    private static function editor_options(context $context): array {
        return [
            "context" => $context,
            "maxfiles" => 20,
            "maxbytes" => 0,
            "noclean" => false,
            "trusttext" => false,
            "subdirs" => 1,
        ];
    }
}
