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

use mod_videoprogress\material\manager;

require('../../../config.php');

$id = required_param("id", PARAM_INT);
$materialid = required_param("materialid", PARAM_INT);
$cm = get_coursemodule_from_id("videoprogress", $id, 0, false, MUST_EXIST);
$course = $DB->get_record("course", ["id" => $cm->course], "*", MUST_EXIST);
$activity = $DB->get_record("videoprogress", ["id" => $cm->instance], "*", MUST_EXIST);
$context = context_module::instance($cm->id);
require_login($course, true, $cm);
require_capability('mod/videoprogress:view', $context);
$manager = new manager();
$material = $manager->get_material($materialid, $activity->id);
if (empty($material->enabled)) {
    throw new moodle_exception("materialdisabled", "videoprogress");
}
$PAGE->set_url('/mod/videoprogress/material/view.php', ["id" => $cm->id, "materialid" => $material->id]);
$PAGE->set_title(format_string($material->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('mod_videoprogress/material_page', [
    "backurl" => new moodle_url('/mod/videoprogress/view.php', ["id" => $cm->id]),
    "content" => $manager->get_plugin($material->plugin)->render_full($material, $context, $cm->id),
]);
echo $OUTPUT->footer();
