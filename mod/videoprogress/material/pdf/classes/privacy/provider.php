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
 * @package   videoprogressmaterial_pdf
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace videoprogressmaterial_pdf\privacy;

use core_privacy\local\metadata\null_provider;

/**
 * Declares that this subplugin stores no personal data independently of the parent activity.
 */
class provider implements null_provider {
    /**
     * Returns the Privacy API explanation for a subplugin that stores no personal data.
     *
     * @return string The resolved or formatted string value.
     */
    public static function get_reason(): string {
        return 'privacy:metadata';
    }
}
