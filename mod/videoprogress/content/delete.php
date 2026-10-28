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
 * delete.php
 *
 * @package   mod_videoprogress
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_videoprogress\content\manager;

require('../../../config.php');

$id = required_param("id", PARAM_INT);
$type = required_param("type", PARAM_ALPHA);
$pointid = optional_param("pointid", 0, PARAM_INT);
$itemid = optional_param("itemid", 0, PARAM_INT);
$confirmed = optional_param("confirmed", 0, PARAM_BOOL);
$cm = get_coursemodule_from_id("videoprogress", $id, 0, false, MUST_EXIST);
$course = $DB->get_record("course", ["id" => $cm->course], "*", MUST_EXIST);
$activity = $DB->get_record("videoprogress", ["id" => $cm->instance], "*", MUST_EXIST);
$context = context_module::instance($cm->id);
require_login($course, true, $cm);
require_capability('mod/videoprogress:managecontent', $context);
$manager = new manager();
if (!in_array($type, ["point", "item"], true)) {
    throw new invalid_parameter_exception(get_string("invaliddeletetype", "videoprogress"));
}
$record = $type === "point" ? $manager->get_point($pointid, $activity->id) : $manager->get_item($itemid, $activity->id);
if ($type === "point") {
    $name = format_string($record->title);
} else {
    try {
        $name = $manager->get_plugin($record->plugin)->get_name();
    } catch (moodle_exception $exception) {
        $name = get_string("unavailableplugin", "videoprogress", $record->plugin);
    }
}
$returnurl = new moodle_url('/mod/videoprogress/content.php', ["id" => $cm->id]);
$urlparams = ["id" => $cm->id, "type" => $type] +
    ($type === "point" ? ["pointid" => $record->id] : ["itemid" => $record->id]);
$PAGE->set_url('/mod/videoprogress/content/delete.php', $urlparams);
$PAGE->set_title(get_string("deletecontent", "videoprogress"));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

if ($confirmed) {
    require_sesskey();
    if ($type === "point") {
        $manager->delete_point($record, $context);
    } else {
        $manager->delete_item($record, $context);
    }
    redirect($returnurl, get_string("contentdeleted", "videoprogress"));
}
echo $OUTPUT->header();
echo $OUTPUT->render_from_template('mod_videoprogress/delete_resource', [
    "title" => get_string("deletecontent", "videoprogress"),
    "message" => get_string("deletecontentconfirm", "videoprogress", $name),
    "actionurl" => $PAGE->url->out(false),
    "sesskey" => sesskey(),
    "returnurl" => $returnurl->out(false),
]);
echo $OUTPUT->footer();
