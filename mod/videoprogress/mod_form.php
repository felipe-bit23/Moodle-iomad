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
 * mod_form.php
 *
 * @package   mod_videoprogress
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_videoprogress\caption_manager;
use mod_videoprogress\source\manager;

defined('MOODLE_INTERNAL') || die;

global $CFG;
require_once($CFG->dirroot . '/course/moodleform_mod.php');

/**
 * Defines the main Moodle activity configuration and custom completion form.
 */
class mod_videoprogress_mod_form extends moodleform_mod {
    /**
     * Defines the fields, defaults, dependencies, and actions displayed by the Moodle form.
     *
     * @return void This method does not return a value.
     * @throws coding_exception
     * @throws moodle_exception
     */
    public function definition(): void {
        $mform = $this->_form;
        $sourcemanager = new manager();
        $sourceoptions = $sourcemanager->get_options();
        if (!$sourceoptions) {
            throw new moodle_exception("nosourceplugins", "videoprogress");
        }
        $mform->addElement("header", "general", get_string("general", "form"));
        $mform->addElement("text", "name", get_string("videoprogressname", "videoprogress"), ["size" => 64]);
        $mform->setType("name", PARAM_TEXT);
        $mform->addRule("name", null, "required", null, "client");
        $this->standard_intro_elements();

        $mform->addElement("html", html_writer::tag("h3", get_string("sourceheader", "videoprogress")));

        $mform->addElement("select", "videosource", get_string("videosource", "videoprogress"), $sourceoptions);
        $mform->setType("videosource", PARAM_PLUGIN);
        $mform->setDefault("videosource", $sourcemanager->get_default_source());
        $sourcemanager->add_form_elements($mform, "videosource");

        $filemanageroptions = [
            "subdirs" => 0,
            "accepted_types" => ["image"],
        ];
        $mform->addElement("filemanager", "poster", get_string("poster", "videoprogress"), null, $filemanageroptions);
        $nopostersources = $sourcemanager->get_sources_without_poster();
        if ($nopostersources) {
            $mform->hideIf("poster", "videosource", "in", $nopostersources);
        }

        $mform->addElement("header", "captionsheader", get_string("subtitles", "videoprogress"));
        $mform->addHelpButton("captionsheader", "subtitles", "videoprogress");
        $nocaptionsources = $sourcemanager->get_sources_without_uploaded_captions();
        if ($nocaptionsources) {
            $mform->hideIf("captionsheader", "videosource", "in", $nocaptionsources);
        }
        $languages = caption_manager::get_language_options();
        $captionsources = caption_manager::get_source_options();
        $repeatarray = [];
        $repeatarray[] = $mform->createElement("select", "captionsource",
            get_string("captionsource", "videoprogress"), $captionsources);
        $repeatarray[] = $mform->createElement("select", "captionlanguage",
            get_string("captionlanguage", "videoprogress"), $languages);
        $repeatarray[] = $mform->createElement("filepicker", "captionfile",
            get_string("captionfile", "videoprogress"), null, [
                "accepted_types" => ['.vtt', '.srt'],
                "maxbytes" => caption_manager::MAX_BYTES,
            ]);
        $repeatarray[] = $mform->createElement("url", "captionurl", get_string("captionurl", "videoprogress"),
            ["size" => 80], ["usefilepicker" => false]);
        $repeatarray[] = $mform->createElement("url", "captionnextcloudurl",
            get_string("captionnextcloudurl", "videoprogress"), ["size" => 80], ["usefilepicker" => false]);
        $repeatoptions = [
            "captionsource" => ["default" => "upload", "type" => PARAM_ALPHA],
            "captionlanguage" => ["default" => "en-US", "type" => PARAM_ALPHANUMEXT],
            "captionurl" => ["type" => PARAM_URL, "hideif" => ["captionsource", "neq", "url"]],
            "captionnextcloudurl" => ["type" => PARAM_URL, "hideif" => ["captionsource", "neq", "nextcloud"]],
            "captionfile" => ["hideif" => ["captionsource", "neq", "upload"]],
        ];
        if (!empty($this->current->instance) && !empty($this->_cm->id)) {
            global $OUTPUT, $PAGE;
            $existing = (new caption_manager())->get_management_tracks(
                (int)$this->current->instance,
                (int)$this->_cm->id
            );
            if ($existing) {
                $mform->addElement("html", $OUTPUT->render_from_template('mod_videoprogress/caption_list', [
                    "captions" => $existing,
                    "hascaptions" => true,
                ]));
                $PAGE->requires->strings_for_js(["deletecaptionconfirm", "confirmdelete", "cancel"], "videoprogress");
                $PAGE->requires->js_call_amd('mod_videoprogress/captions', "init");
            }
        }
        $repeats = $this->repeat_elements(
            $repeatarray,
            1,
            $repeatoptions,
            "caption_repeats",
            "caption_add",
            1,
            get_string("addcaption", "videoprogress"),
            true
        );
        if ($nocaptionsources) {
            $mform->hideIf("caption_add", "videosource", "in", $nocaptionsources);
            for ($index = 0; $index < $repeats; $index++) {
                foreach (["captionsource", "captionlanguage", "captionfile", "captionurl", "captionnextcloudurl"] as $field) {
                    $mform->hideIf("{$field}[{$index}]", "videosource", "in", $nocaptionsources);
                }
            }
        }

        $mform->addElement("html", html_writer::tag("h3", get_string("playbackheader", "videoprogress")));
        $mform->addElement("select", "resumeplayback", get_string("resumeplayback", "videoprogress"), [
            1 => get_string("resumeautomatic", "videoprogress"),
            2 => get_string("resumeask", "videoprogress"),
            0 => get_string("resumefromstart", "videoprogress"),
        ]);
        $mform->setDefault("resumeplayback", 1);
        $mform->addElement("selectyesno", "allowseek", get_string("allowseek", "videoprogress"));
        $mform->setDefault("allowseek", 1);
        $mform->addElement("select", "maxplaybackrate", get_string("maxplaybackrate", "videoprogress"), [
            "0" => get_string("nolimit", "videoprogress"), "1" => "1x", '1.25' => '1.25x',
            '1.5' => '1.5x', '1.75' => '1.75x', "2" => "2x",
        ]);
        foreach (["disabledownload", "disablepip", "disablecontextmenu"] as $field) {
            $mform->addElement("selectyesno", $field, get_string($field, "videoprogress"));
            $mform->setDefault($field, 0);
        }

        $mform->addElement("html", html_writer::div(get_string("protectionnotice", "videoprogress"), "alert alert-info"));

        $this->standard_grading_coursemodule_elements();
        $mform->setDefault("grade", 100);
        $this->standard_coursemodule_elements();
        $this->add_action_buttons();
    }

