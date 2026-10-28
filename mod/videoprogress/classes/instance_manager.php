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
 * instance_manager.php
 *
 * @package   mod_videoprogress
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_videoprogress;

use coding_exception;
use context_module;
use dml_exception;
use dml_transaction_exception;
use file_exception;
use moodle_exception;
use stdClass;
use stored_file_creation_exception;

/**
 * Coordinates generic activity files and delegates source-owned files to the selected subplugin.
 */
class instance_manager {
    /**
     * Saves source-specific files, poster files, and initial caption tracks.
     *
     * @param stdClass $data Validated input or tracking data.
     * @param string|null $previoussource Source selected before an activity update.
     * @return void This method does not return a value.
     * @throws coding_exception
     * @throws dml_exception
     * @throws dml_transaction_exception
     * @throws file_exception
     * @throws moodle_exception
     * @throws stored_file_creation_exception
     */
    public static function save_files(stdClass $data, string|null $previoussource = null): void {
        global $USER;
        $context = context_module::instance($data->coursemodule);
        $sourcemanager = new source\manager();
        $sourceplugin = $sourcemanager->get_plugin((string)$data->videosource);
        $sourcemanager->save_files($data, $context, $previoussource);
        if (!$sourceplugin->supports_poster()) {
            get_file_storage()->delete_area_files($context->id, "mod_videoprogress", "poster", 0);
        } else if (isset($data->poster)) {
            file_save_draft_area_files($data->poster, $context->id, "mod_videoprogress", "poster", 0, [
                "subdirs" => 0,
                "accepted_types" => ["image"],
            ]);
        }
        if ($sourceplugin->supports_uploaded_captions()) {
            $captionmanager = new caption_manager();
            if (!empty($data->subtitles)) {
                $captionmanager->import_draft_files(
                    (int)$data->subtitles,
                    (int)$data->id,
                    $context,
                    (int)$USER->id
                );
            }
            $captionmanager->save_form_tracks($data, $context, (int)$USER->id);
        }
    }
}
