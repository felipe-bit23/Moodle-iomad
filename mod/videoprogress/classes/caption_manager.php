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
 * caption_manager.php
 *
 * @package   mod_videoprogress
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_videoprogress;

use coding_exception;
use context_module;
use context_user;
use dml_exception;
use dml_transaction_exception;
use file_exception;
use moodle_exception;
use moodle_url;
use stdClass;
use stored_file_creation_exception;
use videoprogresssource_nextcloud\plugin as nextcloud_plugin;

/**
 * Coordinates caption upload, validation, review, publication, transcription, and translation.
 */
class caption_manager {
    /**
     * Canonical BCP 47 language tags offered for caption tracks, mapped to English display names.
     *
     * @var string[]
     */
    public const LANGUAGES = [
        "af-ZA" => "Afrikaans (South Africa)",
        "am-ET" => "Amharic (Ethiopia)",
        "ar-SA" => "Arabic (Saudi Arabia)",
        "az-AZ" => "Azerbaijani (Azerbaijan)",
        "be-BY" => "Belarusian (Belarus)",
        "bg-BG" => "Bulgarian (Bulgaria)",
        "bn-IN" => "Bengali (India)",
        "bs-BA" => "Bosnian (Bosnia and Herzegovina)",
        "ca-ES" => "Catalan (Spain)",
        "cs-CZ" => "Czech (Czech Republic)",
        "cy-GB" => "Welsh (United Kingdom)",
        "da-DK" => "Danish (Denmark)",
        "de-AT" => "German (Austria)",
        "de-CH" => "German (Switzerland)",
        "de-DE" => "German (Germany)",
        "el-GR" => "Greek (Greece)",
        "en-AU" => "English (Australia)",
        "en-CA" => "English (Canada)",
        "en-GB" => "English (United Kingdom)",
        "en-IE" => "English (Ireland)",
        "en-IN" => "English (India)",
        "en-NZ" => "English (New Zealand)",
        "en-US" => "English (United States)",
        "en-ZA" => "English (South Africa)",
        "es-AR" => "Spanish (Argentina)",
        "es-CL" => "Spanish (Chile)",
        "es-CO" => "Spanish (Colombia)",
        "es-ES" => "Spanish (Spain)",
        "es-MX" => "Spanish (Mexico)",
        "es-PE" => "Spanish (Peru)",
        "et-EE" => "Estonian (Estonia)",
        "fa-IR" => "Persian (Iran)",
        "fi-FI" => "Finnish (Finland)",
        "fil-PH" => "Filipino (Philippines)",
        "fr-BE" => "French (Belgium)",
        "fr-CA" => "French (Canada)",
        "fr-CH" => "French (Switzerland)",
        "fr-FR" => "French (France)",
        "ga-IE" => "Irish (Ireland)",
        "gl-ES" => "Galician (Spain)",
        "gu-IN" => "Gujarati (India)",
        "he-IL" => "Hebrew (Israel)",
        "hi-IN" => "Hindi (India)",
        "hr-HR" => "Croatian (Croatia)",
        "hu-HU" => "Hungarian (Hungary)",
        "id-ID" => "Indonesian (Indonesia)",
        "is-IS" => "Icelandic (Iceland)",
        "it-CH" => "Italian (Switzerland)",
        "it-IT" => "Italian (Italy)",
        "ja-JP" => "Japanese (Japan)",
        "jv-ID" => "Javanese (Indonesia)",
        "km-KH" => "Khmer (Cambodia)",
        "kn-IN" => "Kannada (India)",
        "ko-KR" => "Korean (South Korea)",
        "lo-LA" => "Lao (Laos)",
        "lt-LT" => "Lithuanian (Lithuania)",
        "lv-LV" => "Latvian (Latvia)",
        "ml-IN" => "Malayalam (India)",
        "mr-IN" => "Marathi (India)",
        "ms-MY" => "Malay (Malaysia)",
        "nb-NO" => "Norwegian Bokmål (Norway)",
        "ne-NP" => "Nepali (Nepal)",
        "nl-BE" => "Dutch (Belgium)",
        "nl-NL" => "Dutch (Netherlands)",
        "no-NO" => "Norwegian (Norway)",
        "pa-IN" => "Punjabi (India)",
        "pl-PL" => "Polish (Poland)",
        "pt-BR" => "Portuguese (Brazil)",
        "pt-PT" => "Portuguese (Portugal)",
        "ro-RO" => "Romanian (Romania)",
        "ru-RU" => "Russian (Russia)",
        "si-LK" => "Sinhala (Sri Lanka)",
        "sk-SK" => "Slovak (Slovakia)",
        "sl-SI" => "Slovenian (Slovenia)",
        "sq-AL" => "Albanian (Albania)",
        "sr-RS" => "Serbian (Serbia)",
        "sv-FI" => "Swedish (Finland)",
        "sv-SE" => "Swedish (Sweden)",
        "sw-KE" => "Swahili (Kenya)",
        "ta-IN" => "Tamil (India)",
        "te-IN" => "Telugu (India)",
        "th-TH" => "Thai (Thailand)",
        "tr-TR" => "Turkish (Turkey)",
        "uk-UA" => "Ukrainian (Ukraine)",
        "ur-PK" => "Urdu (Pakistan)",
        "vi-VN" => "Vietnamese (Vietnam)",
        "zh-CN" => "Chinese (China)",
        "zh-HK" => "Chinese (Hong Kong)",
        "zh-SG" => "Chinese (Singapore)",
        "zh-TW" => "Chinese (Taiwan)",
        "zu-ZA" => "Zulu (South Africa)",
    ];

