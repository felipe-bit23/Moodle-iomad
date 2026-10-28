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
 * progress_completed.php
 *
 * @package   mod_videoprogress
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_videoprogress\event;

use coding_exception;
use core\event\base;
use core\exception\moodle_exception;
use moodle_url;

/**
 * Represents the Moodle progress completed event.
 */
class progress_completed extends base {
    /**
     * Defines Moodle event metadata such as level, action, target, and object table.
     *
     * @return void This method does not return a value.
     */
    protected function init(): void {
        $this->data["crud"] = "u";
        $this->data["edulevel"] = self::LEVEL_PARTICIPATING;
        $this->data["objecttable"] = "videoprogress_progress";
    }

    /**
     * Returns the localized event name displayed by Moodle logs.
     *
     * @return string The resolved or formatted string value.
     * @throws coding_exception
     */
    public static function get_name(): string {
        return get_string("eventprogresscompleted", "videoprogress");
    }

    /**
     * Builds the event description from the recorded object and related user data.
     *
     * @return string The resolved or formatted string value.
     * @throws coding_exception
     */
    public function get_description(): string {
        return get_string("eventprogresscompleteddescription", "videoprogress", (object)[
            "userid" => $this->relateduserid,
            "activityid" => $this->other["videoprogressid"],
            "percent" => $this->other["percent"],
        ]);
    }

    /**
     * Returns the activity report URL associated with the event.
     *
     * @return moodle_url The result produced by the operation.
     * @throws moodle_exception
     */
    public function get_url(): moodle_url {
        return new moodle_url('/mod/videoprogress/view.php', ["id" => $this->contextinstanceid]);
    }
}