    /**
     * Performs server-side validation for submitted Moodle form values.
     *
     * @param mixed $data Validated input or tracking data.
     * @param mixed $files Files submitted with the Moodle form.
     * @return array Structured data produced by the operation.
     * @throws coding_exception
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        $percentfield = $this->get_suffixed_name("completionpercent");
        if (isset($data[$percentfield]) && ((int)$data[$percentfield] < 1 || (int)$data[$percentfield] > 100)) {
            $errors[$percentfield] = get_string("errorpercent", "videoprogress");
        }
        $errors += (new manager())->validation((array)$data, (array)$files);
        $errors += $this->validate_caption_rows((array)$data);
        return $errors;
    }

    /**
     * Prepares stored activity files and values before the edit form is displayed.
     *
     * @param mixed $defaultvalues defaultvalues value used by the operation.
     * @return void This method does not return a value.
     * @throws coding_exception
     * @throws moodle_exception
     */
    public function data_preprocessing(&$defaultvalues): void {
        foreach (["completionpercent", "requireconfirmation"] as $field) {
            if (array_key_exists($field, $defaultvalues)) {
                $defaultvalues[$this->get_suffixed_name($field)] = $defaultvalues[$field];
            }
        }
        if (empty($this->current->instance)) {
            return;
        }
        $context = $this->context;
        (new manager())->prepare_form_data($defaultvalues, $context);
        $posterdraftid = file_get_submitted_draft_itemid("poster");
        file_prepare_draft_area($posterdraftid, $context->id, "mod_videoprogress", "poster", 0, ["subdirs" => 0]);
        $defaultvalues["poster"] = $posterdraftid;
    }

