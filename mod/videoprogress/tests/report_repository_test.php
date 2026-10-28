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
 * report_repository_test.php
 *
 * @package   mod_videoprogress
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_videoprogress;

/**
 * Provides PHPUnit coverage for report repository behavior.
 *
 * @covers \mod_videoprogress\report_repository
 */
final class report_repository_test extends \advanced_testcase {
    /**
     * Verifies that report includes enrolled users without progress.
     *
     * @return void This method does not return a value.
     */
    public function test_report_includes_enrolled_users_without_progress(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_and_enrol($course, "student");
        $activity = $this->getDataGenerator()->create_module("videoprogress", ["course" => $course->id]);
        $cm = get_coursemodule_from_instance("videoprogress", $activity->id, $course->id, false, MUST_EXIST);
        $record = $DB->get_record("videoprogress", ["id" => $activity->id], "*", MUST_EXIST);
        $repository = new report_repository($record, $cm);
        $filters = new report_filters();
        $filters->normalise();
        $result = $repository->get_students($filters);
        $this->assertArrayHasKey($user->id, $result["records"]);
        $this->assertEquals(0, $result["records"][$user->id]->percent);
        $this->assertEquals(1, $result["total"]);
    }
}
