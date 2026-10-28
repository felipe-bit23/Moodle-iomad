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
 * tracking_manager_test.php
 *
 * @package   mod_videoprogress
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_videoprogress;

/**
 * Provides PHPUnit coverage for tracking manager behavior.
 *
 * @covers \mod_videoprogress\tracking_manager
 */
final class tracking_manager_test extends \advanced_testcase {
    /**
     * Verifies that impossible forward jump is blocked and not recorded.
     *
     * @return void This method does not return a value.
     */
    public function test_impossible_forward_jump_is_blocked_and_not_recorded(): void {
        $activity = (object)["allowseek" => 0, "maxplaybackrate" => 1];
        $progress = (object)["watchedsegments" => '[[0,10]]'];
        $session = (object)[
            "sequence" => 1,
            "lastposition" => 10,
            "lastheartbeat" => 1000,
            "lastclienttime" => 1000,
        ];
        $result = (new tracking_manager())->validate($activity, $progress, $session, [
            "duration" => 600,
            "currentposition" => 300,
            "playbackrate" => 1,
            "segmentstart" => 10,
            "segmentend" => 300,
            "sequence" => 2,
            "clienttime" => 1005,
            "playerstate" => "playing",
        ], 1005);
        $this->assertTrue($result["accepted"]);
        $this->assertTrue($result["seekblocked"]);
        $this->assertNull($result["segment"]);
        $this->assertEquals(10, $result["currentposition"]);
    }

    /**
     * Verifies that stale sequence is rejected.
     *
     * @return void This method does not return a value.
     */
    public function test_stale_sequence_is_rejected(): void {
        $activity = (object)["allowseek" => 1, "maxplaybackrate" => 0];
        $progress = (object)["watchedsegments" => '[]'];
        $session = (object)[
            "sequence" => 7,
            "lastposition" => 12,
            "lastheartbeat" => 1000,
            "lastclienttime" => 1000,
        ];
        $result = (new tracking_manager())->validate($activity, $progress, $session, [
            "duration" => 100, "currentposition" => 13, "playbackrate" => 1,
            "segmentstart" => 12, "segmentend" => 13, "sequence" => 7,
            "clienttime" => 1001,
            "playerstate" => "playing",
        ], 1001);
        $this->assertFalse($result["accepted"]);
        $this->assertSame("stale", $result["reason"]);
    }

    /**
     * Verifies that new session cannot bypass anti skip.
     *
     * @return void This method does not return a value.
     */
    public function test_new_session_cannot_bypass_anti_skip(): void {
        $activity = (object)["allowseek" => 0, "maxplaybackrate" => 1];
        $progress = (object)["watchedsegments" => '[[0,10]]'];
        $result = (new tracking_manager())->validate($activity, $progress, null, [
            "duration" => 600,
            "currentposition" => 500,
            "playbackrate" => 1,
            "segmentstart" => 500,
            "segmentend" => 510,
            "sequence" => 1,
            "clienttime" => 1000,
            "playerstate" => "playing",
        ], 1000);
        $this->assertTrue($result["seekblocked"]);
        $this->assertNull($result["segment"]);
        $this->assertEquals(10, $result["currentposition"]);
    }

    /**
     * Verifies that offline queue uses original client interval.
     *
     * @return void This method does not return a value.
     */
    public function test_offline_queue_uses_original_client_interval(): void {
        $activity = (object)["allowseek" => 1, "maxplaybackrate" => 1];
        $progress = (object)["watchedsegments" => '[[0,10]]'];
        $session = (object)[
            "sequence" => 1,
            "lastposition" => 10,
            "lastheartbeat" => 2000,
            "lastclienttime" => 1000,
        ];
        $result = (new tracking_manager())->validate($activity, $progress, $session, [
            "duration" => 100,
            "currentposition" => 20,
            "playbackrate" => 1,
            "segmentstart" => 10,
            "segmentend" => 20,
            "sequence" => 2,
            "clienttime" => 1010,
            "playerstate" => "playing",
        ], 2001);
        $this->assertSame([10.0, 20.0], $result["segment"]);
        $this->assertEqualsWithDelta(10, $result["watchtime"], 0.01);
    }

    /**
     * Verifies that excessive client interval does not expand tracking window.
     *
     * @return void This method does not return a value.
     */
    public function test_excessive_client_interval_does_not_expand_tracking_window(): void {
        $activity = (object)["allowseek" => 1, "maxplaybackrate" => 1];
        $progress = (object)["watchedsegments" => '[[0,10]]'];
        $session = (object)[
            "sequence" => 1,
            "lastposition" => 10,
            "lastheartbeat" => 2000,
            "lastclienttime" => 1000,
        ];
        $result = (new tracking_manager())->validate($activity, $progress, $session, [
            "duration" => 100,
            "currentposition" => 30,
            "playbackrate" => 1,
            "segmentstart" => 10,
            "segmentend" => 30,
            "sequence" => 2,
            "clienttime" => 1201,
            "playerstate" => "playing",
        ], 2001);
        $this->assertSame([10.0, 14.0], $result["segment"]);
    }

    /**
     * Verifies that a real ended event closes the tiny final player timing gap.
     *
     * @return void This method does not return a value.
     */
    public function test_ended_event_closes_final_timing_gap(): void {
        $activity = (object)["allowseek" => 0, "maxplaybackrate" => 1];
        $progress = (object)["watchedsegments" => '[[0,98.5]]'];
        $session = (object)[
            "sequence" => 8,
            "lastposition" => 98.5,
            "lastheartbeat" => 1000,
            "lastclienttime" => 1000,
        ];
        $result = (new tracking_manager())->validate($activity, $progress, $session, [
            "duration" => 100,
            "currentposition" => 100,
            "playbackrate" => 1,
            "segmentstart" => 98.5,
            "segmentend" => 99.4,
            "sequence" => 9,
            "clienttime" => 1001,
            "playerstate" => "ended",
        ], 1001);

        $this->assertFalse($result["seekblocked"]);
        $this->assertSame([98.5, 100.0], $result["segment"]);
        $this->assertSame(100.0, $result["currentposition"]);
    }
}
