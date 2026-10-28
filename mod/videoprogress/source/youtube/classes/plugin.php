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
 * @package   videoprogresssource_youtube
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace videoprogresssource_youtube;

use coding_exception;
use context_module;
use dml_exception;
use mod_videoprogress\source\plugin_base;
use moodle_exception;
use MoodleQuickForm;
use stdClass;

/**
 * Implements YouTube IFrame Player API videos as an independently installable video source.
 */
class plugin extends plugin_base {
    /**
     * Returns the localized source name displayed in activity forms.
     *
     * @return string Localized YouTube source name.
     * @throws coding_exception
     */
    public function get_name(): string {
        return get_string("pluginname", "videoprogresssource_youtube");
    }

    /**
     * Adds the YouTube URL field and its source-dependent visibility rule.
     *
     * @param MoodleQuickForm $mform Activity form receiving the YouTube field.
     * @param string $sourcefield Name of the source selector field.
     * @return void
     * @throws coding_exception
     */
    public function add_form_elements(MoodleQuickForm $mform, string $sourcefield): void {
        $mform->addElement("url", "youtubeurl", get_string("videourl", "videoprogresssource_youtube"),
            ["size" => 80], ["usefilepicker" => false]);
        $mform->setType("youtubeurl", PARAM_URL);
        $mform->hideIf("youtubeurl", $sourcefield, "neq", "youtube");
    }

    /**
     * Validates and extracts a video identifier from a supported YouTube URL form.
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
            return ["youtubeurl" => $exception->getMessage()];
        }
    }

    /**
     * Extracts and stores the normalized identifier from supported YouTube URL formats.
     *
     * @param stdClass $data Submitted activity data.
     * @return array Normalized YouTube source configuration.
     * @throws moodle_exception
     */
    public function build_config(stdClass $data): array {
        return ["id" => self::extract_id(trim((string)($data->youtubeurl ?? '')))];
    }

    /**
     * Returns the normalized YouTube identifier retained for older backups and integrations.
     *
     * @param array $config Normalized source configuration.
     * @return string Backward-compatible YouTube identifier.
     */
    public function get_legacy_value(array $config): string {
        return (string)($config["id"] ?? '');
    }

    /**
     * Restores an editable watch URL from the normalized YouTube identifier.
     *
     * @param array $defaultvalues Values passed to the activity form.
     * @param context_module $context Module context used for File API access.
     * @return void
     */
    public function prepare_form_data(array &$defaultvalues, context_module $context): void {
        $config = $this->decode_config((object)$defaultvalues);
        $defaultvalues["youtubeurl"] = !empty($config["id"])
            ? 'https://www.youtube.com/watch?v=' . $config["id"]
            : '';
    }

    /**
     * Builds the identifier and privacy-enhanced host used by the YouTube adapter.
     *
     * @param stdClass $activity Activity configuration record.
     * @param context_module $context Module context used for File API access.
     * @return array Browser-safe YouTube player configuration.
     * @throws dml_exception
     */
    public function get_player_config(stdClass $activity, context_module $context): array {
        $config = $this->decode_config($activity);
        return [
            "youtubeid" => (string)($config["id"] ?? ''),
            "youtubehost" => get_config("videoprogresssource_youtube", "youtubenocookie")
                ? 'https://www.youtube-nocookie.com'
                : 'https://www.youtube.com',
        ];
    }

    /**
     * Returns the Mustache template that renders the YouTube player container.
     *
     * @return string Moodle template identifier.
     */
    public function get_player_template(): string {
        return 'videoprogresssource_youtube/player';
    }

    /**
     * Returns the AMD module that integrates with the YouTube IFrame Player API.
     *
     * @return string Moodle AMD module identifier.
     */
    public function get_amd_module(): string {
        return 'videoprogresssource_youtube/player';
    }

    /**
     * Indicates that YouTube supplies its own poster image.
     *
     * @return bool Always false because custom poster upload is unnecessary.
     */
    public function supports_poster(): bool {
        return false;
    }

    /**
     * Indicates that YouTube manages caption tracks through its own player.
     *
     * @return bool Always false because local caption upload is not used.
     */
    public function supports_uploaded_captions(): bool {
        return false;
    }

    /**
     * Converts a YouTube identifier or URL stored by an older activity into normalized configuration.
     *
     * @param string $legacyvalue Legacy YouTube identifier or URL.
     * @return array Normalized YouTube source configuration.
     * @throws moodle_exception
     */
    protected function get_legacy_config(string $legacyvalue): array {
        if (preg_match('/^[A-Za-z0-9_-]{6,20}$/', $legacyvalue)) {
            return ["id" => $legacyvalue];
        }
        return ["id" => self::extract_id($legacyvalue)];
    }

    /**
     * Extracts a normalized YouTube identifier from watch, short, embed, shorts, and nocookie URLs.
     *
     * @param string $url YouTube URL supplied by an editor or legacy activity.
     * @return string Normalized YouTube identifier.
     * @throws moodle_exception
     */
    private static function extract_id(string $url): string {
        if (!filter_var($url, FILTER_VALIDATE_URL) ||
            !in_array(strtolower((string)parse_url($url, PHP_URL_SCHEME)), ["http", "https"], true)) {
            throw new moodle_exception("invalidurl", "videoprogresssource_youtube");
        }
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        $path = trim((string)parse_url($url, PHP_URL_PATH), '/');
        $id = '';
        if (in_array($host, ['youtu.be', 'www.youtu.be'], true)) {
            $id = explode('/', $path)[0] ?? '';
        } else if (in_array($host, [
            'youtube.com', 'www.youtube.com', 'm.youtube.com',
            'youtube-nocookie.com', 'www.youtube-nocookie.com',
        ], true)) {
            parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
            $id = (string)($query["v"] ?? '');
            if ($id === '' && preg_match('~(?:embed|shorts)/([A-Za-z0-9_-]{6,20})~', $path, $matches)) {
                $id = $matches[1];
            }
        }
        if (!preg_match('/^[A-Za-z0-9_-]{6,20}$/', $id)) {
            throw new moodle_exception("invalidurl", "videoprogresssource_youtube");
        }
        return $id;
    }
}
