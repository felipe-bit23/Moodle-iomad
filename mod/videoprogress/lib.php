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
 * lib.php
 *
 * @package   mod_videoprogress
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_videoprogress\progress_manager;
use mod_videoprogress\source\manager;

/**
 * Declares the Moodle core features supported by the activity module.
 *
 * @param string $feature Moodle feature constant.
 * @return bool|null Whether Moodle should accept the result.
 */
function videoprogress_supports($feature) {
    switch ($feature) {
        case FEATURE_MOD_ARCHETYPE:
            return MOD_ARCHETYPE_RESOURCE;
        case FEATURE_GROUPS:
            return false;
        case FEATURE_GROUPINGS:
            return false;
        case FEATURE_MOD_INTRO:
            return true;
        case FEATURE_COMPLETION_TRACKS_VIEWS:
            return true;
        case FEATURE_COMPLETION_HAS_RULES:
            return true;
        case FEATURE_GRADE_HAS_GRADE:
            return true;
        case FEATURE_BACKUP_MOODLE2:
            return true;
        case FEATURE_MOD_PURPOSE:
            return MOD_PURPOSE_CONTENT;
        default:
            return null;
    }
}

/**
 * Creates an activity instance, stores protected files, and creates its grade item.
 *
 * @param stdClass $data Activity data submitted by Moodle.
 * @param mod_videoprogress_mod_form|null $mform Activity configuration form instance.
 * @return int The created identifier or Moodle status code.
 * @throws coding_exception
 * @throws dml_exception
 * @throws dml_transaction_exception
 * @throws file_exception
 * @throws moodle_exception
 * @throws stored_file_creation_exception
 */
function videoprogress_add_instance(stdClass $data, ?mod_videoprogress_mod_form $mform = null): int {
    global $DB;

    $now = time();
    $data->timecreated = $now;
    $data->timemodified = $now;
    (new manager())->normalise_record($data);
    $id = $DB->insert_record("videoprogress", $data);
    $data->id = $id;
    mod_videoprogress\instance_manager::save_files($data);
    videoprogress_grade_item_update($data);
    return $id;
}

/**
 * Updates an activity instance, protected files, and gradebook configuration.
 *
 * @param stdClass $data Activity data submitted by Moodle.
 * @param mod_videoprogress_mod_form|null $mform Activity configuration form instance.
 * @return bool Whether Moodle should accept the result.
 * @throws coding_exception
 * @throws dml_exception
 * @throws dml_transaction_exception
 * @throws file_exception
 * @throws moodle_exception
 * @throws stored_file_creation_exception
 */
function videoprogress_update_instance(stdClass $data, ?mod_videoprogress_mod_form $mform = null): bool {
    global $DB;

    $data->id = $data->instance;
    $data->timemodified = time();
    $previoussource = $DB->get_field("videoprogress", "videosource", ["id" => $data->id], MUST_EXIST);
    (new manager())->normalise_record($data);
    $result = $DB->update_record("videoprogress", $data);
    mod_videoprogress\instance_manager::save_files($data, $previoussource);
    videoprogress_grade_item_update($data);
    return $result;
}

/**
 * Deletes an activity instance together with progress, sessions, interactions, and files.
 *
 * @param int $id Activity instance identifier.
 * @return bool Whether Moodle should accept the result.
 */
function videoprogress_delete_instance(int $id): bool {
    global $DB;

    if (!$activity = $DB->get_record("videoprogress", ["id" => $id])) {
        return false;
    }

    $cm = get_coursemodule_from_instance("videoprogress", $id, $activity->course, false, IGNORE_MISSING);
    if ($cm) {
        $context = context_module::instance($cm->id);
        (new manager())->delete_files($context);
        (new \mod_videoprogress\material\manager())->delete_all($id, $context);
        (new \mod_videoprogress\content\manager())->delete_all($id, $context);
        (new \mod_videoprogress\objective\manager())->delete_all($id, $context);
    }
    $transaction = $DB->start_delegated_transaction();
    if (!$cm) {
        $pointids = $DB->get_fieldset_select("videoprogress_points", "id", 'videoprogressid = :id', ["id" => $id]);
        if ($pointids) {
            [$insql, $params] = $DB->get_in_or_equal($pointids, SQL_PARAMS_NAMED, "point");
            $itemids = $DB->get_fieldset_select("videoprogress_pointitems", "id", "pointid {$insql}", $params);
            if ($itemids) {
                [$itemsql, $itemparams] = $DB->get_in_or_equal($itemids, SQL_PARAMS_NAMED, "item");
                $DB->delete_records_select("videoprogress_interactions", "itemid {$itemsql}", $itemparams);
                $DB->delete_records_select("videoprogress_pointitems", "id {$itemsql}", $itemparams);
            }
            $DB->delete_records_select("videoprogress_points", "id {$insql}", $params);
        }
        $DB->delete_records("videoprogress_materials", ["videoprogressid" => $id]);
        $DB->delete_records("videoprogress_objectives", ["videoprogressid" => $id]);
    }
    $DB->delete_records("videoprogress_sessions", ["videoprogressid" => $id]);
    $DB->delete_records("videoprogress_progress", ["videoprogressid" => $id]);
    $DB->delete_records("videoprogress_captions", ["videoprogressid" => $id]);
    $DB->delete_records("videoprogress", ["id" => $id]);
    $transaction->allow_commit();
    videoprogress_grade_item_delete($activity);
    return true;
}

