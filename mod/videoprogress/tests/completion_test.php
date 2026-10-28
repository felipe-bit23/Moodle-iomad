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
 * completion_test.php
 *
 * @package   mod_videoprogress
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_videoprogress;

/**
 * Provides PHPUnit coverage for completion behavior.
 *
 * @covers \mod_videoprogress\progress_manager
 */
final class completion_test extends \advanced_testcase {
    /**
     * Verifies that confirmation and percentage are both required.
     *
     * @return void This method does not return a value.
     */
    public function test_confirmation_and_percentage_are_both_required(): void {
        $manager = new progress_manager();
        $activity = (object)["completionpercent" => 80, "requireconfirmation" => 1];
        $progress = (object)["percent" => 90, "confirmation" => 0];
        $this->assertFalse($manager->is_complete($activity, $progress));
        $progress->confirmation = 1;
        $this->assertTrue($manager->is_complete($activity, $progress));
        $progress->percent = 79.99;
        $this->assertFalse($manager->is_complete($activity, $progress));
    }
}
