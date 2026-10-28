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
 * view_map.php
 *
 * @package   mod_videoprogress
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_videoprogress;

/**
 * Builds compact timeline intensity maps and aggregated pedagogical insights.
 */
class view_map {
    /**
     * Buckets
     */
    public const DEFAULT_BUCKETS = 120;

    /**
     * Decodes persisted JSON data into a validated normalized structure.
     *
     * @param string|null $json json value used by the operation.
     * @param int $buckets buckets value used by the operation.
     * @return array Structured data produced by the operation.
     */
    public static function decode(string|null $json, int $buckets = self::DEFAULT_BUCKETS): array {
        $map = json_decode($json ?? '', true);
        if (!is_array($map)) {
            return array_fill(0, $buckets, 0);
        }
        $map = array_values(array_map(static fn($value): int => max(0, (int)$value), $map));
        return array_pad(array_slice($map, 0, $buckets), $buckets, 0);
    }

    /**
     * Adds a watched interval to the compact view map buckets.
     *
     * @param array $map map value used by the operation.
     * @param float $start Interval start position in seconds.
     * @param float $end Interval end position in seconds.
     * @param float $duration Authoritative video duration in seconds.
     * @param int $buckets buckets value used by the operation.
     * @return array Structured data produced by the operation.
     */
    public static function add_segment(array $map, float $start, float $end, float $duration,
                                       int $buckets = self::DEFAULT_BUCKETS): array {
        $map = array_pad(array_slice(array_values($map), 0, $buckets), $buckets, 0);
        if ($duration <= 0 || $end <= $start) {
            return $map;
        }
        $startbucket = max(0, min($buckets - 1, (int)floor(($start / $duration) * $buckets)));
        $endbucket = max($startbucket, min($buckets - 1, (int)floor((max($start, $end - 0.001) / $duration) * $buckets)));
        for ($index = $startbucket; $index <= $endbucket; $index++) {
            $map[$index] = min(PHP_INT_MAX, (int)$map[$index] + 1);
        }
        return $map;
    }

    /**
     * Aggregates multiple view maps into a single class-wide intensity map.
     *
     * @param array $maps View maps to aggregate.
     * @param int $buckets buckets value used by the operation.
     * @return array Structured data produced by the operation.
     */
    public static function aggregate(array $maps, int $buckets = self::DEFAULT_BUCKETS): array {
        $aggregate = array_fill(0, $buckets, 0);
        foreach ($maps as $map) {
            $decoded = is_string($map) ? self::decode($map, $buckets) : array_pad(array_slice($map, 0, $buckets), $buckets, 0);
            foreach ($decoded as $index => $value) {
                $aggregate[$index] += max(0, (int)$value);
            }
        }
        return $aggregate;
    }

    /**
     * Identifies the most watched, least watched, and largest audience-drop regions.
     *
     * @param array $map map value used by the operation.
     * @param float $duration Authoritative video duration in seconds.
     * @return array Structured data produced by the operation.
     */
    public static function insights(array $map, float $duration): array {
        if (!$map || $duration <= 0) {
            return ["mostwatched" => null, "leastwatched" => null, "dropoff" => null];
        }
        $count = count($map);
        $bucketduration = $duration / $count;
        $maximum = max($map);
        $positive = array_filter($map, static fn($value): bool => $value > 0);
        $minimum = $positive ? min($positive) : 0;
        $mostindex = (int)array_search($maximum, $map, true);
        $leastindex = $minimum > 0 ? (int)array_search($minimum, $map, true) : null;
        $largestdrop = 0;
        $dropindex = null;
        for ($index = 1; $index < $count; $index++) {
            $drop = $map[$index - 1] - $map[$index];
            if ($drop > $largestdrop) {
                $largestdrop = $drop;
                $dropindex = $index;
            }
        }
        $range = static fn(int $index): array => [
            "start" => round($index * $bucketduration, 3),
            "end" => round(min($duration, ($index + 1) * $bucketduration), 3),
            "views" => (int)$map[$index],
        ];
        return [
            "mostwatched" => $range($mostindex),
            "leastwatched" => $leastindex === null ? null : $range($leastindex),
            "dropoff" => $dropindex === null ? null : $range($dropindex),
        ];
    }
}
