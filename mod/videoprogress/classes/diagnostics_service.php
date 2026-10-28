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
 * diagnostics_service.php
 *
 * @package   mod_videoprogress
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_videoprogress;

use coding_exception;
use core\exception\moodle_exception;
use dml_exception;

/**
 * Collects gradebook, progress, segment, and view-map integrity diagnostics.
 */
class diagnostics_service {
    /**
     * Runs gradebook and data-integrity diagnostics for Video Progress activities.
     *
     * @return array Structured data produced by the operation.
     * @throws coding_exception
     * @throws dml_exception
     * @throws moodle_exception
     */
    public function run(): array {
        global $DB;

        $activities = $DB->count_records("videoprogress");
        $progress = $DB->count_records("videoprogress_progress");

        $sql = "
            SELECT COUNT(1)
              FROM {videoprogress_progress} p
         LEFT JOIN {videoprogress} v ON v.id = p.videoprogressid
             WHERE v.id IS NULL";
        $orphanprogress = $DB->count_records_sql($sql);
        $sql = "
            SELECT COUNT(1)
              FROM {videoprogress_sessions} s
         LEFT JOIN {videoprogress} v ON v.id = s.videoprogressid
             WHERE v.id IS NULL";
        $orphansessions = $DB->count_records_sql($sql);
        $missinggradeitems = $DB->get_records_sql("SELECT v.id, v.name, v.course
                                                    FROM {videoprogress} v
                                               LEFT JOIN {grade_items} gi
                                                      ON gi.courseid = v.course AND gi.itemmodule = :module
                                                     AND gi.iteminstance = v.id AND gi.itemnumber = 0
                                                   WHERE gi.id IS NULL", ["module" => "videoprogress"]);
        $invalidpercent = $DB->count_records_select("videoprogress_progress", 'percent < 0 OR percent > 100');
        $invalidsegments = 0;
        $invalidviewmaps = 0;
        $records = $DB->get_recordset("videoprogress_progress", null, '',
            'id,duration,watchedsegments,viewmap');
        foreach ($records as $record) {
            $rawsegments = json_decode($record->watchedsegments ?? '', true);
            if (!is_array($rawsegments)
                || count($rawsegments) !== count(segment_manager::normalise($rawsegments, (float)$record->duration))) {
                $invalidsegments++;
            }
            $rawmap = json_decode($record->viewmap ?? '', true);
            if (!is_array($rawmap)
                || count($rawmap) !== view_map::DEFAULT_BUCKETS
                || array_filter($rawmap, static fn($value): bool => !is_int($value) && !ctype_digit((string)$value))) {
                $invalidviewmaps++;
            }
        }
        $records->close();
        $inconsistentgrades = $DB->count_records_sql("SELECT COUNT(1)
                                                       FROM {videoprogress_progress} p
                                                       JOIN {videoprogress} v ON v.id = p.videoprogressid
                                                       JOIN {grade_items} gi ON gi.courseid = v.course AND gi.itemmodule = :module
                                                            AND gi.iteminstance = v.id AND gi.itemnumber = 0
                                                  LEFT JOIN {grade_grades} gg ON gg.itemid = gi.id AND gg.userid = p.userid
                                                      WHERE gg.id IS NULL OR ABS(gg.rawgrade - p.percent) > :tolerance", [
            "module" => "videoprogress", "tolerance" => 0.01,
        ]);
        $formulas = $this->broken_formulas();
        return [
            "cards" => [
                ["value" => $activities, "label" => get_string("diagnosticactivities", "videoprogress")],
                ["value" => $progress, "label" => get_string("diagnosticprogress", "videoprogress")],
                ["value" => $orphanprogress + $orphansessions, "label" => get_string("diagnosticorphans", "videoprogress")],
                ["value" => count($missinggradeitems), "label" => get_string("diagnosticmissinggradeitems", "videoprogress")],
                ["value" => $inconsistentgrades, "label" => get_string("diagnosticinconsistentgrades", "videoprogress")],
                ["value" => $invalidpercent, "label" => get_string("diagnosticinvalidpercent", "videoprogress")],
                ["value" => $invalidsegments, "label" => get_string("diagnosticinvalidsegments", "videoprogress")],
                ["value" => $invalidviewmaps, "label" => get_string("diagnosticinvalidviewmaps", "videoprogress")],
            ],
            "missinggradeitems" => array_map(static fn($record): array => [
                "name" => format_string($record->name),
                "courseid" => $record->course,
                "activityid" => $record->id,
            ], array_values($missinggradeitems)),
            "hasmissinggradeitems" => (bool)$missinggradeitems,
            "formulas" => $formulas,
            "hasformulas" => (bool)$formulas,
        ];
    }

    /**
     * Finds gradebook calculations that reference missing grade items.
     *
     * @return array Structured data produced by the operation.
     * @throws moodle_exception
     * @throws coding_exception
     * @throws dml_exception
     */
    private function broken_formulas(): array {
        global $DB;
        $result = [];
        $items = $DB->get_records_select("grade_items",
            "calculation IS NOT NULL AND calculation <> ''", null, '',
            'id,courseid,itemname,calculation');
        foreach ($items as $item) {
            preg_match_all('/##gi(\d+)##|\[\[([^\]]+)\]\]/', $item->calculation, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $exists = !empty($match[1])
                    ? $DB->record_exists("grade_items", ["id" => (int)$match[1], "courseid" => $item->courseid])
                    : $DB->record_exists("grade_items", ["idnumber" => $match[2], "courseid" => $item->courseid]);
                if (!$exists) {
                    $result[] = [
                        "courseid" => $item->courseid,
                        "itemname" => format_string($item->itemname),
                        "formula" => s($item->calculation),
                        "problem" => get_string("missingformulareference", "videoprogress", $match[0]),
                        "editurl" => (new \moodle_url('/grade/edit/tree/index.php', ["id" => $item->courseid]))->out(false),
                    ];
                    break;
                }
            }
        }
        return $result;
    }
}
