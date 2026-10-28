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
 * @package   videoprogresssource_nextcloud
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace videoprogresssource_nextcloud;

use coding_exception;
use context_module;
use mod_videoprogress\source\plugin_base;
use moodle_exception;
use MoodleQuickForm;
use stdClass;

/**
 * Implements public Nextcloud share links as an independently installable video source.
 */
class plugin extends plugin_base {
    /**
     * Returns the localized source name displayed in activity forms.
     *
     * @return string Localized Nextcloud source name.
     * @throws coding_exception
     */
    public function get_name(): string {
        return get_string("pluginname", "videoprogresssource_nextcloud");
    }

    /**
     * Adds the Nextcloud share URL field and its source-dependent visibility rule.
     *
     * @param MoodleQuickForm $mform Activity form receiving the Nextcloud field.
     * @param string $sourcefield Name of the source selector field.
     * @return void
     * @throws coding_exception
     */
    public function add_form_elements(MoodleQuickForm $mform, string $sourcefield): void {
        $mform->addElement("url", "nextcloudurl", get_string("nextcloudurl", "videoprogresssource_nextcloud"),
            ["size" => 80], ["usefilepicker" => false]);
        $mform->setType("nextcloudurl", PARAM_URL);
        $mform->addHelpButton("nextcloudurl", "nextcloudurl", "videoprogresssource_nextcloud");
        $mform->hideIf("nextcloudurl", $sourcefield, "neq", "nextcloud");
    }

    /**
     * Validates the selected Nextcloud share URL and the remote video response.
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
            return ["nextcloudurl" => $exception->getMessage()];
        }
    }

    /**
     * Converts a public Nextcloud share into a download URL after confirming it is video media.
     *
     * @param stdClass $data Submitted activity data.
     * @return array Normalized Nextcloud source configuration.
     * @throws moodle_exception
     */
    public function build_config(stdClass $data): array {
        $shareurl = trim((string)($data->nextcloudurl ?? ''));
        $downloadurl = $this->to_download_url($shareurl);
        return [
            "shareurl" => $shareurl,
            "url" => $downloadurl,
            "hls" => $this->probe_download($downloadurl),
        ];
    }

    /**
     * Returns the generated download URL retained for backward compatibility with older backups.
     *
     * @param array $config Normalized source configuration.
     * @return string Backward-compatible Nextcloud download URL.
     */
    public function get_legacy_value(array $config): string {
        return (string)($config["url"] ?? '');
    }

    /**
     * Restores the original Nextcloud share URL in the activity edit form.
     *
     * @param array $defaultvalues Values passed to the activity form.
     * @param context_module $context Module context used for File API access.
     * @return void
     */
    public function prepare_form_data(array &$defaultvalues, context_module $context): void {
        $config = $this->decode_config((object)$defaultvalues);
        $defaultvalues["nextcloudurl"] = $config["shareurl"] ?? $config["url"] ?? '';
    }

    /**
     * Builds the download URL and HLS fallback data used by the browser adapter.
     *
     * @param stdClass $activity Activity configuration record.
     * @param context_module $context Module context used for File API access.
     * @return array Browser-safe Nextcloud player configuration.
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
     * Returns the Mustache template that renders the HTML5 video element.
     *
     * @return string Moodle template identifier.
     */
    public function get_player_template(): string {
        return 'videoprogresssource_nextcloud/player';
    }

    /**
     * Returns the AMD module that selects the HTML5 or HLS adapter for a Nextcloud download.
     *
     * @return string Moodle AMD module identifier.
     */
    public function get_amd_module(): string {
        return 'videoprogresssource_nextcloud/player';
    }

    /**
     * Converts a public Nextcloud share URL into the canonical download URL used by the player.
     *
     * Accepted forms:
     * - https://host/s/{token}
     * - https://host/index.php/s/{token}
     * - either form with a trailing /download
     *
     * @param string $shareurl Public Nextcloud share URL supplied by an editor.
     * @return string Canonical Nextcloud download URL.
     * @throws moodle_exception
     */
    public function to_download_url(string $shareurl): string {
        $shareurl = trim($shareurl);
        if (!filter_var($shareurl, FILTER_VALIDATE_URL) ||
            strtolower((string)parse_url($shareurl, PHP_URL_SCHEME)) !== "https") {
            throw new moodle_exception("invalidurl", "videoprogresssource_nextcloud");
        }

        $path = (string)parse_url($shareurl, PHP_URL_PATH);
        if (!preg_match('#^(?:/index\.php)?/s/([^/]+)(?:/download)?/?$#i', $path, $matches)) {
            throw new moodle_exception("invalidurl", "videoprogresssource_nextcloud");
        }

        $host = (string)parse_url($shareurl, PHP_URL_HOST);
        $port = parse_url($shareurl, PHP_URL_PORT);
        $query = (string)parse_url($shareurl, PHP_URL_QUERY);
        $authority = $host . ($port ? ':' . $port : '');
        $downloadurl = 'https://' . $authority . '/index.php/s/' . $matches[1] . '/download';
        if ($query !== '') {
            $downloadurl .= '?' . $query;
        }
        return $downloadurl;
    }

    /**
     * Converts a Nextcloud URL stored by an older activity into normalized configuration.
     *
     * @param string $legacyvalue Legacy Nextcloud share or download URL.
     * @return array Normalized Nextcloud source configuration.
     */
    protected function get_legacy_config(string $legacyvalue): array {
        try {
            return [
                "shareurl" => $legacyvalue,
                "url" => $this->to_download_url($legacyvalue),
                "hls" => false,
            ];
        } catch (moodle_exception $exception) {
            return ["shareurl" => '', "url" => $legacyvalue, "hls" => false];
        }
    }

