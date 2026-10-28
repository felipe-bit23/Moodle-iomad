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
 * Returns the launch data used by custom Moodle mobile clients.
 *
 * @package   mod_videoprogress
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_videoprogress\external;

use context_module;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use required_capability_exception;

/**
 * Exposes the authenticated launch information for the mobile application.
 */
class get_app_view extends external_api {
    /**
     * Defines the accepted parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            "cmid" => new external_value(PARAM_INT, "Course module identifier"),
        ]);
    }

    /**
     * Returns the activity URL and current student progress.
     *
     * @param int $cmid Course module identifier.
     * @return array
     */
    public static function execute(int $cmid): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), ["cmid" => $cmid]);
        $cm = get_coursemodule_from_id("videoprogress", $params["cmid"], 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);

        self::validate_context($context);
        require_capability('mod/videoprogress:view', $context);

        if (isguestuser() || !is_enrolled($context, $USER, 'mod/videoprogress:view', true)) {
            throw new required_capability_exception($context, 'mod/videoprogress:view', "nopermissions", '');
        }

        $activity = $DB->get_record("videoprogress", ["id" => $cm->instance], "*", MUST_EXIST);
        $progress = $DB->get_record("videoprogress_progress", [
            "videoprogressid" => $activity->id,
            "userid" => $USER->id,
        ]);

        return [
            "cmid" => (int)$cm->id,
            "name" => format_string($activity->name),
            "viewurl" => (new \moodle_url('/mod/videoprogress/view.php', ["id" => $cm->id]))->out(false),
            "percent" => $progress ? (float)$progress->percent : 0.0,
            "completed" => $progress ? (bool)$progress->completed : false,
            "lastposition" => $progress ? (float)$progress->lastposition : 0.0,
            "completionpercent" => (int)$activity->completionpercent,
            "requireconfirmation" => (bool)$activity->requireconfirmation,
        ];
    }

    /**
     * Defines the returned structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            "cmid" => new external_value(PARAM_INT, "Course module identifier"),
            "name" => new external_value(PARAM_TEXT, "Activity name"),
            "viewurl" => new external_value(PARAM_URL, "Full activity URL"),
            "percent" => new external_value(PARAM_FLOAT, "Current watched percentage"),
            "completed" => new external_value(PARAM_BOOL, "Whether the activity is complete"),
            "lastposition" => new external_value(PARAM_FLOAT, "Last watched position in seconds"),
            "completionpercent" => new external_value(PARAM_INT, "Required percentage for completion"),
            "requireconfirmation" => new external_value(PARAM_BOOL, "Whether final confirmation is required"),
        ]);
    }
}
