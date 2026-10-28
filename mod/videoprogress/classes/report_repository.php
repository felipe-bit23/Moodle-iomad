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
 * report_repository.php
 *
 * @package   mod_videoprogress
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_videoprogress;

use coding_exception;
use context_course;
use core_user\fields;
use dml_exception;
use stdClass;

/**
 * Centralizes portable paginated SQL queries used by Video Progress reports.
 */
class report_repository {
    /**
     * @var stdClass
     */
    private stdClass $activity;
    /**
     * @var stdClass
     */
    private stdClass $cm;
    /**
     * @var context_course|\core\context\course|false
     */
    private context_course $coursecontext;

    /**
     * Initialises the service with the dependencies required by its operations.
     *
     * @param stdClass $activity Activity configuration record.
     * @param stdClass $cm Course module record or course-module information object.
     */
    public function __construct(stdClass $activity, stdClass $cm) {
        $this->activity = $activity;
        $this->cm = $cm;
        $this->coursecontext = context_course::instance($cm->course);
    }

    /**
     * Returns one paginated page of enrolled students and their progress data.
     *
     * @param report_filters $filters Normalized report filters.
     * @return array Structured data produced by the operation.
     * @throws coding_exception
     * @throws dml_exception
     */
    public function get_students(report_filters $filters): array {
        global $DB;

        [$from, $where, $params] = $this->build_query($filters);
        $userfields = fields::for_name()->with_identity($this->coursecontext)->get_sql("u", false, '', "userid");
        $sql = "SELECT u.id {$userfields->selects}, u.email, u.picture, u.imagealt,
                       p.id AS progressid, COALESCE(p.duration, 0) AS duration,
                       COALESCE(p.lastposition, 0) AS lastposition,
                       COALESCE(p.uniquewatched, 0) AS uniquewatched,
                       COALESCE(p.totalwatchtime, 0) AS totalwatchtime,
                       COALESCE(p.percent, 0) AS percent, p.watchedsegments, p.viewmap,
                       COALESCE(p.completed, 0) AS completed, COALESCE(p.confirmation, 0) AS confirmation,
                       COALESCE(p.timemodified, 0) AS lastview {$from}
                 WHERE {$where}
              ORDER BY {$this->order_by($filters)}";
        $recordparams = array_merge($params, $userfields->params);
        $records = $DB->get_records_sql($sql, $recordparams, $filters->page * $filters->perpage, $filters->perpage);
        $count = $DB->count_records_sql("SELECT COUNT(1) {$from} WHERE {$where}", $params);
        return ["records" => $records, "total" => $count];
    }

    /**
     * Returns only the user identifiers matching the active report filters.
     *
     * @param report_filters $filters Normalized report filters.
     * @return array Structured data produced by the operation.
     * @throws dml_exception
     * @throws coding_exception
     */
    public function get_filtered_user_ids(report_filters $filters): array {
        global $DB;
        [$from, $where, $params] = $this->build_query($filters);
        return $DB->get_fieldset_sql("SELECT u.id {$from} WHERE {$where}", $params);
    }

    /**
     * Calculates report summary totals and averages without loading all students.
     *
     * @param report_filters $filters Normalized report filters.
     * @return stdClass The loaded, created, or updated database record.
     * @throws dml_exception
     * @throws coding_exception
     */
    public function get_summary(report_filters $filters): stdClass {
        global $DB;
        $summaryfilters = clone $filters;
        $summaryfilters->search = '';
        $summaryfilters->status = "all";
        $summaryfilters->percentrange = "all";
        $summaryfilters->lastview = 0;
        [$from, $where, $params] = $this->build_query($summaryfilters);
        $sql = "SELECT COUNT(1) AS enrolled,
                       SUM(CASE WHEN p.id IS NOT NULL AND p.totalwatchtime > 0 THEN 1 ELSE 0 END) AS started,
                       SUM(CASE WHEN p.id IS NULL OR p.totalwatchtime <= 0 THEN 1 ELSE 0 END) AS neverstarted,
                       SUM(CASE WHEN p.id IS NOT NULL AND p.totalwatchtime > 0 AND p.completed = 0 THEN 1 ELSE 0 END) AS inprogress,
                       SUM(CASE WHEN p.completed = 1 THEN 1 ELSE 0 END) AS completed,
                       AVG(COALESCE(p.percent, 0)) AS averagepercent,
                       AVG(COALESCE(p.uniquewatched, 0)) AS averageunique,
                       AVG(COALESCE(p.totalwatchtime, 0)) AS averagetotal,
                       SUM(COALESCE(p.totalwatchtime, 0)) AS totalwatchtime {$from}
                 WHERE {$where}";
        return $DB->get_record_sql($sql, $params, MUST_EXIST);
    }

