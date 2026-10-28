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
 * objective_form.php
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
 * Defines the Moodle form used to create and edit learning objectives.
 */
class objective_form extends moodleform {
    /**
     * Defines shared and type-specific objective fields.
     *
     * @return void
     * @throws coding_exception
     */
    public function definition(): void {
        $mform = $this->_form;
        $plugin = $this->_customdata["plugin"];
        $mform->addElement("hidden", "plugin");
        $mform->setType("plugin", PARAM_PLUGIN);
        $mform->addElement("selectyesno", "enabled", get_string("enabled", "videoprogress"));
        $mform->setDefault("enabled", 1);
        $mform->addElement("header", "pluginsettings", $plugin->get_name());
        $plugin->add_form_elements($mform);
        $this->add_action_buttons(true, get_string("savechanges"));
    }

    /**
     * Delegates server-side validation to the selected objective type.
     *
     * @param mixed $data Submitted form data.
     * @param mixed $files Submitted files.
     * @return array Validation errors indexed by field name.
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        return $errors + $this->_customdata["plugin"]->validation(
            $data,
            $files,
            $this->_customdata["objective"],
            $this->_customdata["context"]
        );
    }
}
