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
 * lib.php
 *
 * @package   mod_videoprogress
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Creates Video Progress module instances for Moodle automated tests.
 */
class mod_videoprogress_generator extends testing_module_generator {
    /**
     * Creates a Video Progress activity instance for automated tests.
     *
     * @param mixed $record Database record being processed.
     * @param array|null $options Additional file-serving or formatting options.
     * @return stdClass The loaded, created, or updated database record.
     * @throws coding_exception
     * @throws dml_exception
     * @throws dml_transaction_exception
     * @throws file_exception
     * @throws moodle_exception
     * @throws stored_file_creation_exception
     */
    public function create_instance($record = null, ?array $options = null): stdClass {
        $record = (object)(array)$record;
        $record->videosource = $record->videosource ?? "url";
        $record->videourl = $record->videourl ?? 'https://example.test/video.mp4';
        $record->resumeplayback = $record->resumeplayback ?? 1;
        $record->allowseek = $record->allowseek ?? 1;
        $record->maxplaybackrate = $record->maxplaybackrate ?? 0;
        $record->disabledownload = $record->disabledownload ?? 0;
        $record->disablepip = $record->disablepip ?? 0;
        $record->disablecontextmenu = $record->disablecontextmenu ?? 0;
        $record->completionpercent = $record->completionpercent ?? 80;
        $record->requireconfirmation = $record->requireconfirmation ?? 0;
        $record->grade = $record->grade ?? 100;
        return parent::create_instance($record, $options);
    }
}
