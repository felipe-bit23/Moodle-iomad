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
 * export.php
 *
 * @package   mod_videoprogress
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_videoprogress\report_exporter;
use mod_videoprogress\report_repository;

require('../../../config.php');

$id = required_param("id", PARAM_INT);
$cm = get_coursemodule_from_id("videoprogress", $id, 0, false, MUST_EXIST);
$course = $DB->get_record("course", ["id" => $cm->course], "*", MUST_EXIST);
$activity = $DB->get_record("videoprogress", ["id" => $cm->instance], "*", MUST_EXIST);
$context = context_module::instance($cm->id);
require_login($course, true, $cm);
require_capability('mod/videoprogress:exportreport', $context);

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
$filename = clean_filename('video-progress-' . $activity->name . '-' . userdate(time(), '%Y%m%d'));
(new report_exporter())->download($repository, $filters, $filename);
