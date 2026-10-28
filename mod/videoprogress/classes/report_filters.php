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
 * report_filters.php
 *
 * @package   mod_videoprogress
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_videoprogress;

use coding_exception;

/**
 * Validates, normalizes, and serializes report filter and sorting values.
 */
class report_filters {
    /**
     * @var string
     */
    public string $search = '';
    /**
     * @var int
     */
    public int $groupid = 0;
    /**
     * @var string
     */
    public string $status = "all";
    /**
     * @var string
     */
    public string $percentrange = "all";
    /**
     * @var int
     */
    public int $lastview = 0;
    /**
     * @var int
     */
    public int $page = 0;
    /**
     * @var int
     */
    public int $perpage = 20;
    /**
     * @var string
     */
    public string $sort = "name";
    /**
     * @var string
     */
    public string $direction = "asc";
    /**
     * @var array
     */
    public array $allowedgroupids = [];
    /**
     * @var bool
     */
    public bool $restrictgroups = false;

    /**
     * Reads report filters from the request and normalizes them against allowed values.
     *
     * @param array $allowedgroupids allowedgroupids value used by the operation.
     * @param bool $restrictgroups restrictgroups value used by the operation.
     * @return self The result produced by the operation.
     * @throws coding_exception
     */
    public static function from_request(array $allowedgroupids = [], bool $restrictgroups = false): self {
        $filters = new self();
        $filters->search = trim(optional_param("search", '', PARAM_TEXT));
        $filters->groupid = optional_param("groupid", 0, PARAM_INT);
        $filters->status = optional_param("status", "all", PARAM_ALPHA);
        $filters->percentrange = optional_param("percentrange", "all", PARAM_ALPHANUMEXT);
        $filters->lastview = optional_param("lastview", 0, PARAM_INT);
        $filters->page = max(0, optional_param("page", 0, PARAM_INT));
        $filters->perpage = optional_param("perpage", 20, PARAM_INT);
        $filters->sort = optional_param("sort", "name", PARAM_ALPHA);
        $filters->direction = optional_param("direction", "asc", PARAM_ALPHA);
        $filters->allowedgroupids = array_values(array_unique(array_map("intval", $allowedgroupids)));
        $filters->restrictgroups = $restrictgroups;
        $filters->normalise();
        return $filters;
    }

    /**
     * Sorts, validates, clamps, and merges a collection of watched intervals.
     *
     * @return void This method does not return a value.
     */
    public function normalise(): void {
        if (!in_array($this->status, ["all", "notstarted", "inprogress", "completed"], true)) {
            $this->status = "all";
        }
        if (!in_array($this->percentrange, ["all", "0", '1-24', '25-49', '50-74', '75-99', "100"], true)) {
            $this->percentrange = "all";
        }
        if (!in_array($this->lastview, [0, 7, 30, 90], true)) {
            $this->lastview = 0;
        }
        if (!in_array($this->perpage, [20, 50, 100], true)) {
            $this->perpage = 20;
        }
        if (!in_array($this->sort, ["name", "percent", "uniquewatched", "totalwatchtime", "lastview", "status"], true)) {
            $this->sort = "name";
        }
        if (!in_array($this->direction, ["asc", "desc"], true)) {
            $this->direction = "asc";
        }
        if ($this->groupid && !in_array($this->groupid, $this->allowedgroupids, true)) {
            $this->groupid = 0;
        }
    }

    /**
     * Returns normalized filters as URL parameters for pagination and report actions.
     *
     * @return array Structured data produced by the operation.
     */
    public function url_params(): array {
        return [
            "search" => $this->search,
            "groupid" => $this->groupid,
            "status" => $this->status,
            "percentrange" => $this->percentrange,
            "lastview" => $this->lastview,
            "perpage" => $this->perpage,
            "sort" => $this->sort,
            "direction" => $this->direction,
        ];
    }
}
