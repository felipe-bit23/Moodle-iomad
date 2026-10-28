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
 * complete_point.php
 *
 * @package   mod_videoprogress
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_videoprogress\external;

use coding_exception;
use context_module;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use core_external\restricted_context_exception;
use dml_exception;
use invalid_parameter_exception;
use mod_videoprogress\content\manager;
use moodle_exception;
use required_capability_exception;

/**
 * Exposes the authenticated AJAX endpoint used to complete synchronized video interactions.
 */
class complete_point extends external_api {
    /**
     * Defines and documents the parameters accepted by the external AJAX function.
     *
     * @return external_function_parameters The result produced by the operation.
     * @throws coding_exception
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            "cmid" => new external_value(PARAM_INT, get_string('ws:cmid', "videoprogress")),
            "itemid" => new external_value(PARAM_INT, get_string('ws:itemid', "videoprogress")),
            "response" => new external_value(PARAM_RAW, get_string('ws:interactionresponse', "videoprogress")),
        ]);
    }

    /**
     * Validates the authenticated request and stores a student interaction response.
     *
     * @param int $cmid Course module identifier.
     * @param int $itemid Synchronized content item identifier.
     * @param string $response Decoded student response.
     * @return array Structured data produced by the operation.
     * @throws restricted_context_exception
     * @throws dml_exception
     * @throws moodle_exception
     * @throws coding_exception
     * @throws invalid_parameter_exception
     * @throws required_capability_exception
     */
    public static function execute(int $cmid, int $itemid, string $response): array {
        global $DB, $USER;
        $params = self::validate_parameters(self::execute_parameters(), compact("cmid", "itemid", "response"));
        if ($params["itemid"] <= 0 || strlen($params["response"]) > 2000) {
            throw new invalid_parameter_exception(get_string("invalidinteractionresponse", "videoprogress"));
        }
        $cm = get_coursemodule_from_id("videoprogress", $params["cmid"], 0, false, MUST_EXIST);
        $activity = $DB->get_record("videoprogress", ["id" => $cm->instance], "*", MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/videoprogress:view', $context);
        if (isguestuser() || !is_enrolled($context, $USER, 'mod/videoprogress:view', true)) {
            throw new required_capability_exception($context, 'mod/videoprogress:view', "nopermissions", '');
        }
        return (new manager())->complete_item(
            $activity,
            $USER->id,
            $params["itemid"],
            $params["response"]
        );
    }

    /**
     * Defines and documents the server-authoritative response returned by the external function.
     *
     * @return external_single_structure The result produced by the operation.
     * @throws coding_exception
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            "completed" => new external_value(PARAM_BOOL, get_string('ws:interactioncompleted', "videoprogress")),
            "feedback" => new external_value(PARAM_TEXT, get_string('ws:interactionfeedback', "videoprogress")),
            "attempts" => new external_value(PARAM_INT, get_string('ws:interactionattempts', "videoprogress")),
        ]);
    }
}
