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
 * format.php
 *
 * @package   mod_videoprogress
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_videoprogress;

use coding_exception;

/**
 * Provides presentation-neutral formatting helpers for durations and timeline data.
 */
class format {
    /**
     * Formats a duration in seconds as a readable video timecode.
     *
     * @param float $seconds seconds value used by the operation.
     * @return string The resolved or formatted string value.
     */
    public static function duration(float $seconds): string {
        $seconds = max(0, (int)round($seconds));
        $hours = intdiv($seconds, HOURSECS);
        $minutes = intdiv($seconds % HOURSECS, MINSECS);
        $remaining = $seconds % MINSECS;
        return $hours > 0
            ? sprintf('%02d:%02d:%02d', $hours, $minutes, $remaining)
            : sprintf('%02d:%02d', $minutes, $remaining);
    }

    /**
     * Converts a compact view map into accessible timeline bucket presentation data.
     *
     * @param string|null $viewmap Compact timeline intensity buckets.
     * @param float $duration Authoritative video duration in seconds.
     * @param bool $aggregated aggregated value used by the operation.
     * @return array Structured data produced by the operation.
     * @throws coding_exception
     */
    public static function timeline(string|null $viewmap, float $duration, bool $aggregated = false): array {
        $map = view_map::decode($viewmap);
        $maximum = max(1, max($map));
        $bucketduration = $duration > 0 ? $duration / count($map) : 0;
        $items = [];
        foreach ($map as $index => $views) {
            $start = $index * $bucketduration;
            $end = min($duration, ($index + 1) * $bucketduration);
            $level = $views > 0 ? max(1, min(5, (int)ceil(($views / $maximum) * 5))) : 0;
            $items[] = [
                "level" => $level,
                "empty" => $views === 0,
                "views" => $views,
                "start" => self::duration($start),
                "end" => self::duration($end),
                "tooltip" => get_string($aggregated ? "timelineaggregatedtooltip" : "timelinetooltip", "videoprogress", (object)[
                    "start" => self::duration($start),
                    "end" => self::duration($end),
                    "views" => $views,
                ]),
            ];
        }
        return $items;
    }

    /**
     * Builds the textual accessible alternative for a watched-segment timeline.
     *
     * @param string|null $segmentsjson segmentsjson value used by the operation.
     * @return string The resolved or formatted string value.
     * @throws coding_exception
     */
    public static function segment_alternative(string|null $segmentsjson): string {
        $segments = segment_manager::decode($segmentsjson);
        if (!$segments) {
            return get_string("nosegmentsalternative", "videoprogress");
        }
        $ranges = array_map(static fn(array $segment): string => get_string("timerange", "videoprogress", (object)[
            "start" => self::duration($segment[0]),
            "end" => self::duration($segment[1]),
        ]), $segments);
        return get_string("segmentalternative", "videoprogress", implode('; ', $ranges));
    }
}
