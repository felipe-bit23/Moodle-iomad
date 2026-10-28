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
 * index.php
 *
 * @package   mod_videoprogress
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require('../../config.php');

$id = required_param("id", PARAM_INT);
$course = $DB->get_record("course", ["id" => $id], "*", MUST_EXIST);
require_course_login($course);
$coursecontext = context_course::instance($course->id);
$PAGE->set_url('/mod/videoprogress/index.php', ["id" => $course->id]);
$PAGE->set_title(get_string("modulenameplural", "videoprogress"));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($coursecontext);

$modinfo = get_fast_modinfo($course);

$cms = [];
$instanceids = [];

foreach ($modinfo->get_instances_of("videoprogress") as $cm) {
    if (!$cm->uservisible) {
        continue;
    }

    $cms[] = $cm;
    $instanceids[] = $cm->instance;
}

$activityrecords = [];

if ($instanceids) {
    $activityrecords = $DB->get_records_list(
        "videoprogress",
        "id",
        $instanceids,
        "",
        "id,name,completionpercent"
    );
}

$activities = [];

foreach ($cms as $cm) {
    if (!isset($activityrecords[$cm->instance])) {
        continue;
    }

    $activity = $activityrecords[$cm->instance];
    $context = context_module::instance($cm->id);

    $activities[] = [
        "name" => format_string($activity->name),
        "completionpercent" => $activity->completionpercent,
        "viewurl" => new moodle_url(
            '/mod/videoprogress/view.php',
            ["id" => $cm->id]
        ),
        "canreport" => has_capability(
            'mod/videoprogress:viewreport',
            $context
        ),
        "reporturl" => (string)new moodle_url(
            '/mod/videoprogress/report/report.php',
            ["id" => $cm->id]
        ),
    ];
}
echo $OUTPUT->header();
echo $OUTPUT->render_from_template('mod_videoprogress/index', [
    "coursename" => format_string($course->fullname),
    "activities" => $activities,
    "hasactivities" => (bool)$activities,
]);
echo $OUTPUT->footer();
