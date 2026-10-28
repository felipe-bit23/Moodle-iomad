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
 * content_manager_test.php
 *
 * @package   mod_videoprogress
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_videoprogress;

/**
 * Provides PHPUnit coverage for content manager behavior.
 *
 * @covers \mod_videoprogress\content\manager
 */
final class content_manager_test extends \advanced_testcase {
    /**
     * Verifies that required quiz blocks progress but optional note does not.
     *
     * @return void This method does not return a value.
     */
    public function test_required_quiz_blocks_progress_but_optional_note_does_not(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_and_enrol($course, "student");
        $activity = $this->getDataGenerator()->create_module("videoprogress", ["course" => $course->id]);
        $now = time();
        $pointid = $DB->insert_record("videoprogress_points", (object)[
            "videoprogressid" => $activity->id,
            "timepoint" => 97,
            "title" => 'Knowledge check',
            "enabled" => 1,
            "timecreated" => $now,
            "timemodified" => $now,
        ]);
        $DB->insert_record("videoprogress_pointitems", (object)[
            "pointid" => $pointid,
            "plugin" => "note",
            "configdata" => json_encode(["pausevideo" => false, "displayduration" => 8]),
            "sortorder" => 10,
            "enabled" => 1,
            "timecreated" => $now,
            "timemodified" => $now,
        ]);
        $quizid = $DB->insert_record("videoprogress_pointitems", (object)[
            "pointid" => $pointid,
            "plugin" => "quiz",
            "configdata" => json_encode([
                "answers" => ["One", "Two"],
                "correctanswer" => 0,
            ]),
            "sortorder" => 20,
            "enabled" => 1,
            "timecreated" => $now,
            "timemodified" => $now,
        ]);
        $manager = new content\manager();
        $this->assertNull($manager->get_blocking_item($activity->id, $user->id, 96));
        $this->assertSame($quizid, (int)$manager->get_blocking_item($activity->id, $user->id, 98)->id);
        $DB->insert_record("videoprogress_interactions", (object)[
            "itemid" => $quizid,
            "userid" => $user->id,
            "completed" => 1,
            "attempts" => 1,
            "lastresponse" => '{"answer":0}',
            "timecompleted" => $now,
            "timecreated" => $now,
            "timemodified" => $now,
        ]);
        $this->assertNull($manager->get_blocking_item($activity->id, $user->id, 98));
    }

    /**
     * Verifies that reset removes interaction state.
     *
     * @return void This method does not return a value.
     */
    public function test_reset_removes_interaction_state(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(["enablecompletion" => 1]);
        $user = $this->getDataGenerator()->create_and_enrol($course, "student");
        $activity = $this->getDataGenerator()->create_module("videoprogress", ["course" => $course->id]);
        $cm = get_coursemodule_from_instance("videoprogress", $activity->id, $course->id, false, MUST_EXIST);
        $now = time();
        $pointid = $DB->insert_record("videoprogress_points", (object)[
            "videoprogressid" => $activity->id, "timepoint" => 10, "title" => "Point", "enabled" => 1,
            "timecreated" => $now, "timemodified" => $now,
        ]);
        $itemid = $DB->insert_record("videoprogress_pointitems", (object)[
            "pointid" => $pointid, "plugin" => "quiz", "configdata" => '{}', "sortorder" => 10, "enabled" => 1,
            "timecreated" => $now, "timemodified" => $now,
        ]);
        $DB->insert_record("videoprogress_interactions", (object)[
            "itemid" => $itemid, "userid" => $user->id, "completed" => 1, "attempts" => 1,
            "lastresponse" => '{}', "timecompleted" => $now, "timecreated" => $now, "timemodified" => $now,
        ]);
        $record = $DB->get_record("videoprogress", ["id" => $activity->id], "*", MUST_EXIST);
        (new progress_manager())->reset($record, $cm, $user->id, $user->id);
        $this->assertFalse($DB->record_exists("videoprogress_interactions", [
            "itemid" => $itemid,
            "userid" => $user->id,
        ]));
    }

    /**
     * Verifies that progress is clamped at required interaction.
     *
     * @return void This method does not return a value.
     */
    public function test_progress_is_clamped_at_required_interaction(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_and_enrol($course, "student");
        $activity = $this->getDataGenerator()->create_module("videoprogress", [
            "course" => $course->id,
            "allowseek" => 1,
        ]);
        $cm = get_coursemodule_from_instance("videoprogress", $activity->id, $course->id, false, MUST_EXIST);
        $record = $DB->get_record("videoprogress", ["id" => $activity->id], "*", MUST_EXIST);
        $now = time();
        $pointid = $DB->insert_record("videoprogress_points", (object)[
            "videoprogressid" => $activity->id, "timepoint" => 12, "title" => 'Required quiz', "enabled" => 1,
            "timecreated" => $now, "timemodified" => $now,
        ]);
        $DB->insert_record("videoprogress_pointitems", (object)[
            "pointid" => $pointid, "plugin" => "quiz", "configdata" => '{}', "sortorder" => 10, "enabled" => 1,
            "timecreated" => $now, "timemodified" => $now,
        ]);
        $this->setUser($user);
        $response = (new progress_manager())->update($record, $cm, $user->id, [
            "duration" => 120,
            "currentposition" => 20,
            "playbackrate" => 1,
            "segmentstart" => 8,
            "segmentend" => 20,
            "sequence" => 1,
            "sessionkey" => str_repeat("b", 32),
            "clienttime" => $now,
            "playerstate" => "playing",
        ]);
        $this->assertSame("interactionrequired", $response["reason"]);
        $this->assertEqualsWithDelta(12, $response["correctposition"], 0.01);
        $this->assertEqualsWithDelta(12, $response["lastposition"], 0.01);
        $progress = $DB->get_record("videoprogress_progress", [
            "videoprogressid" => $activity->id,
            "userid" => $user->id,
        ], "*", MUST_EXIST);
        $this->assertSame([[8.0, 12.0]], segment_manager::decode($progress->watchedsegments));
    }
}
