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
 * user.php
 *
 * @package   mod_videoprogress
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_videoprogress\format;
use mod_videoprogress\report_repository;

require('../../../config.php');

$id = required_param("id", PARAM_INT);
$userid = required_param("userid", PARAM_INT);
$cm = get_coursemodule_from_id("videoprogress", $id, 0, false, MUST_EXIST);
$course = $DB->get_record("course", ["id" => $cm->course], "*", MUST_EXIST);
$activity = $DB->get_record("videoprogress", ["id" => $cm->instance], "*", MUST_EXIST);
$user = $DB->get_record("user", ["id" => $userid, "deleted" => 0], "*", MUST_EXIST);
$context = context_module::instance($cm->id);
require_login($course, true, $cm);
require_capability('mod/videoprogress:viewreport', $context);
if (!is_enrolled(context_course::instance($course->id), $userid, 'mod/videoprogress:view', true) ||
    !mod_videoprogress\report_access::can_access_user($cm, $context, $userid)) {
    throw new required_capability_exception($context, 'mod/videoprogress:viewreport', "nopermissions", '');
}

$repository = new report_repository($activity, $cm);
$progress = $repository->get_progress($userid);
$sessions = [];
foreach ($repository->get_sessions($userid) as $session) {
    $sessionend = $session->timeend ?: $session->timemodified;
    $startposition = format::duration($session->startposition);
    $endposition = format::duration($session->endposition);
    $sessions[] = [
        "date" => userdate($session->timestart, get_string("strftimedatetimeshort", "langconfig")),
        "duration" => format::duration(max(0, $sessionend - $session->timestart)),
        "watchtime" => format::duration($session->watchtime),
        "startposition" => $startposition,
        "endposition" => $endposition,
        "range" => get_string("sessionrange", "videoprogress", (object)[
            "startposition" => $startposition,
            "endposition" => $endposition,
        ]),
    ];
}
$percent = $progress ? (float)$progress->percent : 0;
$templatedata = [
    "activityname" => format_string($activity->name),
    "fullname" => fullname($user),
    "email" => s($user->email),
    "percent" => (int)round($percent),
    "uniquewatched" => format::duration($progress->uniquewatched ?? 0),
    "totalwatchtime" => format::duration($progress->totalwatchtime ?? 0),
    "lastposition" => format::duration($progress->lastposition ?? 0),
    "lastview" => $progress ? userdate($progress->timemodified,
        get_string("strftimedatetimeshort", "langconfig")) : get_string("never"),
    "status" => $progress && $progress->completed ?
        get_string("statuscompleted", "videoprogress") :
        ($percent > 0 ? get_string("statusinprogress", "videoprogress") :
            get_string("statusnotstarted", "videoprogress")),
    "timeline" => format::timeline($progress->viewmap ?? '', $progress->duration ?? 0),
    "timelinealternative" => format::segment_alternative($progress->watchedsegments ?? ''),
    "sessions" => $sessions,
    "hassessions" => (bool)$sessions,
    "confirmation" => !empty($progress->confirmation),
    "confirmationtime" => !empty($progress->confirmationtime) ? userdate($progress->confirmationtime) : '',
    "canreset" => has_capability('mod/videoprogress:resetprogress', $context),
    "reseturl" => (string)new moodle_url('/mod/videoprogress/report/reset.php', ["id" => $cm->id, "userid" => $userid]),
    "backurl" => (string)new moodle_url('/mod/videoprogress/report/report.php', ["id" => $cm->id]),
];

$PAGE->set_url('/mod/videoprogress/report/user.php', ["id" => $cm->id, "userid" => $userid]);
$PAGE->set_title(get_string("studentdetails", "videoprogress"));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('mod_videoprogress/report_student', $templatedata);
echo $OUTPUT->footer();
