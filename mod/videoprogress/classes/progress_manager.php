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
 * progress_manager.php
 *
 * @package   mod_videoprogress
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_videoprogress;

use cm_info;
use coding_exception;
use completion_info;
use context_module;
use core\lock\lock_config;
use dml_exception;
use dml_transaction_exception;
use mod_videoprogress\event\progress_completed;
use mod_videoprogress\event\progress_reset;
use moodle_exception;
use stdClass;

/**
 * Maintains server-authoritative student progress, sessions, grades, and completion.
 */
class progress_manager {
    /**
     * Loads the user progress row or creates it safely under a Moodle lock.
     *
     * @param int $activityid Video Progress activity identifier.
     * @param int $userid Target user identifier.
     * @return stdClass The loaded, created, or updated database record.
     * @throws coding_exception
     * @throws dml_exception
     * @throws moodle_exception
     */
    public function get_or_create(int $activityid, int $userid): stdClass {
        global $DB;

        if ($record = $DB->get_record("videoprogress_progress", ["videoprogressid" => $activityid, "userid" => $userid])) {
            return $record;
        }
        $factory = lock_config::get_lock_factory("mod_videoprogress");
        $lock = $factory->get_lock('progress:' . $activityid . ':' . $userid, 10);
        if (!$lock) {
            throw new moodle_exception("progresslocktimeout", "videoprogress");
        }
        try {
            if ($record = $DB->get_record("videoprogress_progress", [
                "videoprogressid" => $activityid,
                "userid" => $userid,
            ])) {
                return $record;
            }
            $now = time();
            $record = (object)[
                "videoprogressid" => $activityid,
                "userid" => $userid,
                "duration" => 0,
                "lastposition" => 0,
                "uniquewatched" => 0,
                "totalwatchtime" => 0,
                "percent" => 0,
                "watchedsegments" => '[]',
                "viewmap" => json_encode(array_fill(0, view_map::DEFAULT_BUCKETS, 0)),
                "completed" => 0,
                "confirmation" => 0,
                "confirmationtime" => 0,
                "sequence" => 0,
                "timecreated" => $now,
                "timemodified" => $now,
            ];
            $record->id = $DB->insert_record("videoprogress_progress", $record);
            return $record;
        } finally {
            $lock->release();
        }
    }

