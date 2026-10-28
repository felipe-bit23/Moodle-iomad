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
 * tracking_manager.php
 *
 * @package   mod_videoprogress
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_videoprogress;

use stdClass;

/**
 * Validates raw heartbeats, playback plausibility, sequence ordering, and anti-skip behavior.
 */
class tracking_manager {
    /**
     * Latency.
     */
    private const LATENCY_TOLERANCE = 3.0;

    /**
     * Small player timing difference accepted when the real ended event is received.
     */
    private const END_TOLERANCE = 2.0;

    /**
     * Validates raw player tracking against sequence, timing, rate, and anti-skip rules.
     *
     * @param stdClass $activity Activity configuration record.
     * @param stdClass $progress Persisted student progress record.
     * @param stdClass|false|null $session Current playback session record, when available.
     * @param array $data Validated input or tracking data.
     * @param int $now Current server timestamp.
     * @return array Structured data produced by the operation.
     */
    public function validate(stdClass $activity, stdClass $progress, stdClass|false|null $session, array $data, int $now): array {
        $duration = max(0.0, (float)$data["duration"]);
        $current = max(0.0, min($duration, (float)$data["currentposition"]));
        $rate = max(0.25, min(4.0, (float)$data["playbackrate"]));
        if ((float)$activity->maxplaybackrate > 0) {
            $rate = min($rate, (float)$activity->maxplaybackrate);
        }
        if ($session && (int)$data["sequence"] <= (int)$session->sequence) {
            return ["accepted" => false, "reason" => "stale", "correctposition" => (float)$session->lastposition];
        }

        $elapsed = $session ? max(0.0, min(120.0, $now - (int)$session->lastheartbeat)) : 10.0;
        if ($session && !empty($session->lastclienttime) && !empty($data["clienttime"])) {
            $clientelapsed = (int)$data["clienttime"] - (int)$session->lastclienttime;
            if ($clientelapsed > 0 && $clientelapsed <= 120 && (int)$data["clienttime"] <= $now + 300) {
                // Queued offline updates can arrive seconds apart even though their playback happened earlier.
                $elapsed = (float)$clientelapsed;
            }
        }
        $maxcontent = max(self::LATENCY_TOLERANCE, $elapsed * $rate + self::LATENCY_TOLERANCE);
        $segments = segment_manager::decode($progress->watchedsegments ?? '');
        $segment = segment_manager::validate_interval([
            (float)$data["segmentstart"],
            (float)$data["segmentend"],
        ], $duration);
        $acceptedsegment = null;
        if ($segment) {
            [$start, $end] = $segment;
            if (($end - $start) > $maxcontent) {
                $end = min($duration, $start + $maxcontent);
            }
            $anchored = $session
                ? abs($start - (float)$session->lastposition) <= self::LATENCY_TOLERANCE + $rate ||
                segment_manager::contains_position($segments, $start, self::LATENCY_TOLERANCE)
                : $activity->allowseek || $start <= self::LATENCY_TOLERANCE ||
                segment_manager::contains_position($segments, $start, self::LATENCY_TOLERANCE);
            if ($anchored) {
                $acceptedsegment = $end > $start ? [$start, $end] : null;
            }
        }

        $correctposition = $current;
        $seekblocked = false;
        if (!$activity->allowseek) {
            $alreadywatched = segment_manager::contains_position($segments, $current, 0.5);
            $legitimateend = $session
                ? (float)$session->lastposition + $maxcontent
                : ($acceptedsegment[1] ?? segment_manager::furthest_watched_position($segments));
            $explicitillegal = $data["playerstate"] === "seeking" && !$alreadywatched &&
                (!$acceptedsegment || $current > $acceptedsegment[1] + 0.5);
            if ($explicitillegal || ($current > $legitimateend + 0.5 && !$alreadywatched)) {
                $correctposition = min($duration, $session
                    ? (float)$session->lastposition
                    : segment_manager::furthest_watched_position($segments));
                $seekblocked = true;
                $acceptedsegment = null;
            }
        }

        // Player APIs can emit their final timeupdate slightly before the exact duration.
        // Only close that tiny gap after a genuine ended event and an accepted segment at the end.
        if ($data["playerstate"] === "ended" && !$seekblocked && $acceptedsegment && $duration > 0 &&
            $current >= $duration - self::END_TOLERANCE &&
            $acceptedsegment[1] >= $duration - self::END_TOLERANCE) {
            $acceptedsegment[1] = $duration;
            $correctposition = $duration;
        }

        $watchtime = 0.0;
        if ($acceptedsegment) {
            $contentseconds = $acceptedsegment[1] - $acceptedsegment[0];
            $watchtime = min($elapsed + self::LATENCY_TOLERANCE, $contentseconds / max(0.25, $rate));
        }

        return [
            "accepted" => true,
            "segment" => $acceptedsegment,
            "currentposition" => $correctposition,
            "duration" => $duration,
            "playbackrate" => $rate,
            "watchtime" => round(max(0, $watchtime), 3),
            "seekblocked" => $seekblocked,
            "reason" => $seekblocked ? "seekblocked" : '',
        ];
    }
}
