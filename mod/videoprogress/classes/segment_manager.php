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
 * segment_manager.php
 *
 * @package   mod_videoprogress
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_videoprogress;

/**
 * Validates and consolidates watched intervals used as the authoritative progress source.
 */
class segment_manager {
    /**
     * Precision
     */
    private const PRECISION = 3;
    /**
     * Tolerance
     */
    private const ADJACENCY_TOLERANCE = 0.05;

    /**
     * Decodes persisted JSON data into a validated normalized structure.
     *
     * @param string|null $json json value used by the operation.
     * @return array Structured data produced by the operation.
     */
    public static function decode(string|null $json): array {
        if ($json === null || trim($json) === '') {
            return [];
        }
        $segments = json_decode($json, true);
        if (!is_array($segments)) {
            return [];
        }
        return self::normalise($segments);
    }

    /**
     * Encodes normalized watched segments for database persistence.
     *
     * @param array $segments Collection of watched intervals.
     * @return string The resolved or formatted string value.
     */
    public static function encode(array $segments): string {
        return json_encode(self::normalise($segments), JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * Validates and clamps one raw watched interval to the video duration.
     *
     * @param mixed $segment segment value used by the operation.
     * @param float $duration Authoritative video duration in seconds.
     * @return array|null Structured data produced by the operation.
     */
    public static function validate_interval(mixed $segment, float $duration = 0): array|null {
        if (!is_array($segment) || count($segment) !== 2 || !is_numeric($segment[0]) || !is_numeric($segment[1])) {
            return null;
        }
        $start = round((float)$segment[0], self::PRECISION);
        $end = round((float)$segment[1], self::PRECISION);
        if (!is_finite($start) || !is_finite($end) || $start < 0 || $end <= $start) {
            return null;
        }
        if ($duration > 0) {
            if ($start >= $duration) {
                return null;
            }
            $end = min($end, $duration);
        }
        return $end > $start ? [$start, $end] : null;
    }

    /**
     * Sorts, validates, clamps, and merges a collection of watched intervals.
     *
     * @param array $segments Collection of watched intervals.
     * @param float $duration Authoritative video duration in seconds.
     * @return array Structured data produced by the operation.
     */
    public static function normalise(array $segments, float $duration = 0): array {
        $valid = [];
        foreach ($segments as $segment) {
            if ($interval = self::validate_interval($segment, $duration)) {
                $valid[] = $interval;
            }
        }
        usort($valid, static fn(array $a, array $b): int => $a[0] <=> $b[0] ?: $a[1] <=> $b[1]);
        $merged = [];
        foreach ($valid as $interval) {
            $lastindex = count($merged) - 1;
            if ($lastindex < 0 || $interval[0] > $merged[$lastindex][1] + self::ADJACENCY_TOLERANCE) {
                $merged[] = $interval;
                continue;
            }
            $merged[$lastindex][1] = max($merged[$lastindex][1], $interval[1]);
        }
        return $merged;
    }

    /**
     * Merges new intervals with existing watched segments and clamps them to the duration.
     *
     * @param array $existing existing value used by the operation.
     * @param array $incoming incoming value used by the operation.
     * @param float $duration Authoritative video duration in seconds.
     * @return array Structured data produced by the operation.
     */
    public static function merge(array $existing, array $incoming, float $duration = 0): array {
        return self::normalise(array_merge($existing, $incoming), $duration);
    }

    /**
     * Calculates the total duration of unique content represented by merged intervals.
     *
     * @param array $segments Collection of watched intervals.
     * @return float The calculated value in seconds or percentage units.
     */
    public static function unique_seconds(array $segments): float {
        $total = 0.0;
        foreach (self::normalise($segments) as [$start, $end]) {
            $total += $end - $start;
        }
        return round($total, self::PRECISION);
    }

    /**
     * Checks whether two timeline intervals overlap within the supplied tolerance.
     *
     * @param array $segments Collection of watched intervals.
     * @param float $start Interval start position in seconds.
     * @param float $end Interval end position in seconds.
     * @return bool Whether the evaluated condition or operation succeeded.
     */
    public static function intersects(array $segments, float $start, float $end): bool {
        if ($end <= $start) {
            return false;
        }
        foreach (self::normalise($segments) as [$watchedstart, $watchedend]) {
            if ($start < $watchedend && $end > $watchedstart) {
                return true;
            }
        }
        return false;
    }

    /**
     * Checks whether a timeline position belongs to an already watched interval.
     *
     * @param array $segments Collection of watched intervals.
     * @param float $position Video position in seconds.
     * @param float $tolerance Allowed positional tolerance in seconds.
     * @return bool Whether the evaluated condition or operation succeeded.
     */
    public static function contains_position(array $segments, float $position, float $tolerance = 1.0): bool {
        foreach (self::normalise($segments) as [$start, $end]) {
            if ($position >= $start - $tolerance && $position <= $end + $tolerance) {
                return true;
            }
        }
        return false;
    }

    /**
     * Returns the greatest validated endpoint in the watched segment collection.
     *
     * @param array $segments Collection of watched intervals.
     * @return float The calculated value in seconds or percentage units.
     */
    public static function furthest_watched_position(array $segments): float {
        $normalised = self::normalise($segments);
        return $normalised ? (float)end($normalised)[1] : 0.0;
    }
}