    /**
     * Returns playback sessions for the selected student and activity.
     *
     * @param int $userid Target user identifier.
     * @return array Structured data produced by the operation.
     * @throws dml_exception
     */
    public function get_sessions(int $userid): array {
        global $DB;
        return $DB->get_records("videoprogress_sessions", [
            "videoprogressid" => $this->activity->id,
            "userid" => $userid,
        ], 'timestart DESC');
    }

    /**
     * Returns the persisted progress record for a student and activity.
     *
     * @param int $userid Target user identifier.
     * @return stdClass|null The loaded, created, or updated database record.
     * @throws dml_exception
     */
    public function get_progress(int $userid): stdClass|null {
        global $DB;
        return $DB->get_record("videoprogress_progress", [
            "videoprogressid" => $this->activity->id,
            "userid" => $userid,
        ]) ?: null;
    }

    /**
     * Returns the best known duration for the selected activity.
     *
     * @return float The calculated value in seconds or percentage units.
     * @throws dml_exception
     */
    public function get_activity_duration(): float {
        global $DB;
        return (float)$DB->get_field_sql(
            'SELECT MAX(duration) FROM {videoprogress_progress} WHERE videoprogressid = :activityid',
            ["activityid" => $this->activity->id]
        );
    }

    /**
     * Returns Video Progress activities from the course with summarized statistics.
     *
     * @return array Structured data produced by the operation.
     * @throws dml_exception
     */
    public function get_course_activities(): array {
        global $DB;
        $sql = "SELECT v.*, cm.id AS cmid,
                       COUNT(p.id) AS started,
                       SUM(CASE WHEN p.completed = 1 THEN 1 ELSE 0 END) AS completed,
                       AVG(COALESCE(p.percent, 0)) AS averagepercent,
                       MAX(COALESCE(p.duration, 0)) AS duration
                  FROM {videoprogress} v
                  JOIN {course_modules} cm ON cm.instance = v.id
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modulename
             LEFT JOIN {videoprogress_progress} p ON p.videoprogressid = v.id
                 WHERE v.course = :courseid
              GROUP BY v.id, cm.id
              ORDER BY v.name";
        return $DB->get_records_sql($sql, ["modulename" => "videoprogress", "courseid" => $this->activity->course]);
    }

    /**
     * Returns visible group names associated with the supplied users.
     *
     * @param array $userids User identifiers included in the operation.
     * @return array Structured data produced by the operation.
     * @throws coding_exception
     * @throws dml_exception
     */
    public function get_group_names(array $userids): array {
        global $DB;
        if (!$userids) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, "uid");
        $params["courseid"] = $this->activity->course;
        $records = $DB->get_records_sql("SELECT gm.id, gm.userid, g.name
                                           FROM {groups_members} gm
                                           JOIN {groups} g ON g.id = gm.groupid
                                          WHERE gm.userid {$insql} AND g.courseid = :courseid
                                       ORDER BY g.name", $params);
        $result = [];
        foreach ($records as $record) {
            $result[$record->userid][] = format_string($record->name);
        }
        return $result;
    }