    /** @var int Maximum remote or uploaded caption size in bytes. */
    public const MAX_BYTES = 5242880;

    /**
     * Returns the localized caption language menu used by activity and management forms.
     *
     * @return array Language tags mapped to localized display names.
     * @throws coding_exception
     */
    public static function get_language_options(): array {
        $options = [];
        foreach (array_keys(self::LANGUAGES) as $code) {
            $options[$code] = self::get_language_label($code);
        }
        \core_collator::asort($options);
        return $options;
    }

    /**
     * Returns the human-readable language name shown to managers and in the player menu.
     *
     * @param string $code BCP 47 language tag.
     * @return string Localized language name, or the English name when a translation is missing.
     */
    public static function get_language_label(string $code): string {
        try {
            $code = self::normalise_language($code);
        } catch (moodle_exception $exception) {
            $code = trim($code);
        }
        $stringid = "captionlang_" . strtolower(str_replace('-', '_', $code));
        if (get_string_manager()->string_exists($stringid, "videoprogress")) {
            return get_string($stringid, "videoprogress");
        }
        return self::LANGUAGES[$code] ?? $code;
    }

    /**
     * Returns the canonical language tag from the supported caption list.
     *
     * @param string $code Submitted or stored language tag.
     * @return string Canonical BCP 47 language tag.
     * @throws moodle_exception
     */
    public static function normalise_language(string $code): string {
        $code = trim($code);
        foreach (array_keys(self::LANGUAGES) as $canonical) {
            if (strcasecmp($canonical, $code) === 0) {
                return $canonical;
            }
        }
        throw new moodle_exception("captioninvalidlanguage", "videoprogress");
    }

    /**
     * Returns caption source options shown when adding a WebVTT track.
     *
     * @return array Source short names mapped to localized labels.
     * @throws coding_exception
     */
    public static function get_source_options(): array {
        return [
            "upload" => get_string("captionsourceupload", "videoprogress"),
            "url" => get_string("captionsourceurl", "videoprogress"),
            "nextcloud" => get_string("captionsourcenextcloud", "videoprogress"),
        ];
    }

