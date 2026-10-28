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
 * Core navigation with IOMAD Multi-Tenant custom menu items support.
 *
 * @package   theme_trema
 * @category  navigation
 * @copyright 2022-2026 TNG Consulting Inc. - {@link https://www.tngconsulting.ca/}
 * @author    Michael Milette
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace theme_trema\output;

use filter_manager;
use renderer_base;

defined('MOODLE_INTERNAL') || die();

/**
 * Custom primary navigation class for the Trema theme.
 */
class primary_navigation extends \core\navigation\output\primary {
    /**
     * Custom menu items reside on the same level as the original nodes.
     * Resolves company-specific custom menu items (from Trema settings or IOMAD company record) before falling back to $CFG->custommenuitems.
     *
     * @param renderer_base $output The renderer base instance.
     * @return array An array of custom menu items.
     */
    protected function get_custom_menu(renderer_base $output): array {
        global $CFG;

        require_once($CFG->dirroot . '/theme/trema/locallib.php');

        $originalcustommenuitems = $CFG->custommenuitems ?? '';
        $tenantcustommenu = theme_trema_get_setting('custommenuitems', null, '');

        if (!empty($tenantcustommenu)) {
            $CFG->custommenuitems = $tenantcustommenu;
        }

        if (empty($CFG->custommenuitems)) {
            $CFG->custommenuitems = $originalcustommenuitems;
            return [];
        }

        if (!empty(theme_trema_get_setting('navfilter')) && strpos($CFG->custommenuitems, '}') !== false) {
            $filtermanager = filter_manager::instance();
            $CFG->custommenuitems = $filtermanager->filter_string($CFG->custommenuitems, \context_system::instance());
        }
        $nodes = parent::get_custom_menu($output);

        $CFG->custommenuitems = $originalcustommenuitems;

        return $nodes;
    }
}