    /**
     * Builds the portable SQL fragments and parameters for the filtered student report.
     *
     * @param report_filters $filters Normalized report filters.
     * @return array Structured data produced by the operation.
     * @throws coding_exception
     * @throws dml_exception
     */
    private function build_query(report_filters $filters): array {
        global $DB;

        [$enrolledsql, $enrolledparams] = get_enrolled_sql($this->coursecontext, 'mod/videoprogress:view', 0, true);
        $from = "FROM ({$enrolledsql}) eu
                JOIN {user} u ON u.id = eu.id
           LEFT JOIN {videoprogress_progress} p
                  ON p.userid = u.id AND p.videoprogressid = :activityid";
        $params = array_merge($enrolledparams, ["activityid" => $this->activity->id]);
        $conditions = ['u.deleted = 0'];

        if ($filters->groupid) {
            $conditions[] = 'EXISTS (SELECT 1 FROM {groups_members} gm WHERE gm.userid = u.id AND gm.groupid = :groupid)';
            $params["groupid"] = $filters->groupid;
        } else if ($filters->restrictgroups && $filters->allowedgroupids) {
            [$groupsql, $groupparams] = $DB->get_in_or_equal($filters->allowedgroupids, SQL_PARAMS_NAMED, "allowedgroup");
            $conditions[] = "EXISTS (SELECT 1 FROM {groups_members} gm WHERE gm.userid = u.id AND gm.groupid {$groupsql})";
            $params = array_merge($params, $groupparams);
        } else if ($filters->restrictgroups && !$filters->allowedgroupids) {
            $conditions[] = '1 = 0';
        }
        if ($filters->search !== '') {
            $needle = '%' . $DB->sql_like_escape(strtolower($filters->search)) . '%';
            $fullname = $DB->sql_fullname('u.firstname', 'u.lastname');
            $conditions[] = '(' . implode(' OR ', [
                    $DB->sql_like('u.firstname', ':search1', false),
                    $DB->sql_like('u.lastname', ':search2', false),
                    $DB->sql_like($fullname, ':search3', false),
                    $DB->sql_like('u.email', ':search4', false),
                ]) . ')';
            foreach (range(1, 4) as $index) {
                $params["search{$index}"] = $needle;
            }
        }
        if ($filters->status === "notstarted") {
            $conditions[] = '(p.id IS NULL OR p.totalwatchtime <= 0)';
        } else if ($filters->status === "inprogress") {
            $conditions[] = 'p.totalwatchtime > 0 AND p.completed = 0';
        } else if ($filters->status === "completed") {
            $conditions[] = 'p.completed = 1';
        }
        $ranges = [
            "0" => [0, 0], '1-24' => [1, 24.999], '25-49' => [25, 49.999],
            '50-74' => [50, 74.999], '75-99' => [75, 99.999], "100" => [100, 100],
        ];
        if (isset($ranges[$filters->percentrange])) {
            $conditions[] = 'COALESCE(p.percent, 0) BETWEEN :percentmin AND :percentmax';
            [$params["percentmin"], $params["percentmax"]] = $ranges[$filters->percentrange];
        }
        if ($filters->lastview) {
            $conditions[] = 'p.timemodified >= :lastviewsince';
            $params["lastviewsince"] = time() - ($filters->lastview * DAYSECS);
        }
        return [$from, implode(' AND ', $conditions), $params];
    }

    /**
     * Returns a whitelisted portable SQL ORDER BY clause for the requested report sort.
     *
     * @param report_filters $filters Normalized report filters.
     * @return string The resolved or formatted string value.
     */
    private function order_by(report_filters $filters): string {
        $columns = [
            "name" => 'u.lastname, u.firstname',
            "percent" => 'COALESCE(p.percent, 0)',
            "uniquewatched" => 'COALESCE(p.uniquewatched, 0)',
            "totalwatchtime" => 'COALESCE(p.totalwatchtime, 0)',
            "lastview" => 'COALESCE(p.timemodified, 0)',
            "status" => 'COALESCE(p.completed, 0)',
        ];
        $direction = $filters->direction === "desc" ? "DESC" : "ASC";
        return $columns[$filters->sort] . ' ' . $direction . ', u.id ASC';
    }
}
