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
 * caption_editor_form.php
 *
 * @package   mod_videoprogress
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_videoprogress\form;

use coding_exception;
use moodleform;

defined('MOODLE_INTERNAL') || die;

require_once($CFG->libdir . '/formslib.php');

/**
 * Defines the Moodle form used to configure caption editor form data.
 */
class caption_editor_form extends moodleform {
    /**
     * Defines the fields, defaults, dependencies, and actions displayed by the Moodle form.
     *
     * @return void This method does not return a value.
     * @throws coding_exception
     */
    public function definition(): void {
        $mform = $this->_form;
        $languages = $this->_customdata['languages'] ?? [];

        $mform->addElement("hidden", "id");
        $mform->setType("id", PARAM_INT);
        $mform->addElement("hidden", "captionid");
        $mform->setType("captionid", PARAM_INT);

        $mform->addElement("select", "language", get_string("captionlanguage", "videoprogress"), $languages);
        $mform->setType("language", PARAM_ALPHANUMEXT);
        $mform->setDefault("language", "en-US");
        $mform->addRule("language", null, "required", null, "client");
        $mform->addHelpButton("language", "captionlanguage", "videoprogress");

        $source = $this->_customdata["source"] ?? "upload";
        if ($source === "url") {
            $mform->addElement("url", "captionurl", get_string("captionurl", "videoprogress"),
                ["size" => 80], ["usefilepicker" => false]);
            $mform->setType("captionurl", PARAM_URL);
            $mform->addHelpButton("captionurl", "captionurl", "videoprogress");
        } else if ($source === "nextcloud") {
            $mform->addElement("url", "captionnextcloudurl", get_string("captionnextcloudurl", "videoprogress"),
                ["size" => 80], ["usefilepicker" => false]);
            $mform->setType("captionnextcloudurl", PARAM_URL);
            $mform->addHelpButton("captionnextcloudurl", "captionnextcloudurl", "videoprogress");
        }

        $options = ["rows" => 24, "class" => 'w-100'];
        $mform->addElement("textarea", "content", get_string("captioncontent", "videoprogress"), $options);
        $mform->setType("content", PARAM_RAW);
        $mform->addRule("content", null, "required", null, "client");

        $mform->addElement("selectyesno", "isdefault", get_string("captiondefault", "videoprogress"));

        $mform->addElement("select", "status", get_string("captionstatus", "videoprogress"), [
            "draft" => get_string("captionstatusdraft", "videoprogress"),
            "published" => get_string("captionstatuspublished", "videoprogress"),
        ]);
        $this->add_action_buttons(true, get_string("savechanges"));
    }
}
