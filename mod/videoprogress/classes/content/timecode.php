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
 * timecode.php
 *
 * @package   mod_videoprogress
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_videoprogress\content;

use mod_videoprogress\format;

/**
 * Parses and formats precise timestamps used by synchronized video content points.
 */
class timecode {
    /**
     * Parses a minute or hour timecode into a precise number of seconds.
     *
     * @param string $value Raw value to parse or normalize.
     * @return float|null The calculated value in seconds or percentage units.
     */
    public static function parse(string $value): float|null {
        $value = trim($value);
        if (!preg_match('/^(?:(\d{1,3}):)?([0-5]?\d):([0-5]\d)(?:\.(\d{1,3}))?$/', $value, $matches)) {
            return null;
        }
        $hours = empty($matches[1]) ? 0 : (int)$matches[1];
        $minutes = (int)$matches[2];
        $seconds = (int)$matches[3];
        $milliseconds = isset($matches[4]) ? (float)('0.' . str_pad($matches[4], 3, "0")) : 0.0;
        return round(($hours * HOURSECS) + ($minutes * MINSECS) + $seconds + $milliseconds, 3);
    }

    /**
     * Formats a numeric video position for display as a timecode.
     *
     * @param float $seconds seconds value used by the operation.
     * @return string The resolved or formatted string value.
     */
    public static function format(float $seconds): string {
        return format::duration($seconds);
    }
}
