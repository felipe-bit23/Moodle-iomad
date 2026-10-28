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
 * view_map_test.php
 *
 * @package   mod_videoprogress
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_videoprogress;

/**
 * Provides PHPUnit coverage for view map behavior.
 *
 * @covers \mod_videoprogress\view_map
 */
final class view_map_test extends \advanced_testcase {
    /**
     * Verifies that rewatch increases bucket intensity.
     *
     * @return void This method does not return a value.
     */
    public function test_rewatch_increases_bucket_intensity(): void {
        $map = array_fill(0, 10, 0);
        $map = view_map::add_segment($map, 20, 50, 100, 10);
        $map = view_map::add_segment($map, 20, 50, 100, 10);
        $this->assertSame(2, $map[2]);
        $this->assertSame(2, $map[4]);
        $this->assertSame(0, $map[8]);
    }

    /**
     * Verifies that aggregate and insights.
     *
     * @return void This method does not return a value.
     */
    public function test_aggregate_and_insights(): void {
        $aggregate = view_map::aggregate([[1, 3, 1, 0], [1, 4, 0, 0]], 4);
        $this->assertSame([2, 7, 1, 0], $aggregate);
        $insights = view_map::insights($aggregate, 40);
        $this->assertEquals(10, $insights["mostwatched"]["start"]);
        $this->assertEquals(20, $insights["mostwatched"]["end"]);
        $this->assertEquals(20, $insights["dropoff"]["start"]);
    }
}
