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
 * edit.php
 *
 * @package   mod_videoprogress
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_videoprogress\form\material_form;
use mod_videoprogress\material\manager;

require('../../../config.php');

$id = required_param("id", PARAM_INT);
$materialid = optional_param("materialid", 0, PARAM_INT);
$pluginname = optional_param("plugin", '', PARAM_PLUGIN);
$cm = get_coursemodule_from_id("videoprogress", $id, 0, false, MUST_EXIST);
$course = $DB->get_record("course", ["id" => $cm->course], "*", MUST_EXIST);
$activity = $DB->get_record("videoprogress", ["id" => $cm->instance], "*", MUST_EXIST);
$context = context_module::instance($cm->id);
require_login($course, true, $cm);
require_capability('mod/videoprogress:managematerials', $context);
$manager = new manager();
$material = $materialid ? $manager->get_material($materialid, $activity->id) : null;
$pluginname = $material ? $material->plugin : $pluginname;
$plugin = $manager->get_plugin($pluginname);
$urlparams = ["id" => $cm->id, "plugin" => $pluginname];
if ($material) {
    $urlparams["materialid"] = $material->id;
}
$PAGE->set_url('/mod/videoprogress/material/edit.php', $urlparams);
$PAGE->set_title($material ? get_string("editmaterial", "videoprogress") : get_string("addmaterial", "videoprogress"));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

$mform = new material_form($PAGE->url, [
    "plugin" => $plugin, "material" => $material, "context" => $context,
]);
$mform->set_data($manager->prepare_form_data($material, $context, $pluginname));
$returnurl = new moodle_url('/mod/videoprogress/materials.php', ["id" => $cm->id]);
if ($mform->is_cancelled()) {
    redirect($returnurl);
} else if ($data = $mform->get_data()) {
    $manager->save($activity->id, $material, $data, $context);
    redirect($returnurl, get_string("materialsaved", "videoprogress"));
}
echo $OUTPUT->header();
echo $OUTPUT->render_from_template('mod_videoprogress/form_page', [
    "backurl" => $returnurl->out(false),
    "eyebrow" => format_string($activity->name),
    "title" => $material ? get_string("editmaterial", "videoprogress") : get_string("addmaterial", "videoprogress"),
    "description" => $plugin->get_name(),
    "formhtml" => $mform->render(),
]);
echo $OUTPUT->footer();
