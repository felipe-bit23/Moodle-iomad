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
 * videoprogressobjective.php
 *
 * @package   mod_videoprogress
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_videoprogress\plugininfo;

use core\exception\moodle_exception;
use core\plugininfo\base;
use dml_exception;
use moodle_url;
use part_of_admin_tree;

/**
 * Describes Video Progress objective type subplugins to Moodle's plugin manager.
 */
class videoprogressobjective extends base {
    /**
     * Allows removal only when no learning objective still uses the subplugin.
     *
     * @return bool Whether Moodle may offer the uninstall action.
     * @throws dml_exception
     */
    public function is_uninstall_allowed(): bool {
        global $DB;
        return !$DB->record_exists("videoprogress_objectives", ["plugin" => $this->name]);
    }

    /**
     * Returns the administration page that lists installed objective types.
     *
     * @return moodle_url Objective subplugin management URL.
     * @throws moodle_exception
     */
    public static function get_manage_url(): moodle_url {
        return new moodle_url('/mod/videoprogress/admin_plugins.php', [
            "type" => "videoprogressobjective",
        ]);
    }

    /**
     * Returns the unique administration section for this subplugin's settings.
     *
     * @return string Administration section identifier.
     */
    public function get_settings_section_name(): string {
        return "{$this->type}_{$this->name}";
    }

    /**
     * Loads an optional settings file supplied by the objective type subplugin.
     *
     * @param part_of_admin_tree $adminroot Moodle administration tree.
     * @param string $parentnodename Parent node receiving the settings page.
     * @param bool $hassiteconfig Whether the current user can configure the site.
     * @return void
     */
    public function load_settings(part_of_admin_tree $adminroot, $parentnodename, $hassiteconfig): void {
        $plugininfo = $this;
        if (!$this->is_installed_and_upgraded() || !$hassiteconfig ||
            !file_exists($this->full_path('settings.php'))) {
            return;
        }

        $settings = new \admin_settingpage(
            $this->get_settings_section_name(),
            $this->displayname,
            'moodle/site:config'
        );
        if ($adminroot->fulltree) {
            include($this->full_path('settings.php'));
        }
        if ($settings) {
            $adminroot->add($parentnodename, $settings);
        }
    }
}
