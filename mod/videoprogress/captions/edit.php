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

use mod_videoprogress\caption_manager;
use mod_videoprogress\form\caption_editor_form;

require('../../../config.php');

$id = required_param("id", PARAM_INT);
$captionid = required_param("captionid", PARAM_INT);
$cm = get_coursemodule_from_id("videoprogress", $id, 0, false, MUST_EXIST);
$course = $DB->get_record("course", ["id" => $cm->course], "*", MUST_EXIST);
$activity = $DB->get_record("videoprogress", ["id" => $cm->instance], "*", MUST_EXIST);
$caption = $DB->get_record("videoprogress_captions", ["id" => $captionid, "videoprogressid" => $activity->id], "*", MUST_EXIST);
$context = context_module::instance($cm->id);
require_login($course, true, $cm);
require_capability('mod/videoprogress:managecaptions', $context);

$PAGE->set_url('/mod/videoprogress/captions/edit.php', ["id" => $cm->id, "captionid" => $caption->id]);
$PAGE->set_title(get_string("editcaption", "videoprogress"));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

$languageoptions = caption_manager::get_language_options();
$files = get_file_storage()->get_area_files($context->id, "mod_videoprogress", "caption", $caption->id, "filename", false);
$caption->content = $files ? reset($files)->get_content() : '';
try {
    $caption->language = caption_manager::normalise_language((string)$caption->language);
} catch (moodle_exception $exception) {
    $languageoptions[$caption->language] = $caption->label ?: $caption->language;
}
$caption->captionid = $caption->id;
$caption->id = $cm->id;
$caption->captionurl = $caption->sourceurl ?? '';
$caption->captionnextcloudurl = $caption->sourceurl ?? '';
$mform = new caption_editor_form($PAGE->url, [
    "languages" => $languageoptions,
    "source" => $caption->source,
]);
$mform->set_data($caption);
if ($mform->is_cancelled()) {
    redirect(new moodle_url('/mod/videoprogress/captions.php', ["id" => $cm->id]));
} else if ($data = $mform->get_data()) {
    $data->label = $languageoptions[$data->language] ?? $data->language;
    $captionrecord = $DB->get_record("videoprogress_captions",
        ["id" => $data->captionid, "videoprogressid" => $activity->id], "*", MUST_EXIST);
    try {
        (new caption_manager())->update($captionrecord, $context, $data);
    } catch (moodle_exception $exception) {
        redirect($PAGE->url, $exception->getMessage(), null, \core\output\notification::NOTIFY_ERROR);
    }
    redirect(new moodle_url('/mod/videoprogress/captions.php',
        ["id" => $cm->id]), get_string("captionsaved", "videoprogress"));
}
echo $OUTPUT->header();
echo $OUTPUT->render_from_template('mod_videoprogress/caption_editor', [
    "activityname" => format_string($activity->name),
    "formhtml" => $mform->render(),
]);
echo $OUTPUT->footer();
