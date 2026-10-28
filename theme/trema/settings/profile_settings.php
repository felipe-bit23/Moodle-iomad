<?php
// This file is part of the Trema theme for Moodle - https://moodle.org/
//
// Trema is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Trema is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Settings for Profile fields with IOMAD Multi-Tenant support.
 *
 * @package     theme_trema
 * @copyright   2016-2026 TNG Consulting Inc. <https://www.tngconsulting.ca>
 * @author      Michael Milette
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

// User Profile Fields tab.
$page = new admin_settingpage('theme_trema_profile', get_string('profile'));

// IOMAD Multi-Tenant Company Selector.
if (!empty($tenantselector)) {
    $setting = (class_exists('theme_trema_admin_setting_html')
        ? new theme_trema_admin_setting_html('theme_trema_tenant_selector_profile' . $sfx, '', $tenantselector)
        : new admin_setting_heading('theme_trema_tenant_selector_profile' . $sfx, '', $tenantselector));
    $page->add($setting);
}

// Enforce required and locked profile fields during user creation.
$name = 'theme_trema/enforcerequiredprofilefields' . $sfx;
$title = get_string('enforcerequiredprofilefields', 'theme_trema');
$description = get_string('enforcerequiredprofilefields_desc', 'theme_trema');
$default = theme_trema_get_global_default('enforcerequiredprofilefields', 0, $editingcompanyid);
$setting = new admin_setting_configcheckbox($name, $title, $description, $default);
$page->add($setting);

$profilefieldsections = ['pictureofuser', 'additionalnames', 'interests', 'optional'];

$profilefields = [];
$profilefields[] = 'emaildisplay';
if (!empty($CFG->branch) && $CFG->branch < 502) {
    $profilefields[] = 'moodlenetprofile';
}
$profilefields[] = 'city';
$profilefields[] = 'country';
$profilefields[] = 'timezone';
$profilefields[] = 'description';
$profilefields[] = 'pictureofuser';
$profilefields[] = 'additionalnames';
$profilefields[] = 'firstnamephonetic';
$profilefields[] = 'lastnamephonetic';
$profilefields[] = 'middlename';
$profilefields[] = 'alternatename';
$profilefields[] = 'interests';
$profilefields[] = 'optional';
$profilefields[] = 'idnumber';
$profilefields[] = 'institution';
$profilefields[] = 'department';
$profilefields[] = 'phone1';
$profilefields[] = 'phone2';
$profilefields[] = 'address';

$name = 'theme_trema/showprofileeheading' . $sfx;
$title = '';
$description = get_string('showprofile_heading', $themename);
$setting = new admin_setting_heading($name, $title, $description);
$setting->set_updatedcallback('theme_reset_all_caches');
$page->add($setting);

$name = 'theme_trema/showprofileeheading_general' . $sfx;
$title = get_string('section') . ' : ' . get_string('general');
$description = '';
$setting = new admin_setting_heading($name, $title, $description);
$setting->set_updatedcallback('theme_reset_all_caches');
$page->add($setting);

$default = 1;
foreach ($profilefields as $field) {
    if (in_array($field, $profilefieldsections)) {
        $name = 'theme_trema/showprofileeheading_' . $field . $sfx;
        $description = '';
        $title = get_string('section') . ' : ' . get_string($field);
        $setting = new admin_setting_heading($name, $title, $description);
        $setting->set_updatedcallback('theme_reset_all_caches');
        $page->add($setting);
        $description = get_string('showprofilesection_desc', $themename);
    } else {
        $description = '';
    }
    $name = 'theme_trema/showprofile' . $field . $sfx;
    $title = get_string($field, ($field != 'moodlenetprofile' ? 'moodle' : 'user'));
    $fdefault = theme_trema_get_global_default('showprofile' . $field, 1, $editingcompanyid);
    $setting = new admin_setting_configcheckbox($name, $title, $description, $fdefault);
    $setting->set_updatedcallback('theme_reset_all_caches');
    $page->add($setting);
}

$settings->add($page);
