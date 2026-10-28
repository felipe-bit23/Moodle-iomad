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
 * @package   videoprogresssource_url
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace videoprogresssource_url;

use coding_exception;
use context_module;
use mod_videoprogress\source\plugin_base;
use moodle_exception;
use MoodleQuickForm;
use stdClass;

/**
 * Implements validated direct HTML5 and HLS URLs as an independently installable video source.
 */
class plugin extends plugin_base {
    /**
     * Returns the localized source name displayed in activity forms.
     *
     * @return string Localized direct URL source name.
     * @throws coding_exception
     */
    public function get_name(): string {
        return get_string("pluginname", "videoprogresssource_url");
    }

    /**
     * Adds the direct video URL field and its source-dependent visibility rule.
     *
     * @param MoodleQuickForm $mform Activity form receiving the URL field.
     * @param string $sourcefield Name of the source selector field.
     * @return void
     * @throws coding_exception
     */
    public function add_form_elements(MoodleQuickForm $mform, string $sourcefield): void {
        $mform->addElement("url", "videourl", get_string("videourl", "videoprogresssource_url"),
            ["size" => 80], ["usefilepicker" => false]);
        $mform->setType("videourl", PARAM_URL);
        $mform->hideIf("videourl", $sourcefield, "neq", "url");
    }

    /**
     * Validates the selected HTTP URL and supported HTML5 or HLS file extension.
     *
     * @param array $data Submitted activity form values.
     * @param array $files Submitted activity form files.
     * @return array Field names mapped to localized validation errors.
     */
    public function validation(array $data, array $files): array {
        try {
            $this->build_config((object)$data);
            return [];
        } catch (moodle_exception $exception) {
            return ["videourl" => $exception->getMessage()];
        }
    }

    /**
     * Normalizes a direct HTML5 or HLS URL and records whether HLS fallback is required.
     *
     * @param stdClass $data Submitted activity data.
     * @return array Normalized URL source configuration.
     * @throws moodle_exception
     */
    public function build_config(stdClass $data): array {
        $url = trim((string)($data->videourl ?? ''));
        if (!filter_var($url, FILTER_VALIDATE_URL) ||
            !in_array(strtolower((string)parse_url($url, PHP_URL_SCHEME)), ["http", "https"], true)) {
            throw new moodle_exception("invalidurl", "videoprogresssource_url");
        }
        $extension = strtolower(pathinfo((string)parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
        if (!in_array($extension, ["mp4", "webm", "ogv", "m4v", "mov", "m3u8"], true)) {
            throw new moodle_exception("invalidextension", "videoprogresssource_url");
        }
        return ["url" => $url, "hls" => $extension === "m3u8"];
    }

    /**
     * Returns the normalized URL retained for backward compatibility with older backups.
     *
     * @param array $config Normalized source configuration.
     * @return string Backward-compatible direct video URL.
     */
    public function get_legacy_value(array $config): string {
        return (string)($config["url"] ?? '');
    }

    /**
     * Restores the current direct URL in the activity edit form.
     *
     * @param array $defaultvalues Values passed to the activity form.
     * @param context_module $context Module context used for File API access.
     * @return void
     */
    public function prepare_form_data(array &$defaultvalues, context_module $context): void {
        $config = $this->decode_config((object)$defaultvalues);
        $defaultvalues["videourl"] = $config["url"] ?? '';
    }

    /**
     * Builds the direct URL and HLS fallback data used by the browser adapter.
     *
     * @param stdClass $activity Activity configuration record.
     * @param context_module $context Module context used for File API access.
     * @return array Browser-safe URL player configuration.
     */
    public function get_player_config(stdClass $activity, context_module $context): array {
        global $CFG;

        $config = $this->decode_config($activity);
        return [
            "url" => (string)($config["url"] ?? ''),
            "hls" => !empty($config["hls"]),
            "hlsjsurl" => $CFG->wwwroot . '/mod/videoprogress/vendor/hls/hls.min.js',
        ];
    }

    /**
     * Returns the Mustache template that renders the direct HTML5 video element.
     *
     * @return string Moodle template identifier.
     */
    public function get_player_template(): string {
        return 'videoprogresssource_url/player';
    }

    /**
     * Returns the AMD module that selects the HTML5 or HLS adapter for a direct URL.
     *
     * @return string Moodle AMD module identifier.
     */
    public function get_amd_module(): string {
        return 'videoprogresssource_url/player';
    }

    /**
     * Converts a direct URL stored by an older activity into normalized configuration.
     *
     * @param string $legacyvalue Legacy direct video URL.
     * @return array Normalized URL source configuration.
     */
    protected function get_legacy_config(string $legacyvalue): array {
        $extension = strtolower(pathinfo((string)parse_url($legacyvalue, PHP_URL_PATH), PATHINFO_EXTENSION));
        return ["url" => $legacyvalue, "hls" => $extension === "m3u8"];
    }
}
