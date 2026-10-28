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
 * view.php
 *
 * @package   mod_videoprogress
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_videoprogress\caption_manager;
use mod_videoprogress\format;
use mod_videoprogress\progress_manager;
use mod_videoprogress\source\manager;

require('../../config.php');

$id = required_param("id", PARAM_INT);
$confirm = optional_param("confirm", 0, PARAM_BOOL);

$cm = get_coursemodule_from_id("videoprogress", $id, 0, false, MUST_EXIST);
$course = $DB->get_record("course", ["id" => $cm->course], "*", MUST_EXIST);
$activity = $DB->get_record("videoprogress", ["id" => $cm->instance], "*", MUST_EXIST);
$context = context_module::instance($cm->id);

require_login($course, true, $cm);
require_capability('mod/videoprogress:view', $context);

$PAGE->set_url('/mod/videoprogress/view.php', ["id" => $cm->id]);
$PAGE->set_title(format_string($activity->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

$progressmanager = new progress_manager();
if ($confirm) {
    require_sesskey();
    $progressmanager->confirm($activity, $cm, $USER->id);
    redirect($PAGE->url, get_string("confirmationsaved", "videoprogress"));
}

$progress = $DB->get_record("videoprogress_progress", [
    "videoprogressid" => $activity->id,
    "userid" => $USER->id,
]);
if (!$progress) {
    $progress = (object)[
        "duration" => 0,
        "lastposition" => 0,
        "uniquewatched" => 0,
        "totalwatchtime" => 0,
        "percent" => 0,
        "watchedsegments" => '[]',
        "viewmap" => '[]',
        "completed" => 0,
        "confirmation" => 0,
    ];
}

$completion = new completion_info($course);
if ($completion->is_enabled($cm)) {
    $completion->set_module_viewed($cm);
}
$event = mod_videoprogress\event\course_module_viewed::create([
    "objectid" => $activity->id,
    "context" => $context,
]);
$event->add_record_snapshot("course", $course);
$event->add_record_snapshot("course_modules", $cm);
$event->add_record_snapshot("videoprogress", $activity);
$event->trigger();

$player = mod_videoprogress\player_config::build($activity, $context);
$sourceplugin = (new manager())->get_plugin($activity->videosource);
$captions = $sourceplugin->supports_uploaded_captions()
    ? (new caption_manager())->get_published_tracks($activity->id, $context)
    : [];
$player["captions"] = $captions;
$playerclientconfig = $player;
unset($playerclientconfig["sourcetemplate"]);
$player["sourcehtml"] = $OUTPUT->render_from_template($player["sourcetemplate"], ["player" => $player]);
$materialcards = (new \mod_videoprogress\material\manager())->get_student_cards(
    $activity->id,
    $context,
    $cm->id
);
$contentdata = (new \mod_videoprogress\content\manager())->get_student_data(
    $activity->id,
    $USER->id,
    $context,
    $progress
);
$trackerconfig = [
    "cmid" => $cm->id,
    "lastposition" => (float)$progress->lastposition,
    "resumeplayback" => (int)$activity->resumeplayback,
    "allowseek" => (bool)$activity->allowseek,
    "maxplaybackrate" => (float)$activity->maxplaybackrate,
    "segments" => json_decode($progress->watchedsegments, true) ?: [],
    "source" => $activity->videosource,
    "player" => $playerclientconfig,
    "content" => ["cmid" => $cm->id] + $contentdata["client"],
];

$objectives = (new \mod_videoprogress\objective\manager())->get_student_items(
    $activity->id,
    $context,
    $cm->id
);
$duration = max((float)$progress->duration, 0);
$canmanagematerials = has_capability('mod/videoprogress:managematerials', $context);
$templatedata = [
    "name" => format_string($activity->name),
    "intro" => format_module_intro("videoprogress", $activity, $cm->id),
    "hasintro" => trim((string)$activity->intro) !== '',
    "objectives" => $objectives,
    "hasobjectives" => (bool)$objectives,
    "player" => $player,
    "materials" => $materialcards,
    "hasmaterials" => (bool)$materialcards,
    "canmanagematerials" => $canmanagematerials,
    "materialsmanageurl" => (string)new moodle_url('/mod/videoprogress/materials.php', ["id" => $cm->id]),
    "contentpoints" => $contentdata["points"],
    "hascontentpoints" => (bool)$contentdata["points"],
    "contentoverlays" => $contentdata["overlays"],
    "configjson" => json_encode($trackerconfig, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT),
    "progress" => [
        "percent" => round((float)$progress->percent, 2),
        "percentrounded" => (int)round((float)$progress->percent),
        "requiredpercent" => (int)$activity->completionpercent,
        "uniquewatched" => format::duration((float)$progress->uniquewatched),
        "duration" => format::duration($duration),
        "completed" => (bool)$progress->completed,
        "inprogress" => !$progress->completed && (float)$progress->percent > 0,
        "notstarted" => (float)$progress->percent <= 0,
    ],
    "timeline" => format::timeline($progress->viewmap, $duration),
    "timelinealternative" => format::segment_alternative($progress->watchedsegments),
    "confirmation" => [
        "required" => (bool)$activity->requireconfirmation,
        "available" => $activity->requireconfirmation
            && (float)$progress->percent >= (float)$activity->completionpercent
            && empty($progress->confirmation),
        "confirmed" => !empty($progress->confirmation),
        "url" => $PAGE->url->out(false),
        "sesskey" => sesskey(),
    ],
    "canviewreport" => has_capability('mod/videoprogress:viewreport', $context),
    "reporturl" => (string)new moodle_url('/mod/videoprogress/report/report.php', ["id" => $cm->id]),
    "canmanageresources" => has_capability('mod/videoprogress:managematerials', $context) ||
        has_capability('mod/videoprogress:managecontent', $context) ||
        has_capability('mod/videoprogress:manageobjectives', $context),
    "manageurl" => (string)new moodle_url('/mod/videoprogress/manage.php', ["id" => $cm->id]),
];
$templatedata["progress"]["watchedofduration"] = get_string("watchedofduration", "videoprogress", (object)[
    "uniquewatched" => $templatedata["progress"]["uniquewatched"],
    "duration" => $templatedata["progress"]["duration"],
]);

$PAGE->requires->strings_for_js([
    "resumequestion", "resumeyes", "resumeno", "trackingerror", "seekblocked",
    "activitycompleted", "pendingupdates", "invalidplayer",
    "interactionrequired", "interactionerror", "watchedpercent", "watchedofduration",
], "videoprogress");
$PAGE->requires->js_call_amd('mod_videoprogress/tracker', "init");
$PAGE->requires->js_call_amd('mod_videoprogress/timeline', "init");

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('mod_videoprogress/view', $templatedata);
echo $OUTPUT->footer();
