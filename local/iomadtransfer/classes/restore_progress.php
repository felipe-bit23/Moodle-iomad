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
 * Live restore progress reporter for local_iomadtransfer.
 *
 * @package    local_iomadtransfer
 * @copyright  2026 IOMAD Multi-Tenant Consolidation Engine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_iomadtransfer;

use core\progress\base;

defined('MOODLE_INTERNAL') || die();

/**
 * Updates the 'restored' percentage column (0-100) on local_iomadtransfer_request.
 */
class restore_progress extends base {
    /** @var int */
    protected int $requestid;

    /** @var int */
    protected int $lastupdate = 0;

    /**
     * Constructor.
     *
     * @param int $requestid
     */
    public function __construct(int $requestid) {
        $this->requestid = $requestid;
    }

    /**
     * Updates progress in DB at most once every 2 seconds.
     */
    public function update_progress(): void {
        global $DB;
        $now = time();
        if (($now - $this->lastupdate) < 2) {
            return;
        }
        $this->lastupdate = $now;
        [$min, $max] = $this->get_progress_proportion_range();
        $pct = (int)round($min * 100);
        if ($pct > 100) {
            $pct = 100;
        }
        if ($pct > 0) {
            $DB->set_field('local_iomadtransfer_request', 'restored', $pct, ['id' => $this->requestid]);
            $DB->set_field('local_iomadtransfer_request', 'timemodified', $now, ['id' => $this->requestid]);
        }
    }
}