/**
 * Serves protected activity video, poster, and published caption files.
 *
 * @param mixed $course Course record.
 * @param mixed $cm Course module record.
 * @param mixed $context Module context.
 * @param string $filearea Requested File API area.
 * @param array $args Remaining pluginfile path arguments.
 * @param bool $forcedownload Whether Moodle requested a forced download.
 * @param array $options Additional file-serving options.
 * @return bool Whether Moodle should accept the result.
 */
function mod_videoprogress_pluginfile($course, $cm, $context, string $filearea, array $args,
                                      bool $forcedownload, array $options = []): bool {
    global $DB;

    if ($context->contextlevel !== CONTEXT_MODULE || !in_array($filearea, ["video", "poster", "caption"], true)) {
        return false;
    }

    require_login($course, true, $cm);
    require_capability('mod/videoprogress:view', $context);
    $itemid = (int)array_shift($args);
    if ($filearea !== "caption" && $itemid !== 0) {
        return false;
    }
    if ($filearea === "caption") {
        $caption = $DB->get_record("videoprogress_captions", ["id" => $itemid], "*", MUST_EXIST);
        $activity = $DB->get_record("videoprogress", ["id" => $caption->videoprogressid], "*", MUST_EXIST);
        if ((int)$activity->id !== (int)$cm->instance || $caption->status !== "published") {
            return false;
        }
    }

    $filename = array_pop($args);
    $filepath = '/' . ($args ? implode('/', $args) . '/' : '');
    $fs = get_file_storage();
    $file = $fs->get_file($context->id, "mod_videoprogress", $filearea, $itemid, $filepath, $filename);
    if (!$file || $file->is_directory()) {
        return false;
    }
    send_stored_file($file, 0, 0, $forcedownload, $options);
}

/**
 * Returns the activity File API areas exposed to Moodle file browsing.
 *
 * @param mixed $course Course record.
 * @param mixed $cm Course module record.
 * @param mixed $context Module context.
 * @return array The structured callback response.
 */
function videoprogress_get_file_areas($course, $cm, $context): array {
    return [
        "video" => get_string("videofile", "videoprogress"),
        "poster" => get_string("poster", "videoprogress"),
        "caption" => get_string("captions", "videoprogress"),
    ];
}

/**
 * Creates or updates the percentage grade item in the Moodle gradebook.
 *
 * @param stdClass $activity Activity configuration record.
 * @param array|null $grades Optional preloaded grade records.
 * @return int The created identifier or Moodle status code.
 */
function videoprogress_grade_item_update(stdClass $activity, array|null $grades = null): int {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');

    $item = [
        "itemname" => clean_param($activity->name, PARAM_NOTAGS),
        "gradetype" => GRADE_TYPE_VALUE,
        "grademin" => 0,
        "grademax" => 100,
    ];
    if ((float)$activity->grade <= 0) {
        $item["gradetype"] = GRADE_TYPE_NONE;
    }
    return grade_update('mod/videoprogress', $activity->course, "mod", "videoprogress", $activity->id, 0, $grades, $item);
}

/**
 * Publishes server-calculated watched percentages to the Moodle gradebook.
 *
 * @param stdClass $activity Activity configuration record.
 * @param int $userid Optional target user identifier.
 * @param bool $nullifnone nullifnone value.
 * @return void This callback does not return a value.
 */
