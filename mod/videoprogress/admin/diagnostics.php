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
 * diagnostics.php
 *
 * @package   mod_videoprogress
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_videoprogress\diagnostics_service;
use mod_videoprogress\task\repair_gradebook;

require('../../../config.php');

$action = optional_param("action", '', PARAM_ALPHA);
$confirmed = optional_param("confirmed", 0, PARAM_BOOL);
$context = context_system::instance();
require_login();
require_capability('moodle/site:config', $context);
$PAGE->set_url('/mod/videoprogress/admin/diagnostics.php');
$PAGE->set_context($context);
$PAGE->set_title(get_string("diagnosticstitle", "videoprogress"));
$PAGE->set_heading(get_string("administrationsite"));

if ($action === "repair" && $confirmed) {
    require_sesskey();
    $task = new repair_gradebook();
    $task->set_custom_data(["actorid" => $USER->id]);
    core\task\manager::queue_adhoc_task($task);
    redirect($PAGE->url, get_string("gradebookrepairqueued", "videoprogress"));
}

$templatedata = (new diagnostics_service())->run();
$templatedata += [
    "actionurl" => $PAGE->url->out(false),
    "sesskey" => sesskey(),
];
echo $OUTPUT->header();
echo $OUTPUT->render_from_template('mod_videoprogress/diagnostics', $templatedata);
echo $OUTPUT->footer();
