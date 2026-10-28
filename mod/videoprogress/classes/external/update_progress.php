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
 * update_progress.php
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
use mod_videoprogress\progress_manager;
use required_capability_exception;

/**
 * Exposes the authenticated AJAX endpoint used to submit raw video tracking updates.
 */
class update_progress extends external_api {
    /**
     * Defines and documents the parameters accepted by the external AJAX function.
     *
     * @return external_function_parameters The result produced by the operation.
     * @throws coding_exception
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            "cmid" => new external_value(PARAM_INT, get_string('ws:cmid', "videoprogress")),
            "currentposition" => new external_value(PARAM_FLOAT, get_string('ws:currentposition', "videoprogress")),
            "duration" => new external_value(PARAM_FLOAT, get_string('ws:duration', "videoprogress")),
            "playbackrate" => new external_value(PARAM_FLOAT, get_string('ws:playbackrate', "videoprogress")),
            "segmentstart" => new external_value(PARAM_FLOAT, get_string('ws:segmentstart', "videoprogress")),
            "segmentend" => new external_value(PARAM_FLOAT, get_string('ws:segmentend', "videoprogress")),
            "sequence" => new external_value(PARAM_INT, get_string('ws:sequence', "videoprogress")),
            "sessionkey" => new external_value(PARAM_ALPHANUMEXT, get_string('ws:sessionkey', "videoprogress")),
            "clienttime" => new external_value(PARAM_INT, get_string('ws:clienttime', "videoprogress")),
            "playerstate" => new external_value(PARAM_ALPHA, get_string('ws:playerstate', "videoprogress")),
        ]);
    }

    /**
     * Validates the authenticated request and submits raw tracking data to the progress manager.
     *
     * @param int $cmid Course module identifier.
     * @param float $currentposition currentposition value used by the operation.
     * @param float $duration Authoritative video duration in seconds.
     * @param float $playbackrate playbackrate value used by the operation.
     * @param float $segmentstart segmentstart value used by the operation.
     * @param float $segmentend segmentend value used by the operation.
     * @param int $sequence sequence value used by the operation.
     * @param string $sessionkey sessionkey value used by the operation.
     * @param int $clienttime clienttime value used by the operation.
     * @param string $playerstate playerstate value used by the operation.
     * @return array Structured data produced by the operation.
     * @throws restricted_context_exception
     * @throws dml_exception
     * @throws invalid_parameter_exception
     * @throws required_capability_exception
     * @throws coding_exception
     */
    public static function execute(int $cmid, float $currentposition, float $duration, float $playbackrate,
                                   float $segmentstart, float $segmentend, int $sequence, string $sessionkey, int $clienttime,
                                   string $playerstate): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), compact(
            "cmid", "currentposition", "duration", "playbackrate", "segmentstart", "segmentend",
            "sequence", "sessionkey", "clienttime", "playerstate"
        ));
        $cm = get_coursemodule_from_id("videoprogress", $params["cmid"], 0, false, MUST_EXIST);
        $activity = $DB->get_record("videoprogress", ["id" => $cm->instance], "*", MUST_EXIST);
        $context = context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/videoprogress:view', $context);
        if (isguestuser() || !is_enrolled($context, $USER, 'mod/videoprogress:view', true)) {
            throw new required_capability_exception($context, 'mod/videoprogress:view', "nopermissions", '');
        }
        if ($params["duration"] <= 0 || $params["duration"] > 86400 * 7 || $params["currentposition"] < 0 ||
            $params["playbackrate"] < 0.25 || $params["playbackrate"] > 4 || $params["segmentstart"] < 0 ||
            $params["sequence"] < 1 || strlen($params["sessionkey"]) < 16 ||
            $params["clienttime"] <= 0 || $params["clienttime"] > time() + 300 ||
            !in_array($params["playerstate"], ["playing", "paused", "seeking", "ended", "hidden", "closed"], true)) {
            throw new invalid_parameter_exception(get_string("invalidtrackingdata", "videoprogress"));
        }
        return (new progress_manager())->update($activity, $cm, $USER->id, $params);
    }

    /**
     * Defines and documents the server-authoritative response returned by the external function.
     *
     * @return external_single_structure The result produced by the operation.
     * @throws coding_exception
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            "accepted" => new external_value(PARAM_BOOL, get_string('ws:accepted', "videoprogress")),
            "reason" => new external_value(PARAM_ALPHAEXT, get_string('ws:reason', "videoprogress")),
            "correctposition" => new external_value(PARAM_FLOAT, get_string('ws:correctposition', "videoprogress")),
            "percent" => new external_value(PARAM_FLOAT, get_string('ws:percent', "videoprogress")),
            "uniquewatched" => new external_value(PARAM_FLOAT, get_string('ws:uniquewatched', "videoprogress")),
            "totalwatchtime" => new external_value(PARAM_FLOAT, get_string('ws:totalwatchtime', "videoprogress")),
            "lastposition" => new external_value(PARAM_FLOAT, get_string('ws:lastposition', "videoprogress")),
            "completed" => new external_value(PARAM_BOOL, get_string('ws:completed', "videoprogress")),
            "confirmationrequired" => new external_value(PARAM_BOOL, get_string('ws:confirmationrequired', "videoprogress")),
            "segments" => new external_value(PARAM_RAW, get_string('ws:segments', "videoprogress")),
            "viewmap" => new external_value(PARAM_RAW, get_string('ws:viewmap', "videoprogress")),
        ]);
    }
}
