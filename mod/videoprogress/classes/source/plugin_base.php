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

namespace mod_videoprogress\source;

use coding_exception;
use context_module;
use moodle_url;
use MoodleQuickForm;
use stdClass;
use stored_file;

/**
 * Defines the contract implemented by every independently installable video source.
 */
abstract class plugin_base {
    /**
     * Returns the source order used in the selector and to choose the first available default.
     *
     * @return int Source order where lower values are displayed first.
     */
    public function get_sort_order(): int {
        return 100;
    }

    /**
     * Returns the localized source name displayed in activity forms.
     *
     * @return string Localized video source name.
     */
    abstract public function get_name(): string;

    /**
     * Adds source-specific fields and visibility rules to the activity form.
     *
     * @param MoodleQuickForm $mform Activity form receiving the source fields.
     * @param string $sourcefield Name of the source selector field.
     * @return void
     */
    abstract public function add_form_elements(MoodleQuickForm $mform, string $sourcefield): void;

    /**
     * Validates the fields owned by this source when it is selected.
     *
     * @param array $data Submitted activity form values.
     * @param array $files Submitted activity form files.
     * @return array Field names mapped to localized validation errors.
     */
    abstract public function validation(array $data, array $files): array;

    /**
     * Converts submitted fields into the normalized configuration persisted for this source.
     *
     * @param stdClass $data Submitted activity data.
     * @return array Normalized source configuration.
     */
    abstract public function build_config(stdClass $data): array;

    /**
     * Returns the legacy videourl value retained for backward compatibility with older backups.
     *
     * @param array $config Normalized source configuration.
     * @return string Backward-compatible source value.
     */
    abstract public function get_legacy_value(array $config): string;

    /**
     * Builds browser-safe source data consumed by the source player template and AMD module.
     *
     * @param stdClass $activity Activity configuration record.
     * @param context_module $context Module context used for File API access.
     * @return array Browser-safe player configuration.
     */
    abstract public function get_player_config(stdClass $activity, context_module $context): array;

    /**
     * Returns the Mustache template that renders this source player container.
     *
     * @return string Moodle template identifier.
     */
    abstract public function get_player_template(): string;

    /**
     * Returns the AMD module that creates the common player adapter for this source.
     *
     * @return string Moodle AMD module identifier.
     */
    abstract public function get_amd_module(): string;

    /**
     * Prepares stored source values and protected files for the activity edit form.
     *
     * @param array $defaultvalues Values passed to the activity form.
     * @param context_module $context Module context used for File API access.
     * @return void
     */
    public function prepare_form_data(array &$defaultvalues, context_module $context): void {
    }

    /**
     * Moves source-specific draft files into their protected permanent file areas.
     *
     * @param stdClass $data Saved activity data including the course-module identifier.
     * @param context_module $context Module context used for File API access.
     * @return void
     */
    public function save_files(stdClass $data, context_module $context): void {
    }

    /**
     * Removes protected files owned by this source from an activity context.
     *
     * @param context_module $context Module context whose source files must be removed.
     * @return void
     */
    public function delete_files(context_module $context): void {
    }

    /**
     * Indicates whether the activity form should allow a custom poster for this source.
     *
     * @return bool Whether custom poster upload is supported.
     */
    public function supports_poster(): bool {
        return true;
    }

    /**
     * Indicates whether the activity form should allow uploaded caption tracks for this source.
     *
     * @return bool Whether uploaded caption tracks are supported.
     */
    public function supports_uploaded_captions(): bool {
        return true;
    }

    /**
     * Returns the protected media file supplied to a server-side transcription provider.
     *
     * @param context_module $context Module context containing source files.
     * @return stored_file|null Server-readable media file or null when unavailable.
     */
    public function get_transcription_file(context_module $context): stored_file|null {
        return null;
    }

    /**
     * Decodes the current source configuration and falls back to the legacy activity value.
     *
     * @param stdClass $activity Activity configuration record.
     * @return array Normalized source configuration.
     */
    final protected function decode_config(stdClass $activity): array {
        $raw = trim((string)($activity->sourceconfig ?? ''));
        if ($raw !== '') {
            $config = json_decode($raw, true);
            if (is_array($config)) {
                return $config;
            }
        }
        return $this->get_legacy_config((string)($activity->videourl ?? ''));
    }

    /**
     * Converts an activity value created before source subplugins into normalized configuration.
     *
     * @param string $legacyvalue Legacy videourl value.
     * @return array Normalized source configuration.
     */
    abstract protected function get_legacy_config(string $legacyvalue): array;

    /**
     * Returns a protected URL for the first file in a Moodle File API area.
     *
     * @param context_module $context Module context containing the file.
     * @param string $component File API component name.
     * @param string $filearea File API area name.
     * @param int $itemid File API item identifier.
     * @return string Protected file URL or an empty string when no file exists.
     * @throws coding_exception
     */
    final protected function first_file_url(context_module $context, string $component, string $filearea, int $itemid = 0): string {
        $files = get_file_storage()->get_area_files(
            $context->id,
            $component,
            $filearea,
            $itemid,
            "filename",
            false
        );
        if (!$files) {
            return '';
        }
        $file = reset($files);
        return moodle_url::make_pluginfile_url(
            $context->id,
            $component,
            $filearea,
            $itemid,
            $file->get_filepath(),
            $file->get_filename()
        )->out(false);
    }
}
