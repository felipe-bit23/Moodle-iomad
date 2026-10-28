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
 * @package   videoprogresssource_vimeo
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace videoprogresssource_vimeo;

use coding_exception;
use context_module;
use mod_videoprogress\source\plugin_base;
use moodle_exception;
use MoodleQuickForm;
use stdClass;

/**
 * Implements public and unlisted Vimeo videos as an independently installable video source.
 */
class plugin extends plugin_base {
    /**
     * Returns the localized source name displayed in activity forms.
     *
     * @return string Localized Vimeo source name.
     * @throws coding_exception
     */
    public function get_name(): string {
        return get_string("pluginname", "videoprogresssource_vimeo");
    }

    /**
     * Adds the Vimeo URL field and its source-dependent visibility rule.
     *
     * @param MoodleQuickForm $mform Activity form receiving the Vimeo field.
     * @param string $sourcefield Name of the source selector field.
     * @return void
     * @throws coding_exception
     */
    public function add_form_elements(MoodleQuickForm $mform, string $sourcefield): void {
        $mform->addElement("url", "vimeourl", get_string("videourl", "videoprogresssource_vimeo"),
            ["size" => 80], ["usefilepicker" => false]);
        $mform->setType("vimeourl", PARAM_URL);
        $mform->hideIf("vimeourl", $sourcefield, "neq", "vimeo");
    }

    /**
     * Validates public and unlisted Vimeo URLs including their optional privacy hash.
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
            return ["vimeourl" => $exception->getMessage()];
        }
    }

    /**
     * Extracts the Vimeo identifier and optional unlisted hash from a supported URL.
     *
     * @param stdClass $data Submitted activity data.
     * @return array Normalized Vimeo source configuration.
     * @throws moodle_exception
     */
    public function build_config(stdClass $data): array {
        return self::extract_config(trim((string)($data->vimeourl ?? '')));
    }

    /**
     * Returns the compact Vimeo identifier and hash retained for older backups and integrations.
     *
     * @param array $config Normalized source configuration.
     * @return string Backward-compatible Vimeo value.
     */
    public function get_legacy_value(array $config): string {
        $value = (string)($config["id"] ?? '');
        if (!empty($config["hash"])) {
            $value .= ':' . $config["hash"];
        }
        return $value;
    }

    /**
     * Restores an editable Vimeo URL from the normalized identifier and unlisted hash.
     *
     * @param array $defaultvalues Values passed to the activity form.
     * @param context_module $context Module context used for File API access.
     * @return void
     */
    public function prepare_form_data(array &$defaultvalues, context_module $context): void {
        $config = $this->decode_config((object)$defaultvalues);
        $defaultvalues["vimeourl"] = !empty($config["id"])
            ? 'https://vimeo.com/' . $config["id"] . (!empty($config["hash"]) ? '/' . $config["hash"] : '')
            : '';
    }

    /**
     * Builds the Vimeo identifier, unlisted hash, and local Player API URL used by the adapter.
     *
     * @param stdClass $activity Activity configuration record.
     * @param context_module $context Module context used for File API access.
     * @return array Browser-safe Vimeo player configuration.
     */
    public function get_player_config(stdClass $activity, context_module $context): array {
        global $CFG;

        $config = $this->decode_config($activity);
        return [
            "vimeoid" => (string)($config["id"] ?? ''),
            "vimeohash" => (string)($config["hash"] ?? ''),
            "vimeoplayerurl" => $CFG->wwwroot . '/mod/videoprogress/vendor/vimeo/player.min.js',
        ];
    }

    /**
     * Returns the Mustache template that renders the Vimeo player container.
     *
     * @return string Moodle template identifier.
     */
    public function get_player_template(): string {
        return 'videoprogresssource_vimeo/player';
    }

    /**
     * Returns the AMD module that integrates with the Vimeo Player API.
     *
     * @return string Moodle AMD module identifier.
     */
    public function get_amd_module(): string {
        return 'videoprogresssource_vimeo/player';
    }

    /**
     * Indicates that Vimeo supplies its own poster image.
     *
     * @return bool Always false because custom poster upload is unnecessary.
     */
    public function supports_poster(): bool {
        return false;
    }

    /**
     * Indicates that Vimeo manages caption tracks through its own player.
     *
     * @return bool Always false because local caption upload is not used.
     */
    public function supports_uploaded_captions(): bool {
        return false;
    }

    /**
     * Converts a compact Vimeo value or URL stored by an older activity into normalized configuration.
     *
     * @param string $legacyvalue Legacy Vimeo identifier, identifier and hash, or URL.
     * @return array Normalized Vimeo source configuration.
     * @throws moodle_exception
     */
    protected function get_legacy_config(string $legacyvalue): array {
        if (preg_match('/^(\d+)(?::([A-Za-z0-9]+))?$/', $legacyvalue, $matches)) {
            return ["id" => $matches[1], "hash" => $matches[2] ?? ''];
        }
        return self::extract_config($legacyvalue);
    }

    /**
     * Extracts a Vimeo identifier and optional unlisted hash from canonical and player URLs.
     *
     * @param string $url Vimeo URL supplied by an editor or legacy activity.
     * @return array Normalized Vimeo source configuration.
     * @throws moodle_exception
     */
    private static function extract_config(string $url): array {
        if (!filter_var($url, FILTER_VALIDATE_URL) ||
            !in_array(strtolower((string)parse_url($url, PHP_URL_SCHEME)), ["http", "https"], true)) {
            throw new moodle_exception("invalidurl", "videoprogresssource_vimeo");
        }
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        if (!in_array($host, ['vimeo.com', 'www.vimeo.com', 'player.vimeo.com'], true)) {
            throw new moodle_exception("invalidurl", "videoprogresssource_vimeo");
        }
        $path = trim((string)parse_url($url, PHP_URL_PATH), '/');
        if (!preg_match('~^(?:video/)?(\d+)(?:/([A-Za-z0-9]+))?$~', $path, $matches)) {
            throw new moodle_exception("invalidurl", "videoprogresssource_vimeo");
        }
        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
        $hash = $matches[2] ?? '';
        if ($hash === '' && !empty($query["h"]) && preg_match('/^[A-Za-z0-9]+$/', $query["h"])) {
            $hash = $query["h"];
        }
        return ["id" => $matches[1], "hash" => $hash];
    }
}
