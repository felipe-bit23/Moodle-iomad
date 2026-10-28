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
 * Login settings with IOMAD Multi-Tenant support.
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

$page = new admin_settingpage('theme_trema_login', get_string('login', $themename));

// IOMAD Multi-Tenant Company Selector.
if (!empty($tenantselector)) {
    $setting = (class_exists('theme_trema_admin_setting_html')
        ? new theme_trema_admin_setting_html('theme_trema_tenant_selector_login' . $sfx, '', $tenantselector)
        : new admin_setting_heading('theme_trema_tenant_selector_login' . $sfx, '', $tenantselector));
    $page->add($setting);
}

// Login page style.
$name = 'theme_trema/loginpagestyle' . $sfx;
$title = get_string('loginpagestyle', $themename);
$description = '';
$choices = [
    "none" => get_string('none'),
    "image" => get_string('image', $themename, ''),
];
$default = theme_trema_get_global_default('loginpagestyle', 'none', $editingcompanyid);
$setting = new admin_setting_configselect($name, $title, $description, $default, $choices);
$setting->set_updatedcallback('theme_reset_all_caches');
$page->add($setting);

// Always register loginbackgroundimage so the upload field never disappears.
if (true) {
    // Login background image.
    $name = 'theme_trema/loginbackgroundimage' . $sfx;
    $title = get_string('loginbackgroundimage', $themename);
    $description = '';
    $setting = new admin_setting_configstoredfile($name, $title, $description, 'loginbackgroundimage' . $sfx);
    $setting->set_updatedcallback('theme_reset_all_caches');
    $page->add($setting);
}

// Login box text alignment.
$choices = [
    'center'    => get_string('middle', 'editor'),
    'left'      => get_string('left', 'editor'),
    'halfleft'  => get_string('halfleft', $themename),
    'halfright' => get_string('halfright', $themename),
    'right'     => get_string('right', 'editor'),
];
$name = 'theme_trema/loginboxcontentalign' . $sfx;
$title = get_string('loginboxcontentalign', $themename);
$description = get_string('loginboxcontentalign_desc', $themename);
$default = theme_trema_get_global_default('loginboxcontentalign', 'center', $editingcompanyid);
$setting = new admin_setting_configselect($name, $title, $description, $default, $choices);
$setting->set_updatedcallback('theme_reset_all_caches');
$page->add($setting);

$name = 'theme_trema/loginpagecreatefirst' . $sfx;
$title = get_string('loginpagecreatefirst', $themename);
$description = get_string('loginpagecreatefirst_desc', $themename);
$default = theme_trema_get_global_default('loginpagecreatefirst', 0, $editingcompanyid);
$setting = new admin_setting_configcheckbox($name, $title, $description, $default);
$setting->set_updatedcallback('theme_reset_all_caches');
$page->add($setting);

$name = 'theme_trema/loginshowloginform' . $sfx;
$title = get_string('loginshowloginform', $themename);
$description = get_string('loginshowloginform_desc', $themename);
$default = theme_trema_get_global_default('loginshowloginform', 1, $editingcompanyid);
$setting = new admin_setting_configcheckbox($name, $title, $description, $default);
$setting->set_updatedcallback('theme_reset_all_caches');
$page->add($setting);

$settings->add($page);