    /**
     * Imports uploaded caption files into published caption records.
     *
     * @param int $draftitemid draftitemid value used by the operation.
     * @param int $activityid Video Progress activity identifier.
     * @param context_module $context Module context used for permissions and File API access.
     * @param int $userid Target user identifier.
     * @return int The resolved identifier or numeric value.
     * @throws coding_exception
     * @throws dml_exception
     * @throws dml_transaction_exception
     * @throws file_exception
     * @throws moodle_exception
     * @throws stored_file_creation_exception
     */
    public function import_draft_files(int $draftitemid, int $activityid, context_module $context, int $userid): int {
        global $DB;
        $fs = get_file_storage();
        $files = $fs->get_area_files(context_user::instance($userid)->id, "user", "draft", $draftitemid, "filename", false);
        $imported = 0;
        $transaction = $DB->start_delegated_transaction();
        foreach ($files as $file) {
            $extension = strtolower(pathinfo($file->get_filename(), PATHINFO_EXTENSION));
            if (!in_array($extension, ["vtt", "srt"], true)) {
                continue;
            }
            $content = $extension === "srt" ? self::convert_srt_to_vtt($file->get_content()) : $file->get_content();
            self::validate_webvtt($content);
            $basename = pathinfo($file->get_filename(), PATHINFO_FILENAME);
            $language = self::language_from_filename($basename) ?? "en-US";
            $now = time();
            $record = (object)[
                "videoprogressid" => $activityid,
                "language" => $language,
                "label" => self::get_language_label($language),
                "isdefault" =>
                    $imported === 0 && !$DB->record_exists("videoprogress_captions", ["videoprogressid" => $activityid]) ? 1 : 0,
                "status" => "published",
                "source" => "upload",
                "createdby" => $userid,
                "timecreated" => $now,
                "timemodified" => $now,
            ];
            $record->id = $DB->insert_record("videoprogress_captions", $record);
            $fs->create_file_from_string([
                "contextid" => $context->id, "component" => "mod_videoprogress", "filearea" => "caption",
                "itemid" => $record->id, "filepath" => '/', "filename" => clean_filename($basename . '.vtt'),
            ], $content);
            $imported++;
        }
        $transaction->allow_commit();
        return $imported;
    }

    /**
     * Saves every completed caption row submitted by the activity form.
     *
     * @param stdClass $data Submitted activity data including repeated caption fields.
     * @param context_module $context Module context used for File API access.
     * @param int $userid User storing the tracks.
     * @return int Number of caption tracks created.
     * @throws coding_exception
     * @throws dml_exception
     * @throws moodle_exception
     */
    public function save_form_tracks(stdClass $data, context_module $context, int $userid): int {
        $saved = 0;
        $repeats = (int)($data->caption_repeats ?? 0);
        for ($index = 0; $index < $repeats; $index++) {
            $row = $this->extract_form_row($data, $index);
            if ($this->is_empty_row($row, $userid)) {
                continue;
            }
            $this->save((int)$data->id, $context, $row, $userid);
            $saved++;
        }
        return $saved;
    }

    /**
     * Validates and stores one caption track from upload, a direct URL, or a Nextcloud share.
     *
     * @param int $activityid Video Progress activity identifier.
     * @param context_module $context Module context used for permissions and File API access.
     * @param stdClass $data Validated caption form values.
     * @param int $userid Target user identifier.
     * @return int Created caption identifier.
     * @throws coding_exception
     * @throws dml_exception
     * @throws moodle_exception
     */
    public function save(int $activityid, context_module $context, stdClass $data, int $userid): int {
        $data->language = self::normalise_language((string)($data->language ?? ''));
        $data->label = self::get_language_label($data->language);
        $data->status = $data->status ?: "published";
        $source = clean_param((string)($data->captionsource ?? "upload"), PARAM_ALPHA);
        return match ($source) {
            "url" => $this->save_remote($activityid, $context, $data, $userid, "url"),
            "nextcloud" => $this->save_remote($activityid, $context, $data, $userid, "nextcloud"),
            default => $this->save_upload($activityid, $context, $data, $userid),
        };
    }

