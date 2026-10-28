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

namespace mod_videoprogress\material;

use context_module;
use MoodleQuickForm;
use stdClass;

/**
 * Defines the common contract implemented by support material subplugins.
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
     * @param stdClass|null $material Support material record.
     * @param context_module $context Module context used for permissions and File API access.
     * @return stdClass The loaded, created, or updated database record.
     */
    public function prepare_form_data(stdClass $data, stdClass|null $material, context_module $context): stdClass {
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
     */
    public function validation(array $data, array $files, stdClass|null $material, context_module $context): array {
        return [];
    }

    /**
     * Persists this material type configuration and moves draft files into protected storage.
     *
     * @param stdClass $material Support material record.
     * @param stdClass $data Validated input or tracking data.
     * @param context_module $context Module context used for permissions and File API access.
     * @return array Structured data produced by the operation.
     */
    abstract public function save(stdClass $material, stdClass $data, context_module $context): array;

    /**
     * Deletes files owned by this support material subplugin record.
     *
     * @param stdClass $material Support material record.
     * @param context_module $context Module context used for permissions and File API access.
     * @return void This method does not return a value.
     */
    public function delete(stdClass $material, context_module $context): void {
    }

    /**
     * Renders the compact support material card through the subplugin Mustache template.
     *
     * @param stdClass $material Support material record.
     * @param context_module $context Module context used for permissions and File API access.
     * @param int $cmid Course module identifier.
     * @return string The resolved or formatted string value.
     */
    abstract public function render_card(stdClass $material, context_module $context, int $cmid): string;

    /**
     * Renders the complete support material view through the subplugin Mustache template.
     *
     * @param stdClass $material Support material record.
     * @param context_module $context Module context used for permissions and File API access.
     * @param int $cmid Course module identifier.
     * @return string The resolved or formatted string value.
     */
    abstract public function render_full(stdClass $material, context_module $context, int $cmid): string;

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
