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
 * point.php
 *
 * @package   mod_videoprogress
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_videoprogress\content\manager;
use mod_videoprogress\content\timecode;
use mod_videoprogress\form\point_form;
use mod_videoprogress\player_config;

require('../../../config.php');

$id = required_param("id", PARAM_INT);
$pointid = optional_param("pointid", 0, PARAM_INT);
$cm = get_coursemodule_from_id("videoprogress", $id, 0, false, MUST_EXIST);
$course = $DB->get_record("course", ["id" => $cm->course], "*", MUST_EXIST);
$activity = $DB->get_record("videoprogress", ["id" => $cm->instance], "*", MUST_EXIST);
$context = context_module::instance($cm->id);
require_login($course, true, $cm);
require_capability('mod/videoprogress:managecontent', $context);
$manager = new manager();
$point = $pointid ? $manager->get_point($pointid, $activity->id) : null;
$urlparams = ["id" => $cm->id] + ($point ? ["pointid" => $point->id] : []);
$PAGE->set_url('/mod/videoprogress/content/point.php', $urlparams);
$PAGE->set_title($point ? get_string("editpoint", "videoprogress") : get_string("addpoint", "videoprogress"));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

$mform = new point_form($PAGE->url);
$mform->set_data($point ? (object)[
    "title" => $point->title,
    "timecode" => timecode::format($point->timepoint),
    "enabled" => $point->enabled,
] : (object)["enabled" => 1]);
$returnurl = new moodle_url('/mod/videoprogress/content.php', ["id" => $cm->id]);
if ($mform->is_cancelled()) {
    redirect($returnurl);
} else if ($data = $mform->get_data()) {
    $manager->save_point($activity->id, $point, $data);
    redirect($returnurl, get_string("pointsaved", "videoprogress"));
}

$player = player_config::build($activity, $context);
$playerclientconfig = $player;
unset($playerclientconfig["sourcetemplate"]);
$player["sourcehtml"] = $OUTPUT->render_from_template($player["sourcetemplate"], ["player" => $player]);

$PAGE->requires->js_call_amd('mod_videoprogress/point', 'init');

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('mod_videoprogress/point_form_page', [
    "backurl" => $returnurl->out(false),
    "eyebrow" => format_string($activity->name),
    "title" => $point ? get_string("editpoint", "videoprogress") : get_string("addpoint", "videoprogress"),
    "description" => get_string("pointformhelp", "videoprogress"),
    "formhtml" => $mform->render(),
    "player" => $player,
    "configjson" => json_encode(
        $playerclientconfig,
        JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
    ),
]);
echo $OUTPUT->footer();
