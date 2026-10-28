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
 * caption_form.php
 *
 * @package   mod_videoprogress
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_videoprogress\form;

use coding_exception;
use context_user;
use moodle_exception;
use moodleform;

defined('MOODLE_INTERNAL') || die;

require_once($CFG->libdir . '/formslib.php');

/**
 * Defines the Moodle form used to add a caption track from upload, URL, or Nextcloud.
 */
class caption_form extends moodleform {
    /**
     * Defines the fields, defaults, dependencies, and actions displayed by the Moodle form.
     *
     * @return void This method does not return a value.
     * @throws coding_exception
     */
    public function definition(): void {
        $mform = $this->_form;
        $languages = $this->_customdata['languages'] ?? [];
        $sources = $this->_customdata['sources'] ?? [];

        $mform->addElement("select", "captionsource", get_string("captionsource", "videoprogress"), $sources);
        $mform->setType("captionsource", PARAM_ALPHA);
        $mform->setDefault("captionsource", "upload");
        $mform->addHelpButton("captionsource", "captionsource", "videoprogress");

        $mform->addElement("select", "language", get_string("captionlanguage", "videoprogress"), $languages);
        $mform->setType("language", PARAM_ALPHANUMEXT);
        $mform->setDefault("language", "en-US");
        $mform->addRule("language", null, "required", null, "client");
        $mform->addHelpButton("language", "captionlanguage", "videoprogress");

        $options = ["accepted_types" => ['.vtt', '.srt'], "maxbytes" => 5 * 1024 * 1024];
        $mform->addElement("filepicker", "captionfile", get_string("captionfile", "videoprogress"), null, $options);
        $mform->hideIf("captionfile", "captionsource", "neq", "upload");

        $mform->addElement("url", "captionurl", get_string("captionurl", "videoprogress"),
            ["size" => 80], ["usefilepicker" => false]);
        $mform->setType("captionurl", PARAM_URL);
        $mform->addHelpButton("captionurl", "captionurl", "videoprogress");
        $mform->hideIf("captionurl", "captionsource", "neq", "url");

        $mform->addElement("url", "captionnextcloudurl", get_string("captionnextcloudurl", "videoprogress"),
            ["size" => 80], ["usefilepicker" => false]);
        $mform->setType("captionnextcloudurl", PARAM_URL);
        $mform->addHelpButton("captionnextcloudurl", "captionnextcloudurl", "videoprogress");
        $mform->hideIf("captionnextcloudurl", "captionsource", "neq", "nextcloud");

        $mform->addElement("selectyesno", "isdefault", get_string("captiondefault", "videoprogress"));
        $mform->addElement("select", "status", get_string("captionstatus", "videoprogress"), [
            "draft" => get_string("captionstatusdraft", "videoprogress"),
            "published" => get_string("captionstatuspublished", "videoprogress"),
        ]);
        $mform->setDefault("status", "published");
        $this->add_action_buttons(true, get_string("addcaption", "videoprogress"));
    }

    /**
     * Validates the selected caption source and its matching file or URL field.
     *
     * @param array $data Submitted form values.
     * @param array $files Submitted files.
     * @return array Field names mapped to localized validation errors.
     */
    public function validation($data, $files): array {
        global $USER;

        $errors = parent::validation($data, $files);
        $source = clean_param((string)($data["captionsource"] ?? "upload"), PARAM_ALPHA);
        $manager = new \mod_videoprogress\caption_manager();
        try {
            $manager::normalise_language((string)($data["language"] ?? ''));
        } catch (moodle_exception $exception) {
            $errors["language"] = $exception->getMessage();
        }
        if ($source === "upload") {
            $draftid = (int)($data["captionfile"] ?? 0);
            $draftfiles = get_file_storage()->get_area_files(
                context_user::instance($USER->id)->id,
                "user",
                "draft",
                $draftid,
                "id",
                false
            );
            if (!$draftfiles) {
                $errors["captionfile"] = get_string("captionfilemissing", "videoprogress");
            }
        } else if ($source === "url") {
            try {
                $manager->validate_direct_caption_url((string)($data["captionurl"] ?? ''));
            } catch (moodle_exception $exception) {
                $errors["captionurl"] = $exception->getMessage();
            }
        } else if ($source === "nextcloud") {
            try {
                $manager->to_nextcloud_download_url(trim((string)($data["captionnextcloudurl"] ?? '')));
            } catch (moodle_exception $exception) {
                $errors["captionnextcloudurl"] = $exception->getMessage();
            }
        }
        return $errors;
    }
}