    /**
     * Adds the custom completion percentage controls to the activity form.
     *
     * @return array Structured data produced by the operation.
     * @throws coding_exception
     */
    public function add_completion_rules(): array {
        $mform = $this->_form;
        $percentfield = $this->get_suffixed_name("completionpercent");
        $confirmationfield = $this->get_suffixed_name("requireconfirmation");
        $mform->addElement("text", $percentfield, get_string("completionpercent", "videoprogress"), ["size" => 5]);
        $mform->setType($percentfield, PARAM_INT);
        $mform->setDefault($percentfield, 80);
        $mform->addRule($percentfield, null, "numeric", null, "client");
        $mform->addElement("selectyesno", $confirmationfield, get_string("requireconfirmation", "videoprogress"));
        $mform->setDefault($confirmationfield, 0);
        return [$percentfield, $confirmationfield];
    }

    /**
     * Checks whether the custom percentage completion rule is enabled.
     *
     * @param mixed $data Validated input or tracking data.
     * @return bool Whether the evaluated condition or operation succeeded.
     */
    public function completion_rule_enabled($data): bool {
        return !empty($data[$this->get_suffixed_name("completionpercent")]);
    }

    /**
     * Returns submitted activity data after applying completion and grade defaults.
     */
    public function get_data() {
        $data = parent::get_data();
        if (!$data) {
            return $data;
        }
        foreach (["completionpercent", "requireconfirmation"] as $field) {
            $suffixed = $this->get_suffixed_name($field);
            if (property_exists($data, $suffixed)) {
                $data->{$field} = $data->{$suffixed};
                unset($data->{$suffixed});
            }
        }
        return $data;
    }

    /**
     * Validates completed caption rows submitted with the activity form.
     *
     * Empty rows are ignored so teachers can leave unused repeats blank.
     *
     * @param array $data Submitted activity form values.
     * @return array Field names mapped to localized validation errors.
     */
    private function validate_caption_rows(array $data): array {
        global $USER;

        $source = clean_param((string)($data["videosource"] ?? ''), PARAM_PLUGIN);
        try {
            if (!(new manager())->get_plugin($source)->supports_uploaded_captions()) {
                return [];
            }
        } catch (moodle_exception $exception) {
            unset($exception);
            return [];
        }

        $errors = [];
        $captionmanager = new caption_manager();
        $repeats = (int)($data["caption_repeats"] ?? 0);
        for ($index = 0; $index < $repeats; $index++) {
            $rowsource = clean_param((string)($data["captionsource"][$index] ?? "upload"), PARAM_ALPHA);
            $hasfile = $this->draft_has_caption_file((int)($data["captionfile"][$index] ?? 0), (int)$USER->id);
            $url = trim((string)($data["captionurl"][$index] ?? ''));
            $nextcloudurl = trim((string)($data["captionnextcloudurl"][$index] ?? ''));
            $empty = match ($rowsource) {
                "url" => $url === '',
                "nextcloud" => $nextcloudurl === '',
                default => !$hasfile,
            };
            if ($empty) {
                continue;
            }
            try {
                caption_manager::normalise_language((string)($data["captionlanguage"][$index] ?? ''));
            } catch (moodle_exception $exception) {
                $errors["captionlanguage[{$index}]"] = $exception->getMessage();
            }
            if ($rowsource === "url") {
                try {
                    $captionmanager->validate_direct_caption_url($url);
                } catch (moodle_exception $exception) {
                    $errors["captionurl[{$index}]"] = $exception->getMessage();
                }
            } else if ($rowsource === "nextcloud") {
                try {
                    $captionmanager->to_nextcloud_download_url($nextcloudurl);
                } catch (moodle_exception $exception) {
                    $errors["captionnextcloudurl[{$index}]"] = $exception->getMessage();
                }
            }
        }
        return $errors;
    }

    /**
     * Checks whether a caption filepicker draft area contains a file.
     *
     * @param int $draftitemid File picker draft item identifier.
     * @param int $userid User owning the draft area.
     * @return bool Whether a caption file is present.
     */
    private function draft_has_caption_file(int $draftitemid, int $userid): bool {
        if ($draftitemid < 1) {
            return false;
        }
        $files = get_file_storage()->get_area_files(
            context_user::instance($userid)->id,
            "user",
            "draft",
            $draftitemid,
            "id",
            false
        );
        return (bool)$files;
    }

    /**
     * Returns a unique form field name for a Video Progress completion rule.
     *
     * @param string $field Base field name.
     * @return string Suffixed form field name.
     */
    private function get_suffixed_name(string $field): string {
        return "{$field}_videoprogress";
    }
}
