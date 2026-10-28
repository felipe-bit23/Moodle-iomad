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
 * point_form.php
 *
 * @package   mod_videoprogress
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_videoprogress\form;

use coding_exception;
use mod_videoprogress\content\timecode;
use moodleform;

defined('MOODLE_INTERNAL') || die;

require_once($CFG->libdir . '/formslib.php');

/**
 * Defines the Moodle form used to configure point form data.
 */
class point_form extends moodleform {
    /**
     * Defines the fields, defaults, dependencies, and actions displayed by the Moodle form.
     *
     * @return void This method does not return a value.
     * @throws coding_exception
     */
    public function definition(): void {
        $mform = $this->_form;
        $mform->addElement("text", "title", get_string("pointtitle", "videoprogress"), ["size" => 60]);
        $mform->setType("title", PARAM_TEXT);
        $mform->addRule("title", null, "required", null, "client");
        $mform->addElement("text", "timecode", get_string("pointtime", "videoprogress"), ["size" => 15]);
        $mform->setType("timecode", PARAM_TEXT);
        $mform->addRule("timecode", null, "required", null, "client");
        $mform->addHelpButton("timecode", "pointtime", "videoprogress");
        $mform->addElement("selectyesno", "enabled", get_string("enabled", "videoprogress"));
        $mform->setDefault("enabled", 1);
        $this->add_action_buttons(true, get_string("savechanges"));
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
        if (timecode::parse($data["timecode"] ?? '') === null) {
            $errors["timecode"] = get_string("invalidtimecode", "videoprogress");
        }
        return $errors;
    }
}
