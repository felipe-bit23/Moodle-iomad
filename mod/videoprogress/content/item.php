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
 * item.php
 *
 * @package   mod_videoprogress
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_videoprogress\content\manager;
use mod_videoprogress\form\point_item_form;

require('../../../config.php');

$id = required_param("id", PARAM_INT);
$itemid = optional_param("itemid", 0, PARAM_INT);
$pointid = optional_param("pointid", 0, PARAM_INT);
$pluginname = optional_param("plugin", '', PARAM_PLUGIN);
$cm = get_coursemodule_from_id("videoprogress", $id, 0, false, MUST_EXIST);
$course = $DB->get_record("course", ["id" => $cm->course], "*", MUST_EXIST);
$activity = $DB->get_record("videoprogress", ["id" => $cm->instance], "*", MUST_EXIST);
$context = context_module::instance($cm->id);
require_login($course, true, $cm);
require_capability('mod/videoprogress:managecontent', $context);
$manager = new manager();
$item = $itemid ? $manager->get_item($itemid, $activity->id) : null;
$pointid = $item ? $item->pointid : $pointid;
$point = $manager->get_point($pointid, $activity->id);
$pluginname = $item ? $item->plugin : $pluginname;
$plugin = $manager->get_plugin($pluginname);
$urlparams = ["id" => $cm->id, "pointid" => $point->id, "plugin" => $pluginname];
if ($item) {
    $urlparams["itemid"] = $item->id;
}
$PAGE->set_url('/mod/videoprogress/content/item.php', $urlparams);
$PAGE->set_title($item ? get_string("editpointcontent", "videoprogress") : get_string("addpointcontent", "videoprogress"));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

$mform = new point_item_form($PAGE->url, [
    "plugin" => $plugin, "item" => $item, "point" => $point, "context" => $context,
]);
$mform->set_data($manager->prepare_item_form_data($item, $context, $pluginname, $point->id));
$returnurl = new moodle_url('/mod/videoprogress/content.php', ["id" => $cm->id]);
if ($mform->is_cancelled()) {
    redirect($returnurl);
} else if ($data = $mform->get_data()) {
    $manager->save_item($point, $item, $data, $context);
    redirect($returnurl, get_string("pointcontentsaved", "videoprogress"));
}
echo $OUTPUT->header();
echo $OUTPUT->render_from_template('mod_videoprogress/form_page', [
    "backurl" => $returnurl->out(false),
    "eyebrow" => format_string($point->title),
    "title" => $item ? get_string("editpointcontent", "videoprogress") : get_string("addpointcontent", "videoprogress"),
    "description" => $plugin->get_name(),
    "formhtml" => $mform->render(),
]);
echo $OUTPUT->footer();
