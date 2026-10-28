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
 * reset.php
 *
 * @package   mod_videoprogress
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_videoprogress\progress_manager;
use mod_videoprogress\report_access;
use mod_videoprogress\report_repository;
use mod_videoprogress\task\mass_reset;

require('../../../config.php');

$id = required_param("id", PARAM_INT);
$userid = optional_param("userid", 0, PARAM_INT);
$mass = optional_param("mass", 0, PARAM_BOOL);
$confirmed = optional_param("confirmed", 0, PARAM_BOOL);
$cm = get_coursemodule_from_id("videoprogress", $id, 0, false, MUST_EXIST);
$course = $DB->get_record("course", ["id" => $cm->course], "*", MUST_EXIST);
$activity = $DB->get_record("videoprogress", ["id" => $cm->instance], "*", MUST_EXIST);
$context = context_module::instance($cm->id);
require_login($course, true, $cm);
require_capability('mod/videoprogress:resetprogress', $context);

$accessallgroups = has_capability('moodle/site:accessallgroups', $context);
$allowedgroups = $accessallgroups
    ? groups_get_all_groups($course->id, 0, $cm->groupingid, 'g.id,g.name')
    : groups_get_activity_allowed_groups($cm);
$restrictgroups = !$accessallgroups && groups_get_activity_groupmode($cm) == SEPARATEGROUPS;
$filters = mod_videoprogress\report_filters::from_request(
    array_map(static fn($group): int => (int)$group->id, $allowedgroups),
    $restrictgroups
);
$repository = new report_repository($activity, $cm);
if ($mass) {
    $userids = array_map("intval", $repository->get_filtered_user_ids($filters));
} else {
    if (!$userid || !is_enrolled(context_course::instance($course->id), $userid, 'mod/videoprogress:view', true) ||
        !report_access::can_access_user($cm, $context, $userid)) {
        throw new moodle_exception("invaliduser");
    }
    $userids = [$userid];
}
$returnurl = new moodle_url('/mod/videoprogress/report/report.php', ["id" => $cm->id] + $filters->url_params());
$PAGE->set_url('/mod/videoprogress/report/reset.php',
    ["id" => $cm->id, "userid" => $userid, "mass" => $mass] + $filters->url_params());
$PAGE->set_title(get_string("resetprogress", "videoprogress"));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);


if ($confirmed) {
    require_sesskey();
    if (count($userids) > 100) {
        foreach (array_chunk($userids, 500) as $batch) {
            $task = new mass_reset();
            $task->set_custom_data(["cmid" => $cm->id, "actorid" => $USER->id, "userids" => $batch]);
            core\task\manager::queue_adhoc_task($task);
        }
        redirect($returnurl, get_string("massresetqueued", "videoprogress", count($userids)));
    }
    $manager = new progress_manager();
    foreach ($userids as $targetuserid) {
        $manager->reset($activity, $cm, $targetuserid, $USER->id);
    }
    redirect($returnurl, get_string("progressresetcount", "videoprogress", count($userids)));
}

$hiddenfields = [];
foreach ($filters->url_params() as $name => $value) {
    $hiddenfields[] = ["name" => $name, "value" => $value];
}
$targetname = '';
if (!$mass && $userid) {
    $targetname = fullname($DB->get_record("user", ["id" => $userid], "*", MUST_EXIST));
}
echo $OUTPUT->header();
echo $OUTPUT->render_from_template('mod_videoprogress/reset_confirmation', [
    "actionurl" => $PAGE->url->out(false),
    "cmid" => $cm->id,
    "userid" => $userid,
    "mass" => $mass,
    "count" => count($userids),
    "targetname" => $targetname,
    "sesskey" => sesskey(),
    "hiddenfields" => $hiddenfields,
    "returnurl" => $returnurl->out(false),
]);
echo $OUTPUT->footer();
