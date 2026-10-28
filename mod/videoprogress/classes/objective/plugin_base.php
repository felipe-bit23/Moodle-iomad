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

namespace mod_videoprogress\objective;

use context_module;
use MoodleQuickForm;
use stdClass;

/**
 * Defines the contract implemented by learning objective type subplugins.
 */
abstract class plugin_base {
    /**
     * Returns the localized objective type name.
     *
     * @return string Localized objective type name.
     */
    abstract public function get_name(): string;

    /**
     * Adds type-specific fields to the objective form.
     *
     * @param MoodleQuickForm $mform Moodle form receiving the fields.
     * @return void
     */
    abstract public function add_form_elements(MoodleQuickForm $mform): void;

    /**
     * Prepares stored type configuration for the edit form.
     *
     * @param stdClass $data Base form data.
     * @param stdClass|null $objective Existing objective record.
     * @param context_module $context Activity context.
     * @return stdClass Prepared form data.
     */
    public function prepare_form_data(stdClass $data, stdClass|null $objective, context_module $context): stdClass {
        return $data;
    }

    /**
     * Validates type-specific form values.
     *
     * @param array $data Submitted form data.
     * @param array $files Submitted files.
     * @param stdClass|null $objective Existing objective record.
     * @param context_module $context Activity context.
     * @return array Validation errors indexed by field name.
     */
    public function validation(array $data, array $files, stdClass|null $objective, context_module $context): array {
        return [];
    }

    /**
     * Converts submitted values into the JSON-serializable type configuration.
     *
     * @param stdClass $objective Objective database record.
     * @param stdClass $data Submitted form data.
     * @param context_module $context Activity context.
     * @return array Type configuration to store.
     */
    abstract public function save(stdClass $objective, stdClass $data, context_module $context): array;

    /**
     * Deletes resources owned by the objective type.
     *
     * @param stdClass $objective Objective database record.
     * @param context_module $context Activity context.
     * @return void
     */
    public function delete(stdClass $objective, context_module $context): void {
    }

    /**
     * Returns a short readable summary for management and confirmation screens.
     *
     * @param stdClass $objective Objective database record.
     * @param context_module $context Activity context.
     * @return string Formatted objective summary.
     */
    abstract public function get_summary(stdClass $objective, context_module $context): string;

    /**
     * Renders the student-facing objective with a Mustache template.
     *
     * @param stdClass $objective Objective database record.
     * @param context_module $context Activity context.
     * @param int $cmid Course module identifier.
     * @return string Rendered objective HTML.
     */
    abstract public function render(stdClass $objective, context_module $context, int $cmid): string;

    /**
     * Decodes a stored JSON configuration into a safe associative array.
     *
     * @param stdClass|null $record Objective database record.
     * @return array Decoded configuration.
     */
    final protected function decode_config(stdClass|null $record): array {
        $config = json_decode($record->configdata ?? '', true);
        return is_array($config) ? $config : [];
    }
}
