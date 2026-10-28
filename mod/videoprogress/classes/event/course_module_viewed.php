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
 * course_module_viewed.php
 *
 * @package   mod_videoprogress
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_videoprogress\event;

/**
 * Represents the Moodle course module viewed event.
 */
class course_module_viewed extends \core\event\course_module_viewed {
    /**
     * Defines Moodle event metadata such as level, action, target, and object table.
     *
     * @return void This method does not return a value.
     */
    protected function init(): void {
        $this->data["crud"] = "r";
        $this->data["edulevel"] = self::LEVEL_PARTICIPATING;
        $this->data["objecttable"] = "videoprogress";
    }

    /**
     * Returns the database mapping used for the viewed activity event object.
     *
     * @return array Structured data produced by the operation.
     */
    public static function get_objectid_mapping(): array {
        return ["db" => "videoprogress", "restore" => "videoprogress"];
    }
}