    /**
     * Validates and saves an uploaded caption as a reviewable track.
     *
     * @param int $activityid Video Progress activity identifier.
     * @param context_module $context Module context used for permissions and File API access.
     * @param stdClass $data Validated input or tracking data.
     * @param int $userid Target user identifier.
     * @return int The resolved identifier or numeric value.
     * @throws coding_exception
     * @throws dml_exception
     * @throws dml_transaction_exception
     * @throws file_exception
     * @throws moodle_exception
     * @throws stored_file_creation_exception
     */
    public function save_upload(int $activityid, context_module $context, stdClass $data, int $userid): int {
        $fs = get_file_storage();
        $draftfiles = $fs->get_area_files(context_user::instance($userid)->id, "user", "draft", $data->captionfile, "id", false);
        if (!$draftfiles) {
            throw new moodle_exception("captionfilemissing", "videoprogress");
        }
        $draft = reset($draftfiles);
        $content = $draft->get_content();
        if (strtolower(pathinfo($draft->get_filename(), PATHINFO_EXTENSION)) === "srt") {
            $content = self::convert_srt_to_vtt($content);
        }
        self::validate_webvtt($content);
        $filename = clean_filename(pathinfo($draft->get_filename(), PATHINFO_FILENAME) . '.vtt');
        return $this->store_track($activityid, $context, $data, $userid, "upload", $content, $filename);
    }

    /**
     * Validates and saves edited WebVTT content for a caption track.
     *
     * @param stdClass $caption caption value used by the operation.
     * @param context_module $context Module context used for permissions and File API access.
     * @param stdClass $data Validated input or tracking data.
     * @return void This method does not return a value.
     * @throws coding_exception
     * @throws dml_exception
     * @throws dml_transaction_exception
     * @throws file_exception
     * @throws moodle_exception
     * @throws stored_file_creation_exception
     */
    public function update_content(stdClass $caption, context_module $context, stdClass $data): void {
        global $DB;
        self::validate_webvtt($data->content);
        $transaction = $DB->start_delegated_transaction();
        if (!empty($data->isdefault)) {
            $DB->set_field("videoprogress_captions", "isdefault", 0, ["videoprogressid" => $caption->videoprogressid]);
        }
        $caption->language = self::normalise_language((string)$data->language);
        $caption->label = self::get_language_label($caption->language);
        $caption->isdefault = !empty($data->isdefault) ? 1 : 0;
        $caption->status = $data->status;
        if (property_exists($caption, "sourceurl") && isset($data->sourceurl)) {
            $caption->sourceurl = $data->sourceurl;
        }
        $caption->timemodified = time();
        $DB->update_record("videoprogress_captions", $caption);
        $fs = get_file_storage();
        $files = $fs->get_area_files($context->id, "mod_videoprogress", "caption", $caption->id, "id", false);
        $filename = $files ? reset($files)->get_filename() : 'caption-' . $caption->id . '.vtt';
        $fs->delete_area_files($context->id, "mod_videoprogress", "caption", $caption->id);
        $fs->create_file_from_string([
            "contextid" => $context->id, "component" => "mod_videoprogress", "filearea" => "caption",
            "itemid" => $caption->id, "filepath" => '/', "filename" => $filename,
        ], $data->content);
        $transaction->allow_commit();
    }

    /**
     * Updates an existing caption, optionally replacing a remote URL and its downloaded WebVTT file.
     *
     * @param stdClass $caption Stored caption record.
     * @param context_module $context Module context used for File API access.
     * @param stdClass $data Submitted editor values.
     * @return void
     * @throws coding_exception
     * @throws dml_exception
     * @throws moodle_exception
     */
    public function update(stdClass $caption, context_module $context, stdClass $data): void {
        $sourceurl = $this->submitted_source_url((string)$caption->source, $data);
        if ($sourceurl !== '' && $sourceurl !== trim((string)($caption->sourceurl ?? ''))) {
            $downloadurl = $caption->source === "nextcloud"
                ? $this->to_nextcloud_download_url($sourceurl)
                : $sourceurl;
            $data->content = $this->normalise_remote_caption($downloadurl);
            $data->sourceurl = $sourceurl;
        }
        $this->update_content($caption, $context, $data);
    }