    /**
     * Validates raw tracking data and atomically updates progress, sessions, grade, and completion.
     *
     * @param stdClass $activity Activity configuration record.
     * @param cm_info|stdClass $cm Course module record or course-module information object.
     * @param int $userid Target user identifier.
     * @param array $data Validated input or tracking data.
     * @return array Structured data produced by the operation.
     * @throws dml_transaction_exception
     * @throws coding_exception
     * @throws dml_exception
     * @throws moodle_exception
     */
    public function update(stdClass $activity, cm_info|stdClass $cm, int $userid, array $data): array {
        global $DB;

        $transaction = $DB->start_delegated_transaction();
        $progress = $this->get_or_create($activity->id, $userid);
        $progress = $DB->get_record_sql(
            'SELECT * FROM {videoprogress_progress} WHERE id = :id FOR UPDATE',
            ["id" => $progress->id],
            MUST_EXIST
        );
        $session = $DB->get_record("videoprogress_sessions", [
            "videoprogressid" => $activity->id,
            "userid" => $userid,
            "sessionkey" => $data["sessionkey"],
        ]);
        if ($session) {
            $session = $DB->get_record_sql(
                'SELECT * FROM {videoprogress_sessions} WHERE id = :id FOR UPDATE',
                ["id" => $session->id],
                MUST_EXIST
            );
        }
        $now = time();
        $tracking = (new tracking_manager())->validate($activity, $progress, $session, $data, $now);
        if (!$tracking["accepted"]) {
            $transaction->allow_commit();
            return $this->response($activity, $progress, $tracking);
        }

        $blockingitem = (new content\manager())->get_blocking_item(
            $activity->id,
            $userid,
            (float)$tracking["currentposition"]
        );
        if ($blockingitem && (float)$tracking["currentposition"] > (float)$blockingitem->timepoint + 0.5) {
            $blockposition = (float)$blockingitem->timepoint;
            if ($tracking["segment"]) {
                if ((float)$tracking["segment"][0] >= $blockposition) {
                    $tracking["segment"] = null;
                } else {
                    $tracking["segment"][1] = min((float)$tracking["segment"][1], $blockposition);
                    if ($tracking["segment"][1] <= $tracking["segment"][0]) {
                        $tracking["segment"] = null;
                    }
                }
            }
            $tracking["currentposition"] = $blockposition;
            $tracking["correctposition"] = $blockposition;
            $tracking["seekblocked"] = true;
            $tracking["reason"] = "interactionrequired";
            $tracking["watchtime"] = $tracking["segment"]
                ? min(
                    (float)$tracking["watchtime"],
                    ($tracking["segment"][1] - $tracking["segment"][0]) /
                    max(0.25, (float)$tracking["playbackrate"])
                )
                : 0.0;
        }

        $previouscompleted = (bool)$progress->completed;
        $segments = segment_manager::decode($progress->watchedsegments);
        if ($tracking["segment"]) {
            $segments = segment_manager::merge($segments, [$tracking["segment"]], $tracking["duration"]);
            $map = view_map::decode($progress->viewmap);
            $map = view_map::add_segment(
                $map,
                $tracking["segment"][0],
                $tracking["segment"][1],
                $tracking["duration"]
            );
            $progress->viewmap = json_encode($map);
        }
        $progress->watchedsegments = segment_manager::encode($segments);
        $progress->duration = max((float)$progress->duration, $tracking["duration"]);
        $progress->lastposition = $tracking["currentposition"];
        $progress->uniquewatched = segment_manager::unique_seconds($segments);
        $progress->totalwatchtime = round((float)$progress->totalwatchtime + $tracking["watchtime"], 3);
        $progress->percent = $progress->duration > 0
            ? round(min(100, ($progress->uniquewatched / $progress->duration) * 100), 2)
            : 0;
        $progress->completed = $this->is_complete($activity, $progress) ? 1 : 0;
        $progress->sequence = max((int)$progress->sequence, (int)$data["sequence"]);
        $progress->timemodified = $now;
        $DB->update_record("videoprogress_progress", $progress);

        if (!$session) {
            $session = (object)[
                "videoprogressid" => $activity->id,
                "userid" => $userid,
                "sessionkey" => $data["sessionkey"],
                "timestart" => $now,
                "timeend" => 0,
                "watchtime" => 0,
                "startposition" => $tracking["segment"][0] ?? $tracking["currentposition"],
                "endposition" => $tracking["currentposition"],
                "lastposition" => $tracking["currentposition"],
                "sequence" => 0,
                "lastheartbeat" => $now,
                "lastclienttime" => (int)$data["clienttime"],
                "playerstate" => "paused",
                "timecreated" => $now,
                "timemodified" => $now,
            ];
            $session->id = $DB->insert_record("videoprogress_sessions", $session);
        }
        $session->watchtime = round((float)$session->watchtime + $tracking["watchtime"], 3);
        $session->endposition = $tracking["currentposition"];
        $session->lastposition = $tracking["currentposition"];
        $session->sequence = (int)$data["sequence"];
        $session->lastheartbeat = $now;
        $session->lastclienttime = (int)$data["clienttime"];
        $session->playerstate = $data["playerstate"];
        $session->timemodified = $now;
        if (in_array($data["playerstate"], ["ended", "closed"], true)) {
            $session->timeend = $now;
        }
        $DB->update_record("videoprogress_sessions", $session);
        $transaction->allow_commit();

        videoprogress_update_grades($activity, $userid);
        $this->update_completion($activity, $cm, $userid, (bool)$progress->completed);
        if (!$previouscompleted && $progress->completed) {
            $event = progress_completed::create([
                "objectid" => $progress->id,
                "context" => context_module::instance($cm->id),
                "relateduserid" => $userid,
                "other" => ["videoprogressid" => $activity->id, "percent" => (float)$progress->percent],
            ]);
            $event->trigger();
        }
        return $this->response($activity, $progress, $tracking);
    }

    /**
     * Records the student confirmation after the required watched percentage is reached.
     *
     * @param stdClass $activity Activity configuration record.
     * @param stdClass $cm Course module record or course-module information object.
     * @param int $userid Target user identifier.
     * @return stdClass The loaded, created, or updated database record.
     * @throws coding_exception
     * @throws dml_exception
     * @throws moodle_exception
     */
    public function confirm(stdClass $activity, stdClass $cm, int $userid): stdClass {
        global $DB;
        $progress = $this->get_or_create($activity->id, $userid);
        if ((float)$progress->percent < (float)$activity->completionpercent) {
            throw new moodle_exception("confirmationnotavailable", "videoprogress");
        }
        $progress->confirmation = 1;
        $progress->confirmationtime = time();
        $progress->completed = $this->is_complete($activity, $progress) ? 1 : 0;
        $progress->timemodified = time();
        $DB->update_record("videoprogress_progress", $progress);
        $this->update_completion($activity, $cm, $userid, (bool)$progress->completed);
        return $progress;
    }

