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
 * report_exporter.php
 *
 * @package   mod_videoprogress
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_videoprogress;

use coding_exception;
use csv_export_writer;

/**
 * Exports filtered student analytics as a portable CSV report.
 */
class report_exporter {
    /**
     * Streams the filtered report as a CSV file using Moodle output APIs.
     *
     * @param report_repository $repository repository value used by the operation.
     * @param report_filters $filters Normalized report filters.
     * @param string $filename Source file name.
     * @return void This method does not return a value.
     * @throws coding_exception
     * @throws \dml_exception
     */
    public function download(report_repository $repository, report_filters $filters, string $filename): void {
        global $CFG;
        require_once($CFG->libdir . '/csvlib.class.php');

        $csv = new csv_export_writer();
        $csv->set_filename($filename);
        $csv->add_data([
            "userid", "fullname", "email", "group", "percent", "unique_watched",
            "total_watch_time", "last_position", "status", "last_view", "completion",
        ]);
        $filters = clone $filters;
        $filters->page = 0;
        $filters->perpage = 100;
        do {
            $result = $repository->get_students($filters);
            $groups = $repository->get_group_names(array_keys($result["records"]));
            foreach ($result["records"] as $record) {
                $status = $record->completed ? get_string("statuscompleted", "videoprogress")
                    : ((float)$record->percent > 0 ? get_string("statusinprogress", "videoprogress")
                        : get_string("statusnotstarted", "videoprogress"));
                $csv->add_data([
                    $record->id,
                    fullname($record),
                    $record->email,
                    implode(', ', $groups[$record->id] ?? []),
                    (float)$record->percent,
                    (float)$record->uniquewatched,
                    (float)$record->totalwatchtime,
                    (float)$record->lastposition,
                    $status,
                    $record->lastview ? userdate($record->lastview) : '',
                    $record->completed ? 1 : 0,
                ]);
            }
            $filters->page++;
        } while ($filters->page * $filters->perpage < $result["total"]);
        $csv->download_file();
    }
}
