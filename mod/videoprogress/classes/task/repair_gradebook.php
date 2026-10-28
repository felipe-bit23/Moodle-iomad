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
 * repair_gradebook.php
 *
 * @package   mod_videoprogress
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_videoprogress\task;

use coding_exception;
use completion_info;
use context_system;
use core\task\adhoc_task;
use dml_exception;
use mod_videoprogress\progress_manager;
use moodle_exception;
use required_capability_exception;

/**
 * Runs the repair gradebook operation as a Moodle task.
 */
class repair_gradebook extends adhoc_task {
    /**
     * Returns the localized task name displayed by Moodle administration.
     *
     * @return string The resolved or formatted string value.
     * @throws coding_exception
     */
    public function get_name(): string {
        return get_string("taskrepairgradebook", "videoprogress");
    }

    /**
     * Executes the repair gradebook task workload.
     *
     * @return void This method does not return a value.
     * @throws coding_exception
     * @throws dml_exception
     * @throws moodle_exception
     * @throws required_capability_exception
     */
    public function execute(): void {
        global $DB;
        $data = $this->get_custom_data();
        if (!has_capability('moodle/site:config', context_system::instance(), $data->actorid)) {
            throw new required_capability_exception(context_system::instance(), 'moodle/site:config', "nopermissions", '');
        }
        $activities = $DB->get_records("videoprogress");
        foreach ($activities as $activity) {
            videoprogress_grade_item_update($activity);
            videoprogress_update_grades($activity);
            $cm = get_coursemodule_from_instance("videoprogress", $activity->id, $activity->course);
            if (!$cm) {
                continue;
            }
            $manager = new progress_manager();
            $completion = new completion_info(get_course($activity->course));
            $progresses = $DB->get_recordset("videoprogress_progress", ["videoprogressid" => $activity->id]);
            foreach ($progresses as $progress) {
                $complete = $manager->is_complete($activity, $progress);
                if ((bool)$progress->completed !== $complete) {
                    $DB->set_field("videoprogress_progress", "completed", $complete ? 1 : 0, ["id" => $progress->id]);
                }
                if ($completion->is_enabled($cm)) {
                    $completion->update_state(
                        $cm,
                        $complete ? COMPLETION_COMPLETE : COMPLETION_INCOMPLETE,
                        $progress->userid
                    );
                }
            }
            $progresses->close();
        }
    }
}
