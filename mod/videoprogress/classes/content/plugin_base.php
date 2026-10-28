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
 * plugin_base.php
 *
 * @package   mod_videoprogress
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_videoprogress\content;

use context_module;
use MoodleQuickForm;
use stdClass;

/**
 * Defines the common contract implemented by synchronized video content subplugins.
 */
abstract class plugin_base {
    /**
     * Returns the localized name exposed to Moodle or the plugin interface.
     *
     * @return string The resolved or formatted string value.
     */
    abstract public function get_name(): string;

    /**
     * Adds the subplugin-specific configuration fields to the Moodle form.
     *
     * @param MoodleQuickForm $mform Moodle form instance receiving additional fields.
     * @return void This method does not return a value.
     */
    abstract public function add_form_elements(MoodleQuickForm $mform): void;

    /**
     * Prepares stored configuration and draft file areas for the edit form.
     *
     * @param stdClass $data Validated input or tracking data.
     * @param stdClass|null $item Synchronized content item record.
     * @param context_module $context Module context used for permissions and File API access.
     * @return stdClass The loaded, created, or updated database record.
     */
    public function prepare_form_data(stdClass $data, stdClass|null $item, context_module $context): stdClass {
        return $data;
    }

    /**
     * Performs server-side validation for submitted Moodle form values.
     *
     * @param array $data Validated input or tracking data.
     * @param array $files Files submitted with the Moodle form.
     * @param stdClass|null $item Synchronized content item record.
     * @param context_module $context Module context used for permissions and File API access.
     * @return array Structured data produced by the operation.
     */
    public function validation(array $data, array $files, stdClass|null $item, context_module $context): array {
        return [];
    }

    /**
     * Persists the subplugin-specific synchronized content configuration and files.
     *
     * @param stdClass $item Synchronized content item record.
     * @param stdClass $data Validated input or tracking data.
     * @param context_module $context Module context used for permissions and File API access.
     * @return array Structured data produced by the operation.
     */
    abstract public function save(stdClass $item, stdClass $data, context_module $context): array;

    /**
     * Deletes embedded files owned by this synchronized content item.
     *
     * @param stdClass $item Synchronized content item record.
     * @param context_module $context Module context used for permissions and File API access.
     * @return void This method does not return a value.
     */
    public function delete(stdClass $item, context_module $context): void {
    }

    /**
     * Indicates whether the content item must be completed before playback may continue.
     *
     * @param array $config Decoded subplugin or player configuration.
     * @return bool Whether the evaluated condition or operation succeeded.
     */
    abstract public function is_required(array $config): bool;

    /**
     * Indicates whether the content item pauses playback while it is displayed.
     *
     * @param array $config Decoded subplugin or player configuration.
     * @return bool Whether the evaluated condition or operation succeeded.
     */
    abstract public function pauses_video(array $config): bool;

    /**
     * Returns the Frankenstyle name of the AMD module that controls this content type.
     *
     * @return string The resolved or formatted string value.
     */
    abstract public function get_amd_module(): string;

    /**
     * Returns the sanitized configuration that may be exposed to the browser.
     *
     * @param array $config Decoded subplugin or player configuration.
     * @return array Structured data produced by the operation.
     */
    abstract public function get_client_data(array $config): array;

    /**
     * Renders the synchronized player overlay through the subplugin Mustache template.
     *
     * @param stdClass $item Synchronized content item record.
     * @param stdClass $point Video timeline point record.
     * @param context_module $context Module context used for permissions and File API access.
     * @return string The resolved or formatted string value.
     */
    abstract public function render_overlay(stdClass $item, stdClass $point, context_module $context): string;

    /**
     * Validates a student interaction response and returns completion and feedback.
     *
     * @param array $config Decoded subplugin or player configuration.
     * @param array $response Decoded student response.
     * @return array Structured data produced by the operation.
     */
    abstract public function validate_response(array $config, array $response): array;

    /**
     * Decodes a subplugin configuration JSON value into a safe associative array.
     *
     * @param stdClass|null $record Database record being processed.
     * @return array Structured data produced by the operation.
     */
    final protected function decode_config(stdClass|null $record): array {
        $config = json_decode($record->configdata ?? '', true);
        return is_array($config) ? $config : [];
    }
}
