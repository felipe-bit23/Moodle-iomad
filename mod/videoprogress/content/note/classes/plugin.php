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
 * @package   videoprogresscontent_note
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace videoprogresscontent_note;

use coding_exception;
use context;
use context_module;
use mod_videoprogress\content\plugin_base;
use MoodleQuickForm;
use stdClass;

/**
 * Implements explanatory synchronized notes with optional pause-until-acknowledged behavior.
 */
class plugin extends plugin_base {
    /**
     * Returns the localized name exposed to Moodle or the plugin interface.
     *
     * @return string The resolved or formatted string value.
     * @throws coding_exception
     */
    public function get_name(): string {
        return get_string("pluginname", "videoprogresscontent_note");
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
        $mform->addElement("editor", "message_editor", get_string("message", "videoprogresscontent_note"), null,
            self::editor_options($PAGE->context));
        $mform->addRule("message_editor", null, "required", null, "client");
        $mform->addElement("selectyesno", "pausevideo", get_string("pausevideo", "videoprogresscontent_note"));
        $options = [];
        foreach ([5, 8, 10, 15, 30] as $seconds) {
            $options[$seconds] = get_string("seconds", "videoprogresscontent_note", $seconds);
        }
        $mform->addElement("select", "displayduration", get_string("displayduration", "videoprogresscontent_note"), $options);
        $mform->setDefault("displayduration", 8);
        $mform->hideIf("displayduration", "pausevideo", "checked");
    }

    /**
     * Prepares stored configuration and draft file areas for the edit form.
     *
     * @param stdClass $data Validated input or tracking data.
     * @param stdClass|null $item Synchronized content item record.
     * @param context_module $context Module context used for permissions and File API access.
     * @return stdClass The loaded, created, or updated database record.
     * @throws coding_exception
     */
    public function prepare_form_data(stdClass $data, stdClass|null $item, context_module $context): stdClass {
        $config = $this->decode_config($item);
        $data->message = $config["message"] ?? '';
        $data->messageformat = $config["messageformat"] ?? FORMAT_HTML;
        $data->pausevideo = !empty($config["pausevideo"]) ? 1 : 0;
        $data->displayduration = (int)($config["displayduration"] ?? 8);
        return file_prepare_standard_editor($data, "message", self::editor_options($context), $context,
            "videoprogresscontent_note", "message", $item->id ?? 0);
    }

    /**
     * Performs server-side validation for submitted Moodle form values.
     *
     * @param array $data Validated input or tracking data.
     * @param array $files Files submitted with the Moodle form.
     * @param stdClass|null $item Synchronized content item record.
     * @param context_module $context Module context used for permissions and File API access.
     * @return array Structured data produced by the operation.
     * @throws coding_exception
     */
    public function validation(array $data, array $files, stdClass|null $item, context_module $context): array {
        $message = trim((string)($data["message_editor"]["text"] ?? ''));
        return $message === '' ? ["message_editor" => get_string("required")] : [];
    }

    /**
     * Persists this interaction type configuration and moves embedded files into protected storage.
     *
     * @param stdClass $item Synchronized content item record.
     * @param stdClass $data Validated input or tracking data.
     * @param context_module $context Module context used for permissions and File API access.
     * @return array Structured data produced by the operation.
     */
    public function save(stdClass $item, stdClass $data, context_module $context): array {
        $data = file_postupdate_standard_editor($data, "message", self::editor_options($context), $context,
            "videoprogresscontent_note", "message", $item->id);
        return [
            "message" => $data->message,
            "messageformat" => $data->messageformat,
            "pausevideo" => !empty($data->pausevideo),
            "displayduration" => max(5, min(30, (int)$data->displayduration)),
        ];
    }

    /**
     * Deletes embedded files owned by this synchronized content item.
     *
     * @param stdClass $item Synchronized content item record.
     * @param context_module $context Module context used for permissions and File API access.
     * @return void This method does not return a value.
     */
    public function delete(stdClass $item, context_module $context): void {
        get_file_storage()->delete_area_files($context->id, "videoprogresscontent_note", "message", $item->id);
    }

    /**
     * Indicates whether the content item must be completed before playback may continue.
     *
     * @param array $config Decoded subplugin or player configuration.
     * @return bool Whether the evaluated condition or operation succeeded.
     */
    public function is_required(array $config): bool {
        return !empty($config["pausevideo"]);
    }

    /**
     * Indicates whether the content item pauses playback while it is displayed.
     *
     * @param array $config Decoded subplugin or player configuration.
     * @return bool Whether the evaluated condition or operation succeeded.
     */
    public function pauses_video(array $config): bool {
        return !empty($config["pausevideo"]);
    }

    /**
     * Returns the Frankenstyle name of the AMD module that controls this content type.
     *
     * @return string The resolved or formatted string value.
     */
    public function get_amd_module(): string {
        return 'videoprogresscontent_note/note';
    }

    /**
     * Returns the sanitized configuration that may be exposed to the browser.
     *
     * @param array $config Decoded subplugin or player configuration.
     * @return array Structured data produced by the operation.
     */
    public function get_client_data(array $config): array {
        return ["displayduration" => max(5, min(30, (int)($config["displayduration"] ?? 8)))];
    }

    /**
     * Renders the synchronized player overlay through the subplugin Mustache template.
     *
     * @param stdClass $item Synchronized content item record.
     * @param stdClass $point Video timeline point record.
     * @param context_module $context Module context used for permissions and File API access.
     * @return string The resolved or formatted string value.
     * @throws coding_exception
     */
    public function render_overlay(stdClass $item, stdClass $point, context_module $context): string {
        global $OUTPUT;
        $config = $this->decode_config($item);
        $message = file_rewrite_pluginfile_urls($config["message"] ?? '', 'pluginfile.php', $context->id,
            "videoprogresscontent_note", "message", $item->id);
        return $OUTPUT->render_from_template('videoprogresscontent_note/overlay', [
            "itemid" => $item->id,
            "pointtitle" => format_string($point->title),
            "message" => format_text($message, $config["messageformat"] ?? FORMAT_HTML, ["context" => $context]),
        ]);
    }

    /**
     * Validates a student interaction response and returns completion and feedback.
     *
     * @param array $config Decoded subplugin or player configuration.
     * @param array $response Decoded student response.
     * @return array Structured data produced by the operation.
     * @throws coding_exception
     */
    public function validate_response(array $config, array $response): array {
        $completed = !empty($response["acknowledged"]);
        return [
            "completed" => $completed,
            "feedback" => get_string($completed ? "acknowledged" : "acknowledgementrequired",
                "videoprogresscontent_note"),
        ];
    }

    /**
     * Returns the secure Moodle editor and embedded-file configuration for this subplugin.
     *
     * @param context $context Module context used for permissions and File API access.
     * @return array Structured data produced by the operation.
     */
    private static function editor_options(context $context): array {
        return ["context" => $context, "maxfiles" => 10, "maxbytes" => 0, "subdirs" => 1, "trusttext" => false];
    }
}
