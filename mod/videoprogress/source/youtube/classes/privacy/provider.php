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
 * provider.php
 *
 * @package   videoprogresssource_youtube
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace videoprogresssource_youtube\privacy;

/**
 * Declares that the YouTube source stores no personal data independently of the parent activity.
 */
class provider implements \core_privacy\local\metadata\null_provider {
    /**
     * Returns the Privacy API explanation for this stateless source plugin.
     *
     * @return string Language identifier describing the absence of personal data.
     */
    public static function get_reason(): string {
        return 'privacy:metadata';
    }
}
