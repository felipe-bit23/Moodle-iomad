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
 * segment_manager_test.php
 *
 * @package   mod_videoprogress
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_videoprogress;

/**
 * Provides PHPUnit coverage for segment manager behavior.
 *
 * @covers \mod_videoprogress\segment_manager
 */
final class segment_manager_test extends \advanced_testcase {
    /**
     * Verifies that adjacent segments are merged.
     *
     * @return void This method does not return a value.
     */
    public function test_adjacent_segments_are_merged(): void {
        $this->assertSame([[0.0, 20.0]], segment_manager::normalise([[0, 10], [10, 20]]));
    }

    /**
     * Verifies that overlapping segments are merged.
     *
     * @return void This method does not return a value.
     */
    public function test_overlapping_segments_are_merged(): void {
        $this->assertSame([[0.0, 15.0]], segment_manager::normalise([[0, 10], [5, 15]]));
        $this->assertSame([[0.0, 100.0]], segment_manager::normalise([[0, 100], [50, 70]]));
    }

    /**
     * Verifies that separate segments remain separate.
     *
     * @return void This method does not return a value.
     */
    public function test_separate_segments_remain_separate(): void {
        $this->assertSame([[0.0, 10.0], [20.0, 30.0]], segment_manager::normalise([[0, 10], [20, 30]]));
    }

    /**
     * Verifies that invalid and unordered segments are handled.
     *
     * @return void This method does not return a value.
     */
    public function test_invalid_and_unordered_segments_are_handled(): void {
        $segments = segment_manager::normalise([
            [40, 50], [0, 10], [10, 20], [-1, 5], [30, 20], [0, 10], [55, 90], [90, 130],
        ], 100);
        $this->assertSame([[0.0, 20.0], [40.0, 50.0], [55.0, 100.0]], $segments);
        $this->assertEquals(75, segment_manager::unique_seconds($segments));
    }

    /**
     * Verifies that many small segments are consolidated.
     *
     * @return void This method does not return a value.
     */
    public function test_many_small_segments_are_consolidated(): void {
        $segments = [];
        for ($index = 0; $index < 100; $index++) {
            $segments[] = [$index, $index + 1];
        }
        $this->assertSame([[0.0, 100.0]], segment_manager::normalise(array_reverse($segments)));
    }
}
