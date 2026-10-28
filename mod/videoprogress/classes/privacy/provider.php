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
 * phpcs:disable Universal.OOStructures.AlphabeticExtendsImplements.ImplementsWrongOrder
 * provider.php
 *
 * @package   mod_videoprogress
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_videoprogress\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Implements Moodle Privacy API metadata, export, discovery, and deletion operations.
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\core_userlist_provider {

    /**
     * Declares the personal data stored by Video Progress for the Moodle Privacy API.
     *
     * @param collection $collection collection value used by the operation.
     * @return collection The result produced by the operation.
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table("videoprogress_progress", [
            "userid" => 'privacy:metadata:progress:userid',
            "duration" => 'privacy:metadata:progress:duration',
            "lastposition" => 'privacy:metadata:progress:lastposition',
            "uniquewatched" => 'privacy:metadata:progress:uniquewatched',
            "totalwatchtime" => 'privacy:metadata:progress:totalwatchtime',
            "percent" => 'privacy:metadata:progress:percent',
            "watchedsegments" => 'privacy:metadata:progress:segments',
            "viewmap" => 'privacy:metadata:progress:viewmap',
            "completed" => 'privacy:metadata:progress:completed',
            "confirmation" => 'privacy:metadata:progress:confirmation',
            "confirmationtime" => 'privacy:metadata:progress:confirmationtime',
            "timemodified" => 'privacy:metadata:progress:timemodified',
        ], 'privacy:metadata:progress');
        $collection->add_database_table("videoprogress_sessions", [
            "userid" => 'privacy:metadata:sessions:userid',
            "sessionkey" => 'privacy:metadata:sessions:sessionkey',
            "timestart" => 'privacy:metadata:sessions:timestart',
            "timeend" => 'privacy:metadata:sessions:timeend',
            "lastclienttime" => 'privacy:metadata:sessions:lastclienttime',
            "watchtime" => 'privacy:metadata:sessions:watchtime',
            "startposition" => 'privacy:metadata:sessions:startposition',
            "endposition" => 'privacy:metadata:sessions:endposition',
            "timecreated" => 'privacy:metadata:sessions:timecreated',
            "timemodified" => 'privacy:metadata:sessions:timemodified',
        ], 'privacy:metadata:sessions');
        $collection->add_database_table("videoprogress_captions", [
            "createdby" => 'privacy:metadata:captions:createdby',
            "language" => 'privacy:metadata:captions:language',
            "label" => 'privacy:metadata:captions:label',
            "source" => 'privacy:metadata:captions:source',
            "timecreated" => 'privacy:metadata:captions:timecreated',
            "timemodified" => 'privacy:metadata:captions:timemodified',
        ], 'privacy:metadata:captions');
        $collection->add_database_table("videoprogress_interactions", [
            "userid" => 'privacy:metadata:interactions:userid',
            "completed" => 'privacy:metadata:interactions:completed',
            "attempts" => 'privacy:metadata:interactions:attempts',
            "lastresponse" => 'privacy:metadata:interactions:lastresponse',
            "timecompleted" => 'privacy:metadata:interactions:timecompleted',
            "timecreated" => 'privacy:metadata:interactions:timecreated',
            "timemodified" => 'privacy:metadata:interactions:timemodified',
        ], 'privacy:metadata:interactions');
        return $collection;
    }

    /**
     * Returns module contexts containing personal data for the selected user.
     *
     * @param int $userid Target user identifier.
     * @return contextlist The result produced by the operation.
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $sql = "SELECT ctx.id
                  FROM {context} ctx
                  JOIN {course_modules} cm ON cm.id = ctx.instanceid AND ctx.contextlevel = :contextlevel
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modulename
                  JOIN {videoprogress} v ON v.id = cm.instance
             LEFT JOIN {videoprogress_progress} p ON p.videoprogressid = v.id AND p.userid = :progressuserid
             LEFT JOIN {videoprogress_sessions} s ON s.videoprogressid = v.id AND s.userid = :sessionuserid
             LEFT JOIN {videoprogress_captions} c ON c.videoprogressid = v.id AND c.createdby = :captionuserid
             LEFT JOIN {videoprogress_points} vp ON vp.videoprogressid = v.id
             LEFT JOIN {videoprogress_pointitems} vi ON vi.pointid = vp.id
             LEFT JOIN {videoprogress_interactions} vx ON vx.itemid = vi.id AND vx.userid = :interactionuserid
                 WHERE p.id IS NOT NULL OR s.id IS NOT NULL OR c.id IS NOT NULL OR vx.id IS NOT NULL";
        $contextlist->add_from_sql($sql, [
            "contextlevel" => CONTEXT_MODULE,
            "modulename" => "videoprogress",
            "progressuserid" => $userid,
            "sessionuserid" => $userid,
            "captionuserid" => $userid,
            "interactionuserid" => $userid,
        ]);
        return $contextlist;
    }

    /**
     * Adds users with stored Video Progress data to the Privacy API user list.
     *
     * @param userlist $userlist Privacy API user list.
     * @return void This method does not return a value.
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if (!$context instanceof \context_module) {
            return;
        }
        $params = ["cmid" => $context->instanceid, "modulename" => "videoprogress"];
        $sql = "SELECT p.userid
                  FROM {videoprogress_progress} p
                  JOIN {videoprogress} v ON v.id = p.videoprogressid
                  JOIN {course_modules} cm ON cm.instance = v.id AND cm.id = :cmid
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modulename";
        $userlist->add_from_sql("userid", $sql, $params);
        $sql = "SELECT x.userid
                  FROM {videoprogress_interactions} x
                  JOIN {videoprogress_pointitems} i ON i.id = x.itemid
                  JOIN {videoprogress_points} p ON p.id = i.pointid
                  JOIN {videoprogress} v ON v.id = p.videoprogressid
                  JOIN {course_modules} cm ON cm.instance = v.id AND cm.id = :cmid
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modulename";
        $userlist->add_from_sql("userid", $sql, $params);
        $sql = "SELECT c.createdby AS userid
                  FROM {videoprogress_captions} c
                  JOIN {videoprogress} v ON v.id = c.videoprogressid
                  JOIN {course_modules} cm ON cm.instance = v.id AND cm.id = :cmid
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modulename
                 WHERE c.createdby > 0";
        $userlist->add_from_sql("userid", $sql, $params);
        $sql = "SELECT s.userid
                  FROM {videoprogress_sessions} s
                  JOIN {videoprogress} v ON v.id = s.videoprogressid
                  JOIN {course_modules} cm ON cm.instance = v.id AND cm.id = :cmid
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modulename";
        $userlist->add_from_sql("userid", $sql, $params);
    }

    /**
     * Exports the approved user progress, sessions, captions, and interactions through the Privacy API.
     *
     * @param approved_contextlist $contextlist Approved Privacy API context list.
     * @return void This method does not return a value.
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;
        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_module) {
                continue;
            }
            $cm = get_coursemodule_from_id("videoprogress", $context->instanceid, 0, false, IGNORE_MISSING);
            if (!$cm) {
                continue;
            }
            $activity = $DB->get_record("videoprogress", ["id" => $cm->instance], "*", MUST_EXIST);
            $progress = $DB->get_record("videoprogress_progress", ["videoprogressid" => $activity->id, "userid" => $userid]);
            if ($progress) {
                $export = clone $progress;
                unset($export->id, $export->videoprogressid, $export->userid);
                $export->completed = transform::yesno($export->completed);
                $export->confirmation = transform::yesno($export->confirmation);
                $export->timecreated = transform::datetime($export->timecreated);
                $export->timemodified = transform::datetime($export->timemodified);
                $export->confirmationtime = $export->confirmationtime ? transform::datetime($export->confirmationtime) : '';
                writer::with_context($context)->export_data([get_string('privacy:progresspath', "videoprogress")], $export);
            }
            $sessions = $DB->get_records("videoprogress_sessions",
                ["videoprogressid" => $activity->id, "userid" => $userid], "timestart");
            $sessionexports = [];
            foreach ($sessions as $session) {
                unset($session->id, $session->videoprogressid, $session->userid);
                foreach (["timestart", "timeend", "lastheartbeat", "lastclienttime", "timecreated", "timemodified"] as $field) {
                    $session->{$field} = $session->{$field} ? transform::datetime($session->{$field}) : '';
                }
                $sessionexports[] = $session;
            }
            if ($sessionexports) {
                writer::with_context($context)->export_data([get_string('privacy:sessionspath', "videoprogress")],
                    (object)["sessions" => $sessionexports]);
            }
            $captions = $DB->get_records("videoprogress_captions", ["videoprogressid" => $activity->id, "createdby" => $userid]);
            $captionexports = [];
            foreach ($captions as $caption) {
                unset($caption->id, $caption->videoprogressid, $caption->createdby);
                $caption->timecreated = transform::datetime($caption->timecreated);
                $caption->timemodified = transform::datetime($caption->timemodified);
                $captionexports[] = $caption;
            }
            if ($captionexports) {
                writer::with_context($context)->export_data([get_string('privacy:captionspath', "videoprogress")],
                    (object)["captions" => $captionexports]);
            }
            $sql = "SELECT x.*, i.plugin, p.title AS pointtitle, p.timepoint
                      FROM {videoprogress_interactions} x
                      JOIN {videoprogress_pointitems} i ON i.id = x.itemid
                      JOIN {videoprogress_points} p ON p.id = i.pointid
                     WHERE p.videoprogressid = :activityid AND x.userid = :userid
                  ORDER BY p.timepoint, i.sortorder, x.id";
            $interactions = $DB->get_records_sql($sql, ["activityid" => $activity->id, "userid" => $userid]);
            $interactionexports = [];
            foreach ($interactions as $interaction) {
                unset($interaction->id, $interaction->itemid, $interaction->userid);
                $interaction->completed = transform::yesno($interaction->completed);
                foreach (["timecompleted", "timecreated", "timemodified"] as $field) {
                    $interaction->{$field} = $interaction->{$field} ? transform::datetime($interaction->{$field}) : '';
                }
                $interactionexports[] = $interaction;
            }
            if ($interactionexports) {
                writer::with_context($context)->export_data(
                    [get_string('privacy:interactionspath', "videoprogress")],
                    (object)["interactions" => $interactionexports]
                );
            }
        }
    }

    /**
     * Deletes all personal Video Progress data stored in the supplied module context.
     *
     * @param \context $context Module context used for permissions and File API access.
     * @return void This method does not return a value.
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;
        $activityid = self::activity_id($context);
        if (!$activityid) {
            return;
        }
        self::delete_interactions($activityid);
        $DB->delete_records("videoprogress_sessions", ["videoprogressid" => $activityid]);
        $DB->delete_records("videoprogress_progress", ["videoprogressid" => $activityid]);
        $DB->set_field("videoprogress_captions", "createdby", 0, ["videoprogressid" => $activityid]);
    }

    /**
     * Deletes the approved user personal data from the approved contexts.
     *
     * @param approved_contextlist $contextlist Approved Privacy API context list.
     * @return void This method does not return a value.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;
        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if ($activityid = self::activity_id($context)) {
                self::delete_interactions($activityid, [$userid]);
                $DB->delete_records("videoprogress_sessions", ["videoprogressid" => $activityid, "userid" => $userid]);
                $DB->delete_records("videoprogress_progress", ["videoprogressid" => $activityid, "userid" => $userid]);
                $DB->set_field("videoprogress_captions", "createdby", 0,
                    ["videoprogressid" => $activityid, "createdby" => $userid]);
            }
        }
    }

    /**
     * Deletes personal data for an approved list of users in a module context.
     *
     * @param approved_userlist $userlist Privacy API user list.
     * @return void This method does not return a value.
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;
        $activityid = self::activity_id($userlist->get_context());
        $userids = $userlist->get_userids();
        if (!$activityid || !$userids) {
            return;
        }
        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, "userid");
        $params["activityid"] = $activityid;
        self::delete_interactions($activityid, $userids);
        $DB->delete_records_select("videoprogress_sessions", "videoprogressid = :activityid AND userid {$insql}", $params);
        $DB->delete_records_select("videoprogress_progress", "videoprogressid = :activityid AND userid {$insql}", $params);
        $DB->set_field_select("videoprogress_captions", "createdby", 0,
            "videoprogressid = :activityid AND createdby {$insql}", $params);
    }

    /**
     * Resolves the Video Progress activity identifier from a module context.
     *
     * @param \context $context Module context used for permissions and File API access.
     * @return int The resolved identifier or numeric value.
     */
    private static function activity_id(\context $context): int {
        if (!$context instanceof \context_module) {
            return 0;
        }
        $cm = get_coursemodule_from_id("videoprogress", $context->instanceid, 0, false, IGNORE_MISSING);
        return $cm ? (int)$cm->instance : 0;
    }

    /**
     * Deletes synchronized interaction responses for an activity and optional user list.
     *
     * @param int $activityid Video Progress activity identifier.
     * @param array $userids User identifiers included in the operation.
     * @return void This method does not return a value.
     */
    private static function delete_interactions(int $activityid, array $userids = []): void {
        global $DB;
        $params = ["activityid" => $activityid];
        $usercondition = '';
        if ($userids) {
            [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, "interactionuser");
            $usercondition = "userid {$insql} AND ";
            $params += $inparams;
        }
        $DB->delete_records_select(
            "videoprogress_interactions",
            "{$usercondition}itemid IN (
                SELECT i.id
                  FROM {videoprogress_pointitems} i
                  JOIN {videoprogress_points} p ON p.id = i.pointid
                 WHERE p.videoprogressid = :activityid
            )",
            $params
        );
    }
}
