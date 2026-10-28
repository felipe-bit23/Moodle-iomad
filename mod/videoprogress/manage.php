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
 * manage.php
 *
 * @package   mod_videoprogress
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require('../../config.php');

$id = required_param("id", PARAM_INT);
$cm = get_coursemodule_from_id("videoprogress", $id, 0, false, MUST_EXIST);
$course = $DB->get_record("course", ["id" => $cm->course], "*", MUST_EXIST);
$activity = $DB->get_record("videoprogress", ["id" => $cm->instance], "*", MUST_EXIST);
$context = context_module::instance($cm->id);
require_login($course, true, $cm);
$canmaterials = has_capability('mod/videoprogress:managematerials', $context);
$cancontent = has_capability('mod/videoprogress:managecontent', $context);
$canobjectives = has_capability('mod/videoprogress:manageobjectives', $context);
if (!$canmaterials && !$cancontent && !$canobjectives) {
    throw new required_capability_exception($context, 'mod/videoprogress:managecontent', "nopermissions", '');
}
$PAGE->set_url('/mod/videoprogress/manage.php', ["id" => $cm->id]);
$PAGE->set_title(get_string("manageresources", "videoprogress"));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('mod_videoprogress/manage_resources', [
    "activityname" => format_string($activity->name),
    "backurl" => (string)new moodle_url('/mod/videoprogress/view.php', ["id" => $cm->id]),
    "canmaterials" => $canmaterials,
    "materialsurl" => (string)new moodle_url('/mod/videoprogress/materials.php', ["id" => $cm->id]),
    "cancontent" => $cancontent,
    "contenturl" => (string)new moodle_url('/mod/videoprogress/content.php', ["id" => $cm->id]),
    "canobjectives" => $canobjectives,
    "objectivesurl" => (string)new moodle_url('/mod/videoprogress/objectives.php', ["id" => $cm->id]),
    "captionsurl" => (string)new moodle_url('/mod/videoprogress/captions.php', ["id" => $cm->id]),
    "cancaptions" => has_capability('mod/videoprogress:managecaptions', $context),
]);
echo $OUTPUT->footer();
