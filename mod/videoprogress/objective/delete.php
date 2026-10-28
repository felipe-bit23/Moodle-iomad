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

use mod_videoprogress\objective\manager;

require('../../../config.php');

$id = required_param("id", PARAM_INT);
$objectiveid = required_param("objectiveid", PARAM_INT);
$confirmed = optional_param("confirmed", 0, PARAM_BOOL);
$cm = get_coursemodule_from_id("videoprogress", $id, 0, false, MUST_EXIST);
$course = $DB->get_record("course", ["id" => $cm->course], "*", MUST_EXIST);
$activity = $DB->get_record("videoprogress", ["id" => $cm->instance], "*", MUST_EXIST);
$context = context_module::instance($cm->id);
require_login($course, true, $cm);
require_capability('mod/videoprogress:manageobjectives', $context);

$manager = new manager();
$objective = $manager->get_objective($objectiveid, $activity->id);
$returnurl = new moodle_url('/mod/videoprogress/objectives.php', ["id" => $cm->id]);
$PAGE->set_url('/mod/videoprogress/objective/delete.php', [
    "id" => $cm->id,
    "objectiveid" => $objective->id,
]);
$PAGE->set_title(get_string("deleteobjective", "videoprogress"));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

if ($confirmed) {
    require_sesskey();
    $manager->delete($objective, $context);
    redirect($returnurl, get_string("objectivedeleted", "videoprogress"));
}

try {
    $summary = $manager->get_plugin($objective->plugin)->get_summary($objective, $context);
} catch (moodle_exception $exception) {
    $summary = get_string("objectivepluginmissing", "videoprogress", $objective->plugin);
}
echo $OUTPUT->header();
echo $OUTPUT->render_from_template('mod_videoprogress/delete_resource', [
    "title" => get_string("deleteobjective", "videoprogress"),
    "message" => get_string("deleteobjectiveconfirm", "videoprogress", $summary),
    "actionurl" => $PAGE->url->out(false),
    "sesskey" => sesskey(),
    "returnurl" => $returnurl->out(false),
]);
echo $OUTPUT->footer();
