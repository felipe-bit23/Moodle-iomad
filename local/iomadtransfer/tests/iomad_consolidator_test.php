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
 * PHPUnit tests for local_iomadtransfer consolidator and backup preparation.
 *
 * @package    local_iomadtransfer
 * @copyright  2026 IOMAD Multi-Tenant Consolidation Engine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_iomadtransfer;

use advanced_testcase;

defined('MOODLE_INTERNAL') || die();

/**
 * Unit tests for iomad_consolidator.
 */
final class iomad_consolidator_test extends advanced_testcase {
    /**
     * Tests activity name normalization and "(copy)" suffix stripping.
     */
    public function test_normalize_name(): void {
        $this->assertSame('examen final', iomad_consolidator::normalize_name('  Examen Final (copy) '));
        $this->assertSame('examen final', iomad_consolidator::normalize_name('Examen Final (copia 2)'));
        $this->assertSame('tarea 1', iomad_consolidator::normalize_name('Tarea   1'));
    }

    /**
     * Tests that prepare_extracted_backup wipes groups.xml cleanly.
     */
    public function test_prepare_extracted_backup_wipes_groups(): void {
        $this->resetAfterTest(true);
        $tmpdir = make_request_directory();
        $groupsfile = $tmpdir . '/groups.xml';
        file_put_contents($groupsfile, '<groups><group id="99"><name>Old Group</name></group></groups>');

        iomad_consolidator::prepare_extracted_backup($tmpdir);

        $cleaned = file_get_contents($groupsfile);
        $this->assertStringNotContainsString('Old Group', $cleaned);
        $this->assertStringContainsString('<groups>', $cleaned);
    }
}