    /**
     * Resets a student progress, sessions, interactions, grade, and completion state.
     *
     * @param stdClass $activity Activity configuration record.
     * @param stdClass $cm Course module record or course-module information object.
     * @param int $userid Target user identifier.
     * @param int $actorid User identifier that initiated the operation.
     * @return void This method does not return a value.
     * @throws coding_exception
     * @throws dml_exception
     * @throws dml_transaction_exception
     * @throws moodle_exception
     */
    public function reset(stdClass $activity, stdClass $cm, int $userid, int $actorid): void {
        global $DB;
        $progress = $DB->get_record("videoprogress_progress", ["videoprogressid" => $activity->id, "userid" => $userid]);
        $transaction = $DB->start_delegated_transaction();
        $DB->delete_records_select(
            "videoprogress_interactions",
            'userid = :userid AND itemid IN (
                SELECT i.id
                  FROM {videoprogress_pointitems} i
                  JOIN {videoprogress_points} p ON p.id = i.pointid
                 WHERE p.videoprogressid = :activityid
            )',
            ["userid" => $userid, "activityid" => $activity->id]
        );
        $DB->delete_records("videoprogress_sessions", ["videoprogressid" => $activity->id, "userid" => $userid]);
        $DB->delete_records("videoprogress_progress", ["videoprogressid" => $activity->id, "userid" => $userid]);
        $transaction->allow_commit();
        videoprogress_update_grades($activity, $userid);
        $this->update_completion($activity, $cm, $userid, false);
        if ($progress) {
            $event = progress_reset::create([
                "objectid" => $progress->id,
                "context" => context_module::instance($cm->id),
                "relateduserid" => $userid,
                "userid" => $actorid,
                "other" => ["videoprogressid" => $activity->id],
            ]);
            $event->trigger();
        }
    }

    /**
     * Checks whether watched percentage and optional confirmation satisfy completion.
     *
     * @param stdClass $activity Activity configuration record.
     * @param stdClass $progress Persisted student progress record.
     * @return bool Whether the evaluated condition or operation succeeded.
     */
    public function is_complete(stdClass $activity, stdClass $progress): bool {
        $watched = (float)$progress->percent >= (float)$activity->completionpercent;
        return $watched && (!$activity->requireconfirmation || !empty($progress->confirmation));
    }

    /**
     * Synchronizes the calculated activity state with Moodle course completion.
     *
     * @param stdClass $activity Activity configuration record.
     * @param stdClass $cm Course module record or course-module information object.
     * @param int $userid Target user identifier.
     * @param bool $complete complete value used by the operation.
     * @return void This method does not return a value.
     * @throws dml_exception
     * @throws moodle_exception
     */
    private function update_completion(stdClass $activity, stdClass $cm, int $userid, bool $complete): void {
        $completion = new completion_info(get_course($activity->course));
        if ($completion->is_enabled($cm)) {
            $completion->update_state($cm, $complete ? COMPLETION_COMPLETE : COMPLETION_INCOMPLETE, $userid);
        }
    }

    /**
     * Builds the server-authoritative tracking response returned to the browser.
     *
     * @param stdClass $activity Activity configuration record.
     * @param stdClass $progress Persisted student progress record.
     * @param array $tracking tracking value used by the operation.
     * @return array Structured data produced by the operation.
     */
    private function response(stdClass $activity, stdClass $progress, array $tracking): array {
        return [
            "accepted" => (bool)$tracking["accepted"],
            "reason" => (string)($tracking["reason"] ?? ''),
            "correctposition" => (float)($tracking["correctposition"] ?? $tracking["currentposition"] ?? $progress->lastposition),
            "percent" => (float)$progress->percent,
            "uniquewatched" => (float)$progress->uniquewatched,
            "totalwatchtime" => (float)$progress->totalwatchtime,
            "lastposition" => (float)$progress->lastposition,
            "completed" => (bool)$progress->completed,
            "confirmationrequired" => $activity->requireconfirmation && empty($progress->confirmation),
            "segments" => (string)$progress->watchedsegments,
            "viewmap" => (string)$progress->viewmap,
        ];
    }
}