    /**
     * Publishes or hides a caption track without deleting it.
     *
     * Hidden tracks stay stored but are not attached to the player.
     *
     * @param int $captionid Caption record identifier.
     * @param int $activityid Video Progress activity identifier.
     * @return string New status value.
     * @throws dml_exception
     */
    public function toggle_visibility(int $captionid, int $activityid): string {
        global $DB;

        $conditions = ["id" => $captionid, "videoprogressid" => $activityid];
        $caption = $DB->get_record("videoprogress_captions", $conditions, "*", MUST_EXIST);
        $caption->status = $caption->status === "published" ? "draft" : "published";
        $caption->timemodified = time();
        $DB->update_record("videoprogress_captions", $caption);
        return $caption->status;
    }

    /**
     * Returns caption tracks for the management list, including edit, toggle, and delete actions.
     *
     * @param int $activityid Video Progress activity identifier.
     * @param int $cmid Course module identifier.
     * @return array Caption list entries for Mustache templates.
     * @throws coding_exception
     * @throws dml_exception
     * @throws moodle_exception
     */
    public function get_management_tracks(int $activityid, int $cmid): array {
        global $DB;

        $tracks = [];
        $actionurl = (new moodle_url('/mod/videoprogress/captions.php', ["id" => $cmid]))->out(false);
        $records = $DB->get_records("videoprogress_captions", ["videoprogressid" => $activityid], 'language,label');
        foreach ($records as $caption) {
            $published = $caption->status === "published";
            $sourceurl = trim((string)($caption->sourceurl ?? ''));
            $tracks[] = [
                "id" => $caption->id,
                "language" => s($caption->language),
                "label" => s(self::get_language_label((string)$caption->language)),
                "isdefault" => (bool)$caption->isdefault,
                "isvisible" => $published,
                "status" => get_string("captionstatus{$caption->status}", "videoprogress"),
                "source" => get_string("captionsource{$caption->source}", "videoprogress"),
                "sourceurl" => s($sourceurl),
                "hassourceurl" => $sourceurl !== '',
                "editurl" => (new moodle_url('/mod/videoprogress/captions/edit.php', [
                    "id" => $cmid,
                    "captionid" => $caption->id,
                ]))->out(false),
                "togglelabel" => get_string($published ? "captionhide" : "captionshow", "videoprogress"),
                "toggleurl" => (new moodle_url('/mod/videoprogress/captions.php', [
                    "id" => $cmid,
                    "action" => "toggle",
                    "captionid" => $caption->id,
                    "sesskey" => sesskey(),
                ]))->out(false),
                "deleteurl" => (new moodle_url('/mod/videoprogress/captions.php', [
                    "id" => $cmid,
                    "action" => "delete",
                    "captionid" => $caption->id,
                    "sesskey" => sesskey(),
                ]))->out(false),
                "actionurl" => $actionurl,
                "cmid" => $cmid,
                "sesskey" => sesskey(),
            ];
        }
        return $tracks;
    }

    /**
     * Deletes a caption record and its associated protected WebVTT file.
     *
     * @param int $captionid Caption record identifier.
     * @param int $activityid Video Progress activity identifier.
     * @param context_module $context Module context used for permissions and File API access.
     * @return void This method does not return a value.
     * @throws dml_exception
     * @throws dml_transaction_exception
     */
    public function delete(int $captionid, int $activityid, context_module $context): void {
        global $DB;
        $conditions = ["id" => $captionid, "videoprogressid" => $activityid];
        $caption = $DB->get_record("videoprogress_captions", $conditions, "*", MUST_EXIST);
        $transaction = $DB->start_delegated_transaction();
        get_file_storage()->delete_area_files($context->id, "mod_videoprogress", "caption", $caption->id);
        $DB->delete_records("videoprogress_captions", ["id" => $caption->id]);
        $transaction->allow_commit();
    }

