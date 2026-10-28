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
 * player_config.php
 *
 * @package   mod_videoprogress
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_videoprogress;

use coding_exception;
use context_module;
use moodle_exception;
use moodle_url;
use stdClass;

/**
 * Combines generic playback restrictions with configuration supplied by the selected source subplugin.
 */
class player_config {
    /**
     * Builds the complete browser-safe configuration used by the player template and tracker.
     *
     * @param stdClass $activity Activity configuration record.
     * @param context_module $context Module context used for permissions and File API access.
     * @return array Complete player configuration.
     * @throws coding_exception
     * @throws moodle_exception
     */
    public static function build(stdClass $activity, context_module $context): array {
        $sourceconfig = (new source\manager())->get_player_config($activity, $context);
        return $sourceconfig + [
                "poster" => self::first_file_url($context, "poster"),
                "maxplaybackrate" => (float)$activity->maxplaybackrate,
                "allowseek" => (bool)$activity->allowseek,
                "disabledownload" => (bool)$activity->disabledownload,
                "disablepip" => (bool)$activity->disablepip,
            ];
    }

    /**
     * Returns the protected URL of the first generic activity file in the requested area.
     *
     * @param context_module $context Module context containing the file.
     * @param string $filearea Generic activity file area name.
     * @return string Protected file URL or an empty string when no file exists.
     * @throws coding_exception
     */
    private static function first_file_url(context_module $context, string $filearea): string {
        $files = get_file_storage()->get_area_files(
            $context->id,
            "mod_videoprogress",
            $filearea,
            0,
            "filename",
            false
        );
        if (!$files) {
            return '';
        }
        $file = reset($files);
        return moodle_url::make_pluginfile_url(
            $context->id,
            "mod_videoprogress",
            $filearea,
            0,
            $file->get_filepath(),
            $file->get_filename()
        )->out(false);
    }
}
