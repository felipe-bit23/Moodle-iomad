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
 * caption_generated.php
 *
 * @package   mod_videoprogress
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_videoprogress\event;

use coding_exception;
use core\event\base;

/**
 * Represents the Moodle caption generated event.
 */
class caption_generated extends base {
    /**
     * Defines Moodle event metadata such as level, action, target, and object table.
     *
     * @return void This method does not return a value.
     */
    protected function init(): void {
        $this->data["crud"] = "c";
        $this->data["edulevel"] = self::LEVEL_TEACHING;
        $this->data["objecttable"] = "videoprogress_captions";
    }

    /**
     * Returns the localized event name displayed by Moodle logs.
     *
     * @return string The resolved or formatted string value.
     * @throws coding_exception
     */
    public static function get_name(): string {
        return get_string("eventcaptiongenerated", "videoprogress");
    }

    /**
     * Builds the event description from the recorded object and related user data.
     *
     * @return string The resolved or formatted string value.
     * @throws coding_exception
     */
    public function get_description(): string {
        return get_string("eventcaptiongenerateddescription", "videoprogress", (object)[
            "userid" => $this->userid,
            "captionid" => $this->objectid,
        ]);
    }
}
