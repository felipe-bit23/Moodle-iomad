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
 * Course settings with IOMAD Multi-Tenant support.
 *
 * @package     theme_trema
 * @copyright   2019-2026 Trema - {@link https://trema.tech/}
 * @copyright   2024-2026 TNG Consulting Inc. - {@link https://www.tngconsulting.ca/}
 * @author      Rodrigo Mady
 * @author      Trevor Furtado
 * @author      Michael Milette
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// Content settings.
$page = new admin_settingpage('theme_trema_courses', get_string('courses'));

// IOMAD Multi-Tenant Company Selector.
if (!empty($tenantselector)) {
    $setting = (class_exists('theme_trema_admin_setting_html')
        ? new theme_trema_admin_setting_html('theme_trema_tenant_selector_courses' . $sfx, '', $tenantselector)
        : new admin_setting_heading('theme_trema_tenant_selector_courses' . $sfx, '', $tenantselector));
    $page->add($setting);
}

// Settings for Courses.
$name = 'theme_trema_courses' . $sfx;
$title = get_string('courses');
$description = '';
$format = FORMAT_MARKDOWN;
$setting = new admin_setting_heading($name, $title, $description, $format);
$page->add($setting);

// Course enrolment page format.
$name = 'theme_trema/courseenrolmentpageformat' . $sfx;
$title = get_string('courseenrolmentpageformat', $themename);
$description = get_string('courseenrolmentpageformat_desc', $themename);
$choices = [
    "fullwidth" => get_string('courseenrolmentpagefull', $themename),
    "card" => get_string('courseenrolmentpagecard', $themename),
];
$default = theme_trema_get_global_default('courseenrolmentpageformat', 'fullwidth', $editingcompanyid);
$setting = new admin_setting_configselect($name, $title, $description, $default, $choices);
$setting->set_updatedcallback('theme_reset_all_caches');
$page->add($setting);

// Show activity navigation.
$name = 'theme_trema/shownactivitynavigation' . $sfx;
$title = get_string('shownactivitynavigation', $themename);
$description = get_string('shownactivitynavigation_desc', $themename);
$default = theme_trema_get_global_default('shownactivitynavigation', 0, $editingcompanyid);
$setting = new admin_setting_configcheckbox($name, $title, $description, $default);
$setting->set_updatedcallback('theme_reset_all_caches');
$page->add($setting);

// Show activity icons.
$name = 'theme_trema/showactivityicons' . $sfx;
$title = get_string('showactivityicons', $themename);
$description = get_string('showactivityicons_desc', $themename);
$default = theme_trema_get_global_default('showactivityicons', 1, $editingcompanyid);
$setting = new admin_setting_configcheckbox($name, $title, $description, $default);
$setting->set_updatedcallback('theme_reset_all_caches');
$page->add($setting);

$settings->add($page);