    /**
     * Returns published caption tracks with protected File API URLs.
     *
     * @param int $activityid Video Progress activity identifier.
     * @param context_module $context Module context used for permissions and File API access.
     * @return array Structured data produced by the operation.
     * @throws coding_exception
     * @throws dml_exception
     */
    public function get_published_tracks(int $activityid, context_module $context): array {
        global $DB;
        $tracks = [];
        $conditions = ["videoprogressid" => $activityid, "status" => "published"];
        $captions = $DB->get_records("videoprogress_captions", $conditions, 'isdefault DESC,language');
        $fs = get_file_storage();
        foreach ($captions as $caption) {
            $files = $fs->get_area_files($context->id, "mod_videoprogress", "caption", $caption->id, "filename", false);
            if (!$files) {
                continue;
            }
            $file = reset($files);
            $tracks[] = [
                "id" => (int)$caption->id,
                "language" => s($caption->language),
                "label" => s(self::get_language_label((string)$caption->language)),
                "isdefault" => (bool)$caption->isdefault,
                "url" => moodle_url::make_pluginfile_url($context->id, "mod_videoprogress", "caption", $caption->id,
                    $file->get_filepath(), $file->get_filename())->out(false),
            ];
        }
        return $tracks;
    }

    /**
     * Checks whether caption content has a valid WebVTT header and cue timing structure.
     *
     * @param string $content Caption or editor content.
     * @return void This method does not return a value.
     * @throws moodle_exception
     */
    public static function validate_webvtt(string $content): void {
        $normalised = preg_replace('/^\xEF\xBB\xBF/', '', trim($content));
        if (!str_starts_with($normalised, "WEBVTT") ||
            !preg_match('/\d{2}:\d{2}(?::\d{2})?\.\d{3}\s+-->\s+\d{2}:\d{2}(?::\d{2})?\.\d{3}/', $normalised)) {
            throw new moodle_exception("invalidwebvtt", "videoprogress");
        }
    }

    /**
     * Converts valid SRT caption content into WebVTT format while preserving timestamps.
     *
     * @param string $content Caption or editor content.
     * @return string The resolved or formatted string value.
     */
    public static function convert_srt_to_vtt(string $content): string {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        $content = preg_replace_callback(
            '/(\d{2}:\d{2}:\d{2}),(\d{3})\s+-->\s+(\d{2}:\d{2}:\d{2}),(\d{3})/',
            static fn(array $matches): string => $matches[1] . '.' . $matches[2] . ' --> ' . $matches[3] . '.' . $matches[4],
            $content
        );
        return "WEBVTT\n\n" . trim($content) . "\n";
    }

    /**
     * Converts a public Nextcloud share into the canonical download URL used to fetch a WebVTT file.
     *
     * @param string $shareurl Public Nextcloud share URL.
     * @return string Canonical Nextcloud download URL.
     * @throws moodle_exception
     */
    public function to_nextcloud_download_url(string $shareurl): string {
        if (!class_exists(nextcloud_plugin::class)) {
            throw new moodle_exception("unavailableplugin", "videoprogress", '', "nextcloud");
        }
        return (new nextcloud_plugin())->to_download_url($shareurl);
    }

