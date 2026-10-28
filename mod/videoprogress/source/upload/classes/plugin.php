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
 * @package   videoprogresssource_upload
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace videoprogresssource_upload;

use coding_exception;
use context_module;
use mod_videoprogress\source\plugin_base;
use moodle_exception;
use MoodleQuickForm;
use stdClass;
use stored_file;

/**
 * Implements protected Moodle File API uploads as an independently installable video source.
 */
class plugin extends plugin_base {
    /**
     * Places the protected Moodle upload before other bundled sources by default.
     *
     * @return int Preferred source order.
     */
    public function get_sort_order(): int {
        return 10;
    }

    /**
     * Returns the localized source name displayed in activity forms.
     *
     * @return string Localized upload source name.
     * @throws coding_exception
     */
    public function get_name(): string {
        return get_string("pluginname", "videoprogresssource_upload");
    }

    /**
     * Adds the single protected video upload field and its source-dependent visibility rule.
     *
     * @param MoodleQuickForm $mform Activity form receiving the upload field.
     * @param string $sourcefield Name of the source selector field.
     * @return void
     * @throws coding_exception
     */
    public function add_form_elements(MoodleQuickForm $mform, string $sourcefield): void {
        $filemanageroptions = [
            "subdirs" => 0,
            "accepted_types" => ['.mp4', '.webm', '.ogv', '.m4v', '.mov', '.m3u8'],
        ];
        $mform->addElement("filemanager", "videofile",
            get_string("videofile", "videoprogresssource_upload"),
            null, $filemanageroptions);
        $mform->hideIf("videofile", $sourcefield, "neq", "upload");
    }

    /**
     * Requires exactly one uploaded video when the upload source is selected.
     *
     * @param array $data Submitted activity form values.
     * @param array $files Submitted activity form files.
     * @return array Field names mapped to localized validation errors.
     * @throws coding_exception
     */
    public function validation(array $data, array $files): array {
        if (empty($data["videofile"]) || empty(file_get_draft_area_info($data["videofile"])["filecount"])) {
            return ["videofile" => get_string("required")];
        }
        return [];
    }

    /**
     * Records that the selected source uses Moodle-managed protected storage.
     *
     * @param stdClass $data Submitted activity data.
     * @return array Normalized upload source configuration.
     */
    public function build_config(stdClass $data): array {
        return ["storage" => "moodle"];
    }

    /**
     * Returns the empty legacy URL used by uploaded activities in older plugin versions.
     *
     * @param array $config Normalized source configuration.
     * @return string Empty backward-compatible URL value.
     */
    public function get_legacy_value(array $config): string {
        return '';
    }

    /**
     * Prepares the protected video file area as an editable Moodle draft area.
     *
     * @param array $defaultvalues Values passed to the activity form.
     * @param context_module $context Module context used for File API access.
     * @return void
     */
    public function prepare_form_data(array &$defaultvalues, context_module $context): void {
        $draftitemid = file_get_submitted_draft_itemid("videofile");
        file_prepare_draft_area($draftitemid, $context->id, "mod_videoprogress", "video", 0, [
            "subdirs" => 0,
            "maxfiles" => 1,
        ]);
        $defaultvalues["videofile"] = $draftitemid;
    }

    /**
     * Moves the selected draft video into the protected activity file area.
     *
     * @param stdClass $data Saved activity data including the draft item identifier.
     * @param context_module $context Module context used for File API access.
     * @return void
     * @throws coding_exception
     */
    public function save_files(stdClass $data, context_module $context): void {
        if (!isset($data->videofile)) {
            return;
        }
        file_save_draft_area_files($data->videofile, $context->id, "mod_videoprogress", "video", 0, [
            "subdirs" => 0,
            "maxfiles" => 1,
            "accepted_types" => ['.mp4', '.webm', '.ogv', '.m4v', '.mov', '.m3u8'],
        ]);
    }

    /**
     * Removes the protected uploaded video when the source changes or the activity is deleted.
     *
     * @param context_module $context Module context whose upload must be removed.
     * @return void
     */
    public function delete_files(context_module $context): void {
        get_file_storage()->delete_area_files($context->id, "mod_videoprogress", "video", 0);
    }

    /**
     * Builds the protected video URL and HLS flag used by the browser adapter.
     *
     * @param stdClass $activity Activity configuration record.
     * @param context_module $context Module context used for File API access.
     * @return array Browser-safe upload player configuration.
     * @throws moodle_exception
     * @throws coding_exception
     */
    public function get_player_config(stdClass $activity, context_module $context): array {
        global $CFG;

        $url = $this->first_file_url($context, "mod_videoprogress", "video");
        if ($url === '') {
            throw new moodle_exception("videofilemissing", "videoprogresssource_upload");
        }
        return [
            "url" => $url,
            "hls" => (bool)preg_match('/\.m3u8(?:$|\?)/i', $url),
            "hlsjsurl" => $CFG->wwwroot . '/mod/videoprogress/vendor/hls/hls.min.js',
        ];
    }

    /**
     * Returns the Mustache template that renders the uploaded HTML5 video element.
     *
     * @return string Moodle template identifier.
     */
    public function get_player_template(): string {
        return 'videoprogresssource_upload/player';
    }

    /**
     * Returns the AMD module that selects the HTML5 or HLS adapter for an uploaded file.
     *
     * @return string Moodle AMD module identifier.
     */
    public function get_amd_module(): string {
        return 'videoprogresssource_upload/player';
    }

    /**
     * Returns the protected uploaded media file used by server-side transcription.
     *
     * @param context_module $context Module context containing the uploaded video.
     * @return stored_file|null Uploaded media file or null when unavailable.
     * @throws coding_exception
     */
    public function get_transcription_file(context_module $context): stored_file|null {
        $files = get_file_storage()->get_area_files(
            $context->id,
            "mod_videoprogress",
            "video",
            0,
            "filename",
            false
        );
        return $files ? reset($files) : null;
    }

    /**
     * Converts an older upload activity into the new normalized source configuration.
     *
     * @param string $legacyvalue Legacy videourl value.
     * @return array Normalized upload source configuration.
     */
    protected function get_legacy_config(string $legacyvalue): array {
        return ["storage" => "moodle"];
    }
}
