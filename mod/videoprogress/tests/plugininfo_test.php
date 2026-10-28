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
 * plugininfo_test.php
 *
 * @package   mod_videoprogress
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_videoprogress;

use core_plugin_manager;
use mod_videoprogress\plugininfo\videoprogresscontent;
use mod_videoprogress\plugininfo\videoprogressmaterial;
use mod_videoprogress\plugininfo\videoprogressobjective;
use mod_videoprogress\plugininfo\videoprogresssource;

/**
 * Verifies that Moodle resolves the custom Video Progress subplugin types correctly.
 *
 * @covers \mod_videoprogress\plugininfo\videoprogresssource
 * @covers \mod_videoprogress\plugininfo\videoprogressmaterial
 * @covers \mod_videoprogress\plugininfo\videoprogresscontent
 * @covers \mod_videoprogress\plugininfo\videoprogressobjective
 */
final class plugininfo_test extends \advanced_testcase {
    /**
     * Confirms that video sources use the dedicated plugin information class.
     *
     * @return void
     */
    public function test_source_plugininfo_class_is_resolved(): void {
        self::assertSame(
            '\\' . videoprogresssource::class,
            core_plugin_manager::resolve_plugininfo_class("videoprogresssource")
        );
    }

    /**
     * Confirms that material formats use the dedicated plugin information class.
     *
     * @return void
     */
    public function test_material_plugininfo_class_is_resolved(): void {
        self::assertSame(
            '\\' . videoprogressmaterial::class,
            core_plugin_manager::resolve_plugininfo_class("videoprogressmaterial")
        );
    }

    /**
     * Confirms that synchronized content formats use the dedicated plugin information class.
     *
     * @return void
     */
    public function test_content_plugininfo_class_is_resolved(): void {
        self::assertSame(
            '\\' . videoprogresscontent::class,
            core_plugin_manager::resolve_plugininfo_class("videoprogresscontent")
        );
    }

    /**
     * Confirms that objective formats use the dedicated plugin information class.
     *
     * @return void
     */
    public function test_objective_plugininfo_class_is_resolved(): void {
        self::assertSame(
            '\\' . videoprogressobjective::class,
            core_plugin_manager::resolve_plugininfo_class("videoprogressobjective")
        );
    }

    /**
     * Confirms that all plugin information classes return their management endpoints.
     *
     * @return void
     */
    public function test_subplugin_manage_urls_are_available(): void {
        $sourceurl = videoprogresssource::get_manage_url();
        $materialurl = videoprogressmaterial::get_manage_url();
        $contenturl = videoprogresscontent::get_manage_url();
        $objectiveurl = videoprogressobjective::get_manage_url();

        self::assertStringContainsString('type=videoprogresssource', $sourceurl->out(false));
        self::assertStringContainsString('type=videoprogressmaterial', $materialurl->out(false));
        self::assertStringContainsString('type=videoprogresscontent', $contenturl->out(false));
        self::assertStringContainsString('type=videoprogressobjective', $objectiveurl->out(false));
    }
}
