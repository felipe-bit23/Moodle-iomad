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
 * progress_manager_test.php
 *
 * @package   mod_videoprogress
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_videoprogress;

/**
 * Provides PHPUnit coverage for progress manager behavior.
 *
 * @covers \mod_videoprogress\progress_manager
 */
final class progress_manager_test extends \advanced_testcase {
    /**
     * Verifies that unique progress is not last position.
     *
     * @return void This method does not return a value.
     */
    public function test_unique_progress_is_not_last_position(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_and_enrol($course, "student");
        $activity = $this->getDataGenerator()->create_module("videoprogress", [
            "course" => $course->id, "completionpercent" => 80, "allowseek" => 1,
        ]);
        $this->setUser($user);
        $cm = get_coursemodule_from_instance("videoprogress", $activity->id, $course->id, false, MUST_EXIST);
        $record = $DB->get_record("videoprogress", ["id" => $activity->id], "*", MUST_EXIST);
        $manager = new progress_manager();
        $base = [
            "duration" => 600, "playbackrate" => 1, "sessionkey" => str_repeat("a", 32), "playerstate" => "playing",
            "clienttime" => time(),
        ];
        $manager->update($record, $cm, $user->id, $base + [
                "currentposition" => 10, "segmentstart" => 0, "segmentend" => 10, "sequence" => 1,
            ]);
        $session = $DB->get_record("videoprogress_sessions",
            ["videoprogressid" => $activity->id, "userid" => $user->id], "*", MUST_EXIST);
        $DB->set_field("videoprogress_sessions", "lastheartbeat", time() - 60, ["id" => $session->id]);
        $manager->update($record, $cm, $user->id, $base + [
                "currentposition" => 60, "segmentstart" => 10, "segmentend" => 60, "sequence" => 2,
            ]);
        $manager->update($record, $cm, $user->id, $base + [
                "currentposition" => 480, "segmentstart" => 480, "segmentend" => 480, "sequence" => 3, "playerstate" => "seeking",
            ]);
        $DB->set_field("videoprogress_sessions", "lastheartbeat", time() - 120, ["id" => $session->id]);
        $manager->update($record, $cm, $user->id, $base + [
                "currentposition" => 600, "segmentstart" => 480, "segmentend" => 600, "sequence" => 4, "playerstate" => "ended",
            ]);
        $progress = $DB->get_record("videoprogress_progress",
            ["videoprogressid" => $activity->id, "userid" => $user->id], "*", MUST_EXIST);
        $this->assertEqualsWithDelta(180, $progress->uniquewatched, 0.1);
        $this->assertEqualsWithDelta(30, $progress->percent, 0.1);
        $this->assertEqualsWithDelta(600, $progress->lastposition, 0.1);
        $this->assertFalse((bool)$progress->completed);
    }
}