function videoprogress_update_grades(stdClass $activity, int $userid = 0, bool $nullifnone = true): void {
    global $DB;

    $conditions = ["videoprogressid" => $activity->id];
    if ($userid) {
        $conditions["userid"] = $userid;
    }
    $records = $DB->get_records("videoprogress_progress", $conditions);
    $grades = [];
    foreach ($records as $record) {
        $grades[$record->userid] = (object)[
            "userid" => $record->userid,
            "rawgrade" => min(100, max(0, (float)$record->percent)),
        ];
    }
    if (!$grades && $userid && $nullifnone) {
        $grades[$userid] = (object)["userid" => $userid, "rawgrade" => null];
    }
    videoprogress_grade_item_update($activity, $grades);
}

/**
 * Deletes the activity grade item from the Moodle gradebook.
 *
 * @param stdClass $activity Activity configuration record.
 * @return int The created identifier or Moodle status code.
 */
function videoprogress_grade_item_delete(stdClass $activity): int {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');
    return grade_update('mod/videoprogress', $activity->course, "mod", "videoprogress", $activity->id, 0, null, ["deleted" => 1]);
}

/**
 * Builds cached course-module information used in Moodle course pages.
 *
 * @param stdClass $cm Course module record.
 * @return cached_cm_info|null The callback result.
 */
function videoprogress_get_coursemodule_info(stdClass $cm): cached_cm_info|null {
    global $DB;
    $activity = $DB->get_record("videoprogress", ["id" => $cm->instance],
        'id,name,intro,introformat,completionpercent,requireconfirmation');
    if (!$activity) {
        return null;
    }
    $info = new cached_cm_info();
    $info->name = $activity->name;
    if ($cm->showdescription) {
        $info->content = format_module_intro("videoprogress", $activity, $cm->id, false);
    }
    if ((int)$cm->completion === COMPLETION_TRACKING_AUTOMATIC) {
        $info->customdata["customcompletionrules"] = [
            "completionpercent" => (int)$activity->completionpercent,
            "requireconfirmation" => (bool)$activity->requireconfirmation,
        ];
    }

    return $info;
}

/**
 * Returns descriptions of active custom completion rules for the course page.
 *
 * @param cached_cm_info $cm Course module record.
 * @return array The structured callback response.
 */
function videoprogress_get_completion_active_rule_descriptions(cached_cm_info $cm): array {
    if ((int)$cm->completion !== COMPLETION_TRACKING_AUTOMATIC ||
        empty($cm->customdata["customcompletionrules"]["completionpercent"])) {
        return [];
    }
    $rules = $cm->customdata["customcompletionrules"];
    $descriptions = [get_string('completiondetail:percent', "videoprogress", $rules["completionpercent"])];
    if (!empty($rules["requireconfirmation"])) {
        $descriptions[] = get_string('completiondetail:confirmation', "videoprogress");
    }
    return $descriptions;
}

/**
 * Evaluates the legacy completion callback from server-authoritative progress.
 *
 * @param mixed $course Course record.
 * @param mixed $cm Course module record.
 * @param int $userid Optional target user identifier.
 * @param bool $type type value.
 * @return bool Whether Moodle should accept the result.
 */
function videoprogress_get_completion_state($course, $cm, int $userid, bool $type): bool {
    global $DB;
    $activity = $DB->get_record("videoprogress", ["id" => $cm->instance], "*", MUST_EXIST);
    $progress = $DB->get_record("videoprogress_progress", [
        "videoprogressid" => $activity->id,
        "userid" => $userid,
    ]);
    if (!$progress) {
        return false;
    }
    return (new progress_manager())->is_complete($activity, $progress);
}

/**
 * Builds the Moodle App WebView response for the activity.
 *
 * @param array $args Remaining pluginfile path arguments.
 * @return array The structured callback response.
 */
function videoprogress_mobile_view(array $args): array {
    global $OUTPUT;
    $args = (object)$args;
    $cm = get_coursemodule_from_id("videoprogress", (int)$args->cmid, 0, false, MUST_EXIST);
    $context = context_module::instance($cm->id);
    require_capability('mod/videoprogress:view', $context);
    $url = new moodle_url('/mod/videoprogress/view.php', ["id" => $cm->id]);
    return [
        "templates" => [[
            "id" => "main",
            "html" => $OUTPUT->render_from_template('mod_videoprogress/mobile_view', ["url" => $url->out(false)]),
        ]],
        "javascript" => '',
        "otherdata" => '',
        "files" => [],
    ];
}
