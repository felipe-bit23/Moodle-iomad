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
 * timecode_test.php
 *
 * @package   mod_videoprogress
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_videoprogress;

/**
 * Provides PHPUnit coverage for timecode behavior.
 *
 * @covers \mod_videoprogress\content\timecode
 */
final class timecode_test extends \advanced_testcase {
    /**
     * Verifies that parses minutes seconds and hours.
     *
     * @return void This method does not return a value.
     */
    public function test_parses_minutes_seconds_and_hours(): void {
        $this->assertSame(72.0, content\timecode::parse('01:12'));
        $this->assertSame(3723.5, content\timecode::parse('1:02:03.500'));
    }

    /**
     * Verifies that rejects invalid timecodes.
     *
     * @return void This method does not return a value.
     */
    public function test_rejects_invalid_timecodes(): void {
        $this->assertNull(content\timecode::parse('1:72'));
        $this->assertNull(content\timecode::parse('-00:10'));
        $this->assertNull(content\timecode::parse("text"));
    }
}
