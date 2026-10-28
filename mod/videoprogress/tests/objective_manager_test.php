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
 * objective_manager_test.php
 *
 * @package   mod_videoprogress
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_videoprogress;

use context_module;
use mod_videoprogress\objective\manager;

/**
 * Verifies unlimited learning objective persistence and rendering behavior.
 *
 * @covers \mod_videoprogress\objective\manager
 */
final class objective_manager_test extends \advanced_testcase {
    /**
     * Confirms that more than three objectives can be stored and displayed in order.
     *
     * @return void
     */
    public function test_unlimited_objectives_are_stored_and_rendered(): void {
        global $DB;

        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $activity = $this->getDataGenerator()->create_module("videoprogress", [
            "course" => $course->id,
        ]);
        $cm = get_coursemodule_from_instance("videoprogress", $activity->id, $course->id, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        $manager = new manager();

        foreach (range(1, 5) as $index) {
            $manager->save($activity->id, null, (object)[
                "plugin" => "bloom",
                "level" => "remember",
                "description" => "Objective " . $index,
                "enabled" => $index !== 5,
            ], $context);
        }

        self::assertSame(5, $DB->count_records("videoprogress_objectives", [
            "videoprogressid" => $activity->id,
        ]));
        $items = $manager->get_student_items($activity->id, $context, $cm->id);
        self::assertCount(4, $items);
        self::assertStringContainsString("Objective 1", $items[0]["html"]);
        self::assertStringContainsString("Objective 4", $items[3]["html"]);
    }

    /**
     * Confirms that objective type configuration is normalized by its subplugin.
     *
     * @return void
     */
    public function test_bloom_objective_configuration_is_normalized(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $activity = $this->getDataGenerator()->create_module("videoprogress", [
            "course" => $course->id,
        ]);
        $cm = get_coursemodule_from_instance("videoprogress", $activity->id, $course->id, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        $manager = new manager();

        $objective = $manager->save($activity->id, null, (object)[
            "plugin" => "bloom",
            "level" => "apply",
            "description" => "Apply the composition rule",
            "enabled" => 1,
        ], $context);
        $config = json_decode($objective->configdata, true);

        self::assertSame("apply", $config["level"]);
        self::assertSame("Apply the composition rule", $config["description"]);
        self::assertStringContainsString(
            "Apply",
            $manager->get_plugin("bloom")->get_summary($objective, $context)
        );
    }
}
