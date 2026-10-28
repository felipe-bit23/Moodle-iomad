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
 * restore_videoprogress_activity_task.class.php
 *
 * @package   mod_videoprogress
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

require_once($CFG->dirroot . '/mod/videoprogress/backup/moodle2/restore_videoprogress_stepslib.php');

/**
 * Defines the Moodle restore task and content-link mappings for Video Progress activities.
 */
class restore_videoprogress_activity_task extends restore_activity_task {
    /**
     * Defines activity-specific restore or backup settings.
     *
     * @return void This method does not return a value.
     */
    protected function define_my_settings(): void {
    }

    /**
     * Registers the activity-specific backup or restore execution steps.
     *
     * @return void This method does not return a value.
     */
    protected function define_my_steps(): void {
        $this->add_step(new restore_videoprogress_activity_structure_step("videoprogress_structure", 'videoprogress.xml'));
    }

    /**
     * Registers activity content fields that must be decoded during restore.
     *
     * @return array Structured data produced by the operation.
     */
    public static function define_decode_contents(): array {
        return [];
    }

    /**
     * Defines the content-link rewrite rules applied during activity restore.
     *
     * @return array Structured data produced by the operation.
     */
    public static function define_decode_rules(): array {
        return [];
    }

    /**
     * Defines the log record mappings restored for this activity.
     *
     * @return array Structured data produced by the operation.
     */
    public static function define_restore_log_rules(): array {
        return [];
    }

    /**
     * Defines course-level log mappings restored for this activity.
     *
     * @return array Structured data produced by the operation.
     */
    public static function define_restore_log_rules_for_course(): array {
        return [];
    }
}