    /**
     * Confirms that the generated download URL returns video or HLS media.
     *
     * File extensions are not used because Nextcloud download URLs do not contain them.
     *
     * @param string $downloadurl Canonical Nextcloud download URL.
     * @return bool Whether the remote media should use the HLS adapter.
     * @throws moodle_exception
     */
    protected function probe_download(string $downloadurl): bool {
        $probe = $this->fetch_probe($downloadurl);
        $status = (int)($probe["status"] ?? 0);
        if (!in_array($status, [200, 206], true)) {
            throw new moodle_exception("invalidmedia", "videoprogresssource_nextcloud");
        }

        $contenttype = $this->normalise_content_type((string)($probe["contenttype"] ?? ''));
        if ($this->is_hls_content_type($contenttype)) {
            return true;
        }
        if ($this->is_html5_video_content_type($contenttype)) {
            return false;
        }
        throw new moodle_exception("invalidcontenttype", "videoprogresssource_nextcloud");
    }

    /**
     * Sends a HEAD request, falling back to a ranged GET when HEAD is unsupported.
     *
     * @param string $downloadurl Canonical Nextcloud download URL.
     * @return array Status, content type, and Accept-Ranges values.
     */
    protected function fetch_probe(string $downloadurl): array {
        $probe = $this->request_probe($downloadurl, true);
        $status = (int)($probe["status"] ?? 0);
        if (in_array($status, [0, 405, 501], true)) {
            $probe = $this->request_probe($downloadurl, false);
        }
        return $probe;
    }

    /**
     * Performs the remote probe used to inspect Nextcloud media headers.
     *
     * @param string $downloadurl Canonical Nextcloud download URL.
     * @param bool $head Whether to send HEAD instead of a ranged GET.
     * @return array Status, content type, and Accept-Ranges values.
     */
    private function request_probe(string $downloadurl, bool $head): array {
        $curl = new \curl();
        $options = [
            "CURLOPT_CONNECTTIMEOUT" => 10,
            "CURLOPT_TIMEOUT" => 20,
            "CURLOPT_FOLLOWLOCATION" => 1,
            "CURLOPT_MAXREDIRS" => 5,
        ];
        if ($head) {
            $raw = $curl->head($downloadurl, $options);
        } else {
            $curl->setHeader(["Range: bytes=0-0"]);
            $options["CURLOPT_HEADER"] = 1;
            $raw = $curl->get($downloadurl, [], $options);
        }

        $info = $curl->get_info() ?: [];
        $headers = $curl->getResponse();
        $contenttype = (string)($info["content_type"] ?? $this->header_value($headers, "Content-Type"));
        if ($contenttype === '' && is_string($raw)) {
            $contenttype = $this->extract_header_from_raw($raw, "content-type");
        }

        return [
            "status" => (int)($info["http_code"] ?? 0),
            "contenttype" => $contenttype,
            "acceptranges" => $this->header_value($headers, "Accept-Ranges")
                ?: (is_string($raw) ? $this->extract_header_from_raw($raw, "accept-ranges") : ''),
        ];
    }

    /**
     * Returns a single HTTP header value from Moodle curl's parsed response map.
     *
     * @param array $headers Parsed response headers.
     * @param string $name Header name to locate.
     * @return string Header value or an empty string when absent.
     */
    private function header_value(array $headers, string $name): string {
        foreach ($headers as $key => $value) {
            if (strcasecmp((string)$key, $name) !== 0) {
                continue;
            }
            if (is_array($value)) {
                $value = end($value);
            }
            return trim((string)$value);
        }
        return '';
    }

    /**
     * Extracts a header value from a raw HTTP response string.
     *
     * @param string $raw Raw HTTP response including headers.
     * @param string $name Lowercase header name.
     * @return string Header value or an empty string when absent.
     */
    private function extract_header_from_raw(string $raw, string $name): string {
        if (!preg_match('/^' . preg_quote($name, '/') . ':\s*(.+)$/im', $raw, $matches)) {
            return '';
        }
        return trim($matches[1]);
    }

    /**
     * Strips content-type parameters so media comparisons use only the MIME type.
     *
     * @param string $contenttype Raw Content-Type header.
     * @return string Lowercase MIME type without parameters.
     */
    private function normalise_content_type(string $contenttype): string {
        return strtolower(trim(explode(';', $contenttype, 2)[0]));
    }

    /**
     * Determines whether the remote media is an HLS playlist.
     *
     * @param string $contenttype Normalized MIME type.
     * @return bool Whether the HLS adapter should be used.
     */
    private function is_hls_content_type(string $contenttype): bool {
        return in_array($contenttype, [
            'application/vnd.apple.mpegurl',
            'application/x-mpegurl',
            'application/mpegurl',
            'audio/mpegurl',
            'audio/x-mpegurl',
        ], true);
    }

    /**
     * Determines whether the remote media can play in the HTML5 video element.
     *
     * Nextcloud download URLs do not include .mp4/.webm/.ogv/.mov/.m4v extensions,
     * so MP4, WebM, OGV, MOV, and M4V are accepted by MIME type instead.
     *
     * @param string $contenttype Normalized MIME type.
     * @return bool Whether the HTML5 adapter should be used.
     */
    private function is_html5_video_content_type(string $contenttype): bool {
        return str_starts_with($contenttype, "video/") || $contenttype === 'application/octet-stream';
    }
}