    /**
     * Validates a direct caption URL that must point at a WebVTT or SRT file.
     *
     * @param string $url Direct caption URL.
     * @return string Trimmed HTTP or HTTPS caption URL.
     * @throws moodle_exception
     */
    public function validate_direct_caption_url(string $url): string {
        $url = trim($url);
        if (!filter_var($url, FILTER_VALIDATE_URL) ||
            !in_array(strtolower((string)parse_url($url, PHP_URL_SCHEME)), ["http", "https"], true)) {
            throw new moodle_exception("captionurlinvalid", "videoprogress");
        }
        $extension = strtolower(pathinfo((string)parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
        if (!in_array($extension, ["vtt", "srt"], true)) {
            throw new moodle_exception("captionurlinvalid", "videoprogress");
        }
        return $url;
    }

    /**
     * Downloads remote caption text for validation and protected storage.
     *
     * @param string $url Remote caption URL.
     * @return string Downloaded caption body.
     * @throws moodle_exception
     */
    protected function fetch_remote_content(string $url): string {
        $curl = new \curl();
        $content = $curl->get($url, [], [
            "CURLOPT_CONNECTTIMEOUT" => 10,
            "CURLOPT_TIMEOUT" => 20,
            "CURLOPT_FOLLOWLOCATION" => 1,
            "CURLOPT_MAXREDIRS" => 5,
        ]);
        $info = $curl->get_info() ?: [];
        $status = (int)($info["http_code"] ?? 0);
        if ($status !== 200 || !is_string($content) || $content === '') {
            throw new moodle_exception("captionmedia", "videoprogress");
        }
        if (strlen($content) > self::MAX_BYTES) {
            throw new moodle_exception("captionmedia", "videoprogress");
        }
        return $content;
    }

    /**
     * Fetches a remote WebVTT file and stores it as a protected caption track.
     *
     * @param int $activityid Video Progress activity identifier.
     * @param context_module $context Module context used for File API access.
     * @param stdClass $data Submitted caption values.
     * @param int $userid User storing the track.
     * @param string $source Caption source short name.
     * @return int Created caption identifier.
     * @throws coding_exception
     * @throws dml_exception
     * @throws moodle_exception
     */
    protected function save_remote(
        int $activityid,
        context_module $context,
        stdClass $data,
        int $userid,
        string $source
    ): int {
        $url = $source === "nextcloud"
            ? $this->to_nextcloud_download_url(trim((string)($data->captionnextcloudurl ?? '')))
            : $this->validate_direct_caption_url((string)($data->captionurl ?? ''));
        $content = $this->normalise_remote_caption($url);
        $data->sourceurl = $source === "nextcloud"
            ? trim((string)($data->captionnextcloudurl ?? ''))
            : trim((string)($data->captionurl ?? ''));
        $filename = clean_filename('caption-' . $data->language . '.vtt');
        return $this->store_track($activityid, $context, $data, $userid, $source, $content, $filename);
    }

    /**
     * Persists caption metadata and the protected WebVTT file.
     *
     * @param int $activityid Video Progress activity identifier.
     * @param context_module $context Module context used for File API access.
     * @param stdClass $data Submitted caption values.
     * @param int $userid User storing the track.
     * @param string $source Caption source short name.
     * @param string $content Validated WebVTT content.
     * @param string $filename Protected file name.
     * @return int Created caption identifier.
     * @throws dml_exception
     * @throws dml_transaction_exception
     * @throws file_exception
     * @throws stored_file_creation_exception
     */
    protected function store_track(
        int $activityid,
        context_module $context,
        stdClass $data,
        int $userid,
        string $source,
        string $content,
        string $filename
    ): int {
        global $DB;

        $transaction = $DB->start_delegated_transaction();
        if (!empty($data->isdefault)) {
            $DB->set_field("videoprogress_captions", "isdefault", 0, ["videoprogressid" => $activityid]);
        } else if (!$DB->record_exists("videoprogress_captions", ["videoprogressid" => $activityid])) {
            $data->isdefault = 1;
        }
        $now = time();
        $record = (object)[
            "videoprogressid" => $activityid,
            "language" => $data->language,
            "label" => $data->label,
            "isdefault" => !empty($data->isdefault) ? 1 : 0,
            "status" => $data->status,
            "source" => $source,
            "createdby" => $userid,
            "timecreated" => $now,
            "timemodified" => $now,
        ];
        if ($DB->get_manager()->field_exists("videoprogress_captions", "sourceurl")) {
            $record->sourceurl = (string)($data->sourceurl ?? '');
        }
        $record->id = $DB->insert_record("videoprogress_captions", $record);
        get_file_storage()->create_file_from_string([
            "contextid" => $context->id,
            "component" => "mod_videoprogress",
            "filearea" => "caption",
            "itemid" => $record->id,
            "filepath" => '/',
            "filename" => $filename,
        ], $content);
        $transaction->allow_commit();
        return $record->id;
    }

    /**
     * Builds a caption row object from repeated activity-form fields.
     *
     * @param stdClass $data Submitted activity data.
     * @param int $index Repeat index.
     * @return stdClass Caption row values.
     */
    protected function extract_form_row(stdClass $data, int $index): stdClass {
        $value = static function (stdClass $data, string $field, int $index, mixed $default = '') {
            $values = $data->{$field} ?? $default;
            if (is_array($values)) {
                return $values[$index] ?? $default;
            }
            return $index === 0 ? $values : $default;
        };
        return (object)[
            "captionsource" => $value($data, "captionsource", $index, "upload"),
            "language" => $value($data, "captionlanguage", $index, "en-US"),
            "captionfile" => $value($data, "captionfile", $index, 0),
            "captionurl" => $value($data, "captionurl", $index),
            "captionnextcloudurl" => $value($data, "captionnextcloudurl", $index),
            "isdefault" => 0,
            "status" => "published",
        ];
    }

    /**
     * Determines whether a repeated caption row was left blank.
     *
     * @param stdClass $row Caption row values.
     * @param int $userid User owning the draft files.
     * @return bool Whether the row should be ignored.
     */
    protected function is_empty_row(stdClass $row, int $userid): bool {
        $source = clean_param((string)($row->captionsource ?? "upload"), PARAM_ALPHA);
        return match ($source) {
            "url" => trim((string)($row->captionurl ?? '')) === '',
            "nextcloud" => trim((string)($row->captionnextcloudurl ?? '')) === '',
            default => !$this->draft_has_files((int)($row->captionfile ?? 0), $userid),
        };
    }

    /**
     * Checks whether a user draft area contains at least one uploaded file.
     *
     * @param int $draftitemid File picker draft item identifier.
     * @param int $userid User owning the draft area.
     * @return bool Whether a caption file is present.
     */
    protected function draft_has_files(int $draftitemid, int $userid): bool {
        if ($draftitemid < 1) {
            return false;
        }
        $files = get_file_storage()->get_area_files(
            context_user::instance($userid)->id,
            "user",
            "draft",
            $draftitemid,
            "id",
            false
        );
        return (bool)$files;
    }

    /**
     * Returns the caption URL submitted for a URL or Nextcloud track.
     *
     * @param string $source Caption source short name.
     * @param stdClass $data Submitted editor values.
     * @return string Submitted source URL or an empty string when unchanged/blank.
     * @throws moodle_exception
     */
    protected function submitted_source_url(string $source, stdClass $data): string {
        if ($source === "url") {
            $url = trim((string)($data->captionurl ?? ''));
            return $url === '' ? '' : $this->validate_direct_caption_url($url);
        }
        if ($source === "nextcloud") {
            return trim((string)($data->captionnextcloudurl ?? ''));
        }
        return '';
    }

    /**
     * Downloads a remote caption file and converts it to validated WebVTT.
     *
     * @param string $url Remote caption URL.
     * @return string Validated WebVTT content.
     * @throws moodle_exception
     */
    protected function normalise_remote_caption(string $url): string {
        $content = $this->fetch_remote_content($url);
        $extension = strtolower(pathinfo((string)parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
        if ($extension === "srt" || (!str_starts_with(ltrim($content), "WEBVTT") && str_contains($content, '-->'))) {
            $content = self::convert_srt_to_vtt($content);
        }
        self::validate_webvtt($content);
        return $content;
    }

    /**
     * Maps a caption filename suffix such as lesson.pt-BR onto the supported language list.
     *
     * @param string $basename Filename without extension.
     * @return string|null Canonical language tag or null when the suffix is unknown.
     */
    protected static function language_from_filename(string $basename): string|null {
        $parts = explode('.', $basename);
        $suffix = str_replace('_', '-', (string)end($parts));
        foreach (array_keys(self::LANGUAGES) as $canonical) {
            if (strcasecmp($canonical, $suffix) === 0) {
                return $canonical;
            }
        }
        return null;
    }
}
