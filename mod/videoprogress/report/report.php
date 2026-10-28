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
 * report.php
 *
 * @package   mod_videoprogress
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_videoprogress\report_repository;
use mod_videoprogress\report_service;

require('../../../config.php');

$id = required_param("id", PARAM_INT);
$cm = get_coursemodule_from_id("videoprogress", $id, 0, false, MUST_EXIST);
$course = $DB->get_record("course", ["id" => $cm->course], "*", MUST_EXIST);
$activity = $DB->get_record("videoprogress", ["id" => $cm->instance], "*", MUST_EXIST);
$context = context_module::instance($cm->id);

require_login($course, true, $cm);
require_capability('mod/videoprogress:viewreport', $context);

$allgroups = groups_get_all_groups($course->id, 0, $cm->groupingid, 'g.id,g.name');
$accessallgroups = has_capability('moodle/site:accessallgroups', $context);
$allowedgroups = $accessallgroups ? $allgroups : groups_get_activity_allowed_groups($cm);
$restrictgroups = !$accessallgroups && groups_get_activity_groupmode($cm) == SEPARATEGROUPS;
$filters = mod_videoprogress\report_filters::from_request(
    array_map(static fn($group): int => (int)$group->id, $allowedgroups),
    $restrictgroups
);
$repository = new report_repository($activity, $cm);
$service = new report_service($activity, $cm, $repository);
$templatedata = $service->build($filters, $allowedgroups);
foreach ($templatedata["coursevideos"] as &$video) {
    $video["stats"] = get_string("coursevideostats", "videoprogress", (object)[
        "started" => $video["started"],
        "completed" => $video["completed"],
        "averagepercent" => $video["averagepercent"],
    ]);
}
unset($video);

$PAGE->set_url('/mod/videoprogress/report/report.php', ["id" => $cm->id] + $filters->url_params());
$PAGE->set_title(get_string("reporttitle", "videoprogress"));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

$PAGE->requires->strings_for_js(["datatableempty", "datatableloading"], "videoprogress");
$PAGE->requires->js_call_amd('mod_videoprogress/report', "init");

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('mod_videoprogress/report', $templatedata);
echo $OUTPUT->footer();
