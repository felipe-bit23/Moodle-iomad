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
 * captions.php
 *
 * @package   mod_videoprogress
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_videoprogress\caption_manager;
use mod_videoprogress\form\caption_form;
use mod_videoprogress\source\manager;

require('../../config.php');

$id = required_param("id", PARAM_INT);
$action = optional_param("action", '', PARAM_ALPHA);
$captionid = optional_param("captionid", 0, PARAM_INT);
$language = optional_param("language", '', PARAM_ALPHANUMEXT);
$cm = get_coursemodule_from_id("videoprogress", $id, 0, false, MUST_EXIST);
$course = $DB->get_record("course", ["id" => $cm->course], "*", MUST_EXIST);
$activity = $DB->get_record("videoprogress", ["id" => $cm->instance], "*", MUST_EXIST);
$context = context_module::instance($cm->id);
require_login($course, true, $cm);
require_capability('mod/videoprogress:managecaptions', $context);

$PAGE->set_url('/mod/videoprogress/captions.php', ["id" => $cm->id]);
$PAGE->set_title(get_string("managecaptions", "videoprogress"));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

$manager = new caption_manager();
$sourceplugin = (new manager())->get_plugin($activity->videosource);

if ($action !== '') {
    require_sesskey();
    if ($action === "delete" && $captionid) {
        $manager->delete($captionid, $activity->id, $context);
        redirect($PAGE->url, get_string("captiondeleted", "videoprogress"));
    }
    if ($action === "toggle" && $captionid) {
        $status = $manager->toggle_visibility($captionid, $activity->id);
        $message = $status === "published"
            ? get_string("captionshown", "videoprogress")
            : get_string("captiondetached", "videoprogress");
        redirect($PAGE->url, $message);
    }
}

$languageoptions = caption_manager::get_language_options();
$mform = new caption_form($PAGE->url, [
    "languages" => $languageoptions,
    "sources" => caption_manager::get_source_options(),
]);
if ($mform->is_cancelled()) {
    redirect(new moodle_url('/mod/videoprogress/view.php', ["id" => $cm->id]));
} else if ($data = $mform->get_data()) {
    $data->label = $languageoptions[$data->language] ?? '';
    try {
        $manager->save($activity->id, $context, $data, $USER->id);
    } catch (moodle_exception $exception) {
        redirect($PAGE->url, $exception->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }
    redirect($PAGE->url, get_string("captionsaved", "videoprogress"));
}

$captions = $manager->get_management_tracks($activity->id, $cm->id);
$templatedata = [
    "activityname" => format_string($activity->name),
    "captions" => $captions,
    "hascaptions" => (bool)$captions,
    "formhtml" => $mform->render(),
    "actionurl" => $PAGE->url->out(false),
    "cmid" => $cm->id,
    "sesskey" => sesskey(),
    "backurl" => new moodle_url('/mod/videoprogress/view.php', ["id" => $cm->id]),
];
$PAGE->requires->strings_for_js(["deletecaptionconfirm", "confirmdelete", "cancel"], "videoprogress");
$PAGE->requires->js_call_amd('mod_videoprogress/captions', "init");
echo $OUTPUT->header();
echo $OUTPUT->render_from_template('mod_videoprogress/captions', $templatedata);
echo $OUTPUT->footer();
