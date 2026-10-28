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
 * mass_reset.php
 *
 * @package   mod_videoprogress
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_videoprogress\task;

use coding_exception;
use context_module;
use core\task\adhoc_task;
use dml_exception;
use mod_videoprogress\progress_manager;
use required_capability_exception;

/**
 * Runs the mass reset operation as a Moodle task.
 */
class mass_reset extends adhoc_task {
    /**
     * Returns the localized task name displayed by Moodle administration.
     *
     * @return string The resolved or formatted string value.
     * @throws coding_exception
     */
    public function get_name(): string {
        return get_string("taskmassreset", "videoprogress");
    }

    /**
     * Executes the mass reset task workload.
     *
     * @return void This method does not return a value.
     * @throws coding_exception
     * @throws dml_exception
     * @throws required_capability_exception
     */
    public function execute(): void {
        global $DB;
        $data = $this->get_custom_data();
        $cm = get_coursemodule_from_id("videoprogress", $data->cmid, 0, false, MUST_EXIST);
        $activity = $DB->get_record("videoprogress", ["id" => $cm->instance], "*", MUST_EXIST);
        $context = context_module::instance($cm->id);
        if (!has_capability('mod/videoprogress:resetprogress', $context, $data->actorid)) {
            throw new required_capability_exception($context, 'mod/videoprogress:resetprogress', "nopermissions", '');
        }
        $manager = new progress_manager();
        foreach (array_unique(array_map("intval", $data->userids)) as $userid) {
            $manager->reset($activity, $cm, $userid, $data->actorid);
        }
    }
}
