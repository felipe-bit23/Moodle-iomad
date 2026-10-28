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
 * report_access.php
 *
 * @package   mod_videoprogress
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_videoprogress;

use coding_exception;
use context_module;
use stdClass;

/**
 * Applies Moodle group visibility rules to student report access.
 */
class report_access {
    /**
     * Checks whether the current report viewer may access the selected student.
     *
     * @param stdClass $cm Course module record or course-module information object.
     * @param context_module $context Module context used for permissions and File API access.
     * @param int $userid Target user identifier.
     * @return bool Whether the evaluated condition or operation succeeded.
     * @throws coding_exception
     */
    public static function can_access_user(stdClass $cm, context_module $context, int $userid): bool {
        if (has_capability('moodle/site:accessallgroups', $context) || groups_get_activity_groupmode($cm) !== SEPARATEGROUPS) {
            return true;
        }
        $allowed = groups_get_activity_allowed_groups($cm);
        if (!$allowed) {
            return false;
        }
        $usergroups = groups_get_all_groups($cm->course, $userid, $cm->groupingid, 'g.id');
        return (bool)array_intersect(array_keys($allowed), array_keys($usergroups));
    }
}
