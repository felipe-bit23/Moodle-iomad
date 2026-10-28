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
 * backup_videoprogress_activity_task.class.php
 *
 * @package   mod_videoprogress
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

require_once($CFG->dirroot . '/mod/videoprogress/backup/moodle2/backup_videoprogress_stepslib.php');

/**
 * Defines the Moodle backup task for Video Progress activities.
 */
class backup_videoprogress_activity_task extends backup_activity_task {
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
        $this->add_step(new backup_videoprogress_activity_structure_step("videoprogress_structure", 'videoprogress.xml'));
    }

    /**
     * Rewrites Video Progress links so they remain valid when the activity is restored.
     *
     * @param mixed $content Caption or editor content.
     * @return string The resolved or formatted string value.
     */
    public static function encode_content_links($content): string {
        return $content;
    }
}
