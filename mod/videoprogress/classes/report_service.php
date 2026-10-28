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
 * report_service.php
 *
 * @package   mod_videoprogress
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_videoprogress;

use coding_exception;
use context_module;
use core\exception\moodle_exception;
use dml_exception;
use moodle_url;
use stdClass;
use user_picture;

/**
 * Transforms repository results into report analytics and Mustache-ready data.
 */
class report_service {
    /**
     * @var stdClass
     */
    private stdClass $activity;
    /**
     * @var stdClass
     */
    private stdClass $cm;
    /**
     * @var report_repository
     */
    private report_repository $repository;

    /**
     * Initialises the service with the dependencies required by its operations.
     *
     * @param stdClass $activity Activity configuration record.
     * @param stdClass $cm Course module record or course-module information object.
     * @param report_repository $repository repository value used by the operation.
     */
    public function __construct(stdClass $activity, stdClass $cm, report_repository $repository) {
        $this->activity = $activity;
        $this->cm = $cm;
        $this->repository = $repository;
    }

    /**
     * Builds the structured configuration or presentation data required by the caller.
     *
     * @param report_filters $filters Normalized report filters.
     * @param array $groups groups value used by the operation.
     * @return array Structured data produced by the operation.
     * @throws coding_exception
     * @throws moodle_exception
     * @throws dml_exception
     */
    public function build(report_filters $filters, array $groups): array {
        global $CFG;

        $result = $this->repository->get_students($filters);
        $summary = $this->repository->get_summary($filters);
        $students = [];
        foreach ($result["records"] as $record) {
            $students[] = $this->student($record);
        }
        $pagecount = max(1, (int)ceil($result["total"] / $filters->perpage));
        $baseparams = $filters->url_params() + ["id" => $this->cm->id];
        $pages = [];
        $start = max(0, $filters->page - 2);
        $end = min($pagecount - 1, $filters->page + 2);
        for ($page = $start; $page <= $end; $page++) {
            $pages[] = [
                "number" => $page + 1,
                "active" => $page === $filters->page,
                "url" => (string)new moodle_url('/mod/videoprogress/report/report.php', $baseparams + ["page" => $page]),
            ];
        }
        $views = view_map::decode($this->activity->aggregateviewmap ?? '');
        $users = view_map::decode($this->activity->aggregateusermap ?? '');
        $duration = $this->repository->get_activity_duration();
        $aggregatetimeline = $this->aggregate_timeline($views, $users, $duration);
        $insights = view_map::insights($views, $duration);
        $coursevideos = [];
        foreach ($this->repository->get_course_activities() as $video) {
            $videocontext = context_module::instance($video->cmid);
            if (!has_capability('mod/videoprogress:viewreport', $videocontext)) {
                continue;
            }
            $coursevideos[] = [
                "name" => format_string($video->name),
                "active" => (int)$video->id === (int)$this->activity->id,
                "started" => (int)$video->started,
                "completed" => (int)$video->completed,
                "averagepercent" => (int)round((float)$video->averagepercent),
                "url" => (string)new moodle_url('/mod/videoprogress/report/report.php', ["id" => $video->cmid]),
                "timeline" => format::timeline($video->aggregateviewmap, (float)$video->duration, true),
            ];
        }
        $rate = $summary->enrolled > 0 ? ((float)$summary->completed / (float)$summary->enrolled) * 100 : 0;
        return [
            "activityname" => format_string($this->activity->name),
            "coursename" => format_string(get_course($this->activity->course)->fullname),
            "viewurl" => (string)new moodle_url('/mod/videoprogress/view.php', ["id" => $this->cm->id]),
            "coursevideos" => $coursevideos,
            "summary" => [
                "enrolled" => (int)$summary->enrolled,
                "started" => (int)$summary->started,
                "neverstarted" => (int)$summary->neverstarted,
                "inprogress" => (int)$summary->inprogress,
                "completed" => (int)$summary->completed,
                "averagepercent" => (int)round((float)$summary->averagepercent),
                "averageunique" => format::duration((float)$summary->averageunique),
                "averagetotal" => format::duration((float)$summary->averagetotal),
                "totalwatchtime" => format::duration((float)$summary->totalwatchtime),
                "completionrate" => (int)round($rate),
            ],
            "aggregatetimeline" => $aggregatetimeline,
            "hasaggregateddata" => array_sum($views) > 0,
            "insights" => $this->format_insights($insights),
            "filters" => $this->filter_data($filters, $groups),
            "students" => $students,
            "hasstudents" => (bool)$students,
            "totalresults" => (int)$result["total"],
            "pagination" => [
                "pages" => $pages,
                "hasprevious" => $filters->page > 0,
                "previousurl" => (new moodle_url('/mod/videoprogress/report/report.php',
                    $baseparams + ["page" => max(0, $filters->page - 1)]))->out(false),
                "hasnext" => $filters->page + 1 < $pagecount,
                "nexturl" => (new moodle_url('/mod/videoprogress/report/report.php',
                    $baseparams + ["page" => min($pagecount - 1, $filters->page + 1)]))->out(false),
            ],
            "exporturl" => (string)new moodle_url('/mod/videoprogress/report/export.php', $baseparams),
            "massreseturl" => (string)new moodle_url('/mod/videoprogress/report/reset.php', $baseparams + ["mass" => 1]),
            "canreset" => has_capability('mod/videoprogress:resetprogress', context_module::instance($this->cm->id)),
            "canexport" => has_capability('mod/videoprogress:exportreport', context_module::instance($this->cm->id)),
            "datatableconfig" => json_encode([
                "jsurl" => $CFG->wwwroot . '/mod/videoprogress/vendor/datatables/dataTables.min.js',
                "cssurl" => $CFG->wwwroot . '/mod/videoprogress/vendor/datatables/dataTables.css',
            ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT),
        ];
    }

    /**
     * Transforms one report database row into Mustache-ready student analytics.
     *
     * @param stdClass $record Database record being processed.
     * @return array Structured data produced by the operation.
     * @throws \core\exception\coding_exception
     * @throws moodle_exception
     * @throws coding_exception
     */
    public function student(stdClass $record): array {
        global $PAGE;

        $picture = new user_picture($record);
        $picture->size = 50;
        $percent = (float)$record->percent;
        $statuskey = $record->completed ? "statuscompleted" : ($percent > 0 ? "statusinprogress" : "statusnotstarted");
        return [
            "userid" => (int)$record->id,
            "fullname" => fullname($record),
            "email" => s($record->email),
            "avatarurl" => $picture->get_url($PAGE)->out(false),
            "percent" => round($percent, 2),
            "percentrounded" => (int)round($percent),
            "uniquewatched" => format::duration((float)$record->uniquewatched),
            "totalwatchtime" => format::duration((float)$record->totalwatchtime),
            "lastposition" => format::duration((float)$record->lastposition),
            "lastview" => $record->lastview ? userdate($record->lastview,
                get_string("strftimedatetimeshort", "langconfig")) : get_string("never"),
            "status" => get_string($statuskey, "videoprogress"),
            "statusclass" => $record->completed ? "success" : ($percent > 0 ? "info" : "secondary"),
            "timeline" => format::timeline($record->viewmap, (float)$record->duration),
            "timelinealternative" => format::segment_alternative($record->watchedsegments),
            "detailsurl" => (new moodle_url('/mod/videoprogress/report/user.php',
                ["id" => $this->cm->id, "userid" => $record->id]))->out(false),
            "reseturl" => (new moodle_url('/mod/videoprogress/report/reset.php',
                ["id" => $this->cm->id, "userid" => $record->id]))->out(false),
        ];
    }

    /**
     * Converts normalized filters into Mustache-ready values and selected options.
     *
     * @param report_filters $filters Normalized report filters.
     * @param array $groups groups value used by the operation.
     * @return array Structured data produced by the operation.
     * @throws coding_exception
     */
    private function filter_data(report_filters $filters, array $groups): array {
        $groupoptions = [
            [
                "value" => 0,
                "label" => get_string("allgroups", "videoprogress"),
                "selected" => $filters->groupid === 0,
            ],
        ];
        foreach ($groups as $group) {
            $groupoptions[] = [
                "value" => $group->id,
                "label" => format_string($group->name),
                "selected" => $filters->groupid === (int)$group->id,
            ];
        }
        return [
            "action" => (string)new moodle_url('/mod/videoprogress/report/report.php'),
            "cmid" => $this->cm->id,
            "search" => s($filters->search),
            "groups" => $groupoptions,
            "statuses" => $this->options(["all", "notstarted", "inprogress", "completed"],
                $filters->status, 'filterstatus:'),
            "percentranges" => $this->options(["all", "0", '1-24', '25-49', '50-74', '75-99', "100"],
                $filters->percentrange, 'filterpercent:'),
            "lastviews" => $this->options(["0", "7", "30", "90"], (string)$filters->lastview, 'filterlastview:'),
            "perpages" => $this->options(["20", "50", "100"], (string)$filters->perpage, 'perpage:'),
            "sorts" => $this->options(["name", "percent", "uniquewatched", "totalwatchtime", "lastview", "status"],
                $filters->sort, 'sort:'),
            "directions" => $this->options(["asc", "desc"], $filters->direction, 'direction:'),
        ];
    }

    /**
     * Converts option maps into Mustache-ready records with selected states.
     *
     * @param array $values values value used by the operation.
     * @param string $selected selected value used by the operation.
     * @param string $prefix prefix value used by the operation.
     * @return array Structured data produced by the operation.
     * @throws coding_exception
     */
    private function options(array $values, string $selected, string $prefix): array {
        return array_map(static fn(string $value): array => [
            "value" => $value,
            "label" => get_string($prefix . $value, "videoprogress"),
            "selected" => $value === $selected,
        ], $values);
    }

    /**
     * Builds the aggregated timeline and its pedagogical insights for the report.
     *
     * @param array $views views value used by the operation.
     * @param array $users users value used by the operation.
     * @param float $duration Authoritative video duration in seconds.
     * @return array Structured data produced by the operation.
     * @throws coding_exception
     */
    private function aggregate_timeline(array $views, array $users, float $duration): array {
        $maximum = max(1, max($views));
        $bucketduration = $duration > 0 ? $duration / count($views) : 0;
        $items = [];
        foreach ($views as $index => $value) {
            $start = $index * $bucketduration;
            $end = min($duration, ($index + 1) * $bucketduration);
            $items[] = [
                "level" => $value > 0 ? max(1, min(5, (int)ceil(($value / $maximum) * 5))) : 0,
                "views" => (int)$value,
                "users" => (int)($users[$index] ?? 0),
                "tooltip" => get_string("reporttimelinetooltip", "videoprogress", (object)[
                    "start" => format::duration($start),
                    "end" => format::duration($end),
                    "views" => $value,
                    "users" => $users[$index] ?? 0,
                ]),
            ];
        }
        return $items;
    }

    /**
     * Formats timeline insight ranges and values for the analytics template.
     *
     * @param array $insights Raw view-map insight values.
     * @return array Structured data produced by the operation.
     * @throws coding_exception
     */
    private function format_insights(array $insights): array {
        $result = [];
        foreach ($insights as $key => $value) {
            if ($value) {
                $result[$key] = [
                    "range" => get_string("timerange", "videoprogress", (object)[
                        "start" => format::duration($value["start"]),
                        "end" => format::duration($value["end"]),
                    ]),
                    "views" => $value["views"],
                ];
            }
        }
        return $result;
    }
}
