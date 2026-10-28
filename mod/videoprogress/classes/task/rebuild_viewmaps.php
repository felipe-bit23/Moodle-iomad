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
 * rebuild_viewmaps.php
 *
 * @package   mod_videoprogress
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_videoprogress\task;

use coding_exception;
use core\task\scheduled_task;
use dml_exception;
use mod_videoprogress\view_map;

/**
 * Runs the rebuild viewmaps operation as a Moodle task.
 */
class rebuild_viewmaps extends scheduled_task {
    /**
     * Returns the localized task name displayed by Moodle administration.
     *
     * @return string The resolved or formatted string value.
     * @throws coding_exception
     */
    public function get_name(): string {
        return get_string("taskrebuildviewmaps", "videoprogress");
    }

    /**
     * Executes the rebuild viewmaps task workload.
     *
     * @return void This method does not return a value.
     * @throws dml_exception
     */
    public function execute(): void {
        global $DB;

        $activities = $DB->get_recordset("videoprogress", null, '', "id");
        foreach ($activities as $activity) {
            $views = array_fill(0, view_map::DEFAULT_BUCKETS, 0);
            $users = array_fill(0, view_map::DEFAULT_BUCKETS, 0);
            $progresses = $DB->get_recordset("videoprogress_progress", ["videoprogressid" => $activity->id], '', 'id,viewmap');
            foreach ($progresses as $progress) {
                $map = view_map::decode($progress->viewmap);
                foreach ($map as $index => $value) {
                    $views[$index] += $value;
                    if ($value > 0) {
                        $users[$index]++;
                    }
                }
            }
            $progresses->close();
            $DB->set_field("videoprogress", "aggregateviewmap", json_encode($views), ["id" => $activity->id]);
            $DB->set_field("videoprogress", "aggregateusermap", json_encode($users), ["id" => $activity->id]);
            $DB->set_field("videoprogress", "aggregateupdated", time(), ["id" => $activity->id]);
        }
        $activities->close();
    }
}
