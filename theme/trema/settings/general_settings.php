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
 * General settings with IOMAD Multi-Tenant support.
 *
 * @package     theme_trema
 * @copyright   2019-2026 Trema - {@link https://trema.tech/}
 * @copyright   2023-2026 TNG Consulting Inc. - {@link https://www.tngconsulting.ca/}
 * @author      Rodrigo Mady
 * @author      Trevor Furtado
 * @author      Michael Milette
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$page = new admin_settingpage('theme_trema_general', get_string('general'));

// IOMAD Multi-Tenant Company Selector.
if (!empty($tenantselector)) {
    $setting = (class_exists('theme_trema_admin_setting_html')
        ? new theme_trema_admin_setting_html('theme_trema_tenant_selector_general' . $sfx, '', $tenantselector)
        : new admin_setting_heading('theme_trema_tenant_selector_general' . $sfx, '', $tenantselector));
    $page->add($setting);
}

// Custom site/company title override.
$name = 'theme_trema/tenantsitename' . $sfx;
$title = get_string('tenantsitename', $themename);
$description = get_string('tenantsitename_desc', $themename);
$setting = new admin_setting_configtext($name, $title, $description, '', PARAM_TEXT);
$setting->set_updatedcallback('theme_reset_all_caches');
$page->add($setting);

// Full Logo per company/global.
$name = 'theme_trema/logo' . $sfx;
$title = get_string('logo', $themename);
$description = get_string('logo_desc', $themename);
$setting = new admin_setting_configstoredfile($name, $title, $description, 'logo' . $sfx);
$setting->set_updatedcallback('theme_reset_all_caches');
$page->add($setting);

// Compact Navbar Logo per company/global.
$name = 'theme_trema/compactlogo' . $sfx;
$title = get_string('compactlogo', $themename);
$description = get_string('compactlogo_desc', $themename);
$setting = new admin_setting_configstoredfile($name, $title, $description, 'compactlogo' . $sfx);
$setting->set_updatedcallback('theme_reset_all_caches');
$page->add($setting);

// Custom menu items per company/global (compatible with IOMAD custommenuitems).
$name = 'theme_trema/custommenuitems' . $sfx;
$title = get_string('custommenuitems', 'admin');
$description = get_string('configcustommenuitems', 'admin');
$setting = new admin_setting_configtextarea($name, $title, $description, '', PARAM_RAW, '50', '10');
$setting->set_updatedcallback('theme_reset_all_caches');
$page->add($setting);

if (!empty($editingcompanyid)) {
    // Isolate central frontpage blocks ('content-cX') per company.
    $name = 'theme_trema/isolatecompanyblocks' . $sfx;
    $title = get_string('isolatecompanyblocks', $themename);
    $description = get_string('isolatecompanyblocks_desc', $themename);
    $setting = new admin_setting_configcheckbox($name, $title, $description, 0);
    $setting->set_updatedcallback('theme_reset_all_caches');
    $page->add($setting);
}

// Unaddable blocks. Exclude these blocks from the "Add a block" list: Administration, Navigation, Courses and Section links.
$name = 'theme_trema/unaddableblocks' . $sfx;
$title = get_string('unaddableblocks', 'theme_boost');
$description = get_string('unaddableblocks_desc', 'theme_boost');
$default = theme_trema_get_global_default('unaddableblocks', 'navigation,settings,course_list,section_links', $editingcompanyid);
$setting = new admin_setting_configtext($name, $title, $description, $default, PARAM_TEXT);
$setting->set_updatedcallback('theme_reset_all_caches');
$page->add($setting);

// Background image setting.
$name = 'theme_trema/backgroundimage' . $sfx;
$title = get_string('backgroundimage', 'theme_boost');
$description = get_string('backgroundimage_desc', 'theme_boost');
$setting = new admin_setting_configstoredfile($name, $title, $description, 'backgroundimage' . $sfx);
$setting->set_updatedcallback('theme_reset_all_caches');
$page->add($setting);

// Links.
$name = 'theme_trema/linkdecoration' . $sfx;
$title = get_string('linkdecoration', $themename);
$description = get_string('linkdecoration_desc', $themename);
$default = theme_trema_get_global_default('linkdecoration', 'underline', $editingcompanyid);
$choices = ['underline' => get_string('underline', 'editor'), "none" => get_string('none')];
$setting = new admin_setting_configselect($name, $title, $description, $default, $choices);
$setting->set_updatedcallback('theme_reset_all_caches');
$page->add($setting);

// Process primary navigation (custom menu) through Moodle filters.
$name = 'theme_trema/navfilter' . $sfx;
$title = get_string('navfilter', $themename);
$description = get_string('navfilter_desc', $themename);
$default = theme_trema_get_global_default('navfilter', 1, $editingcompanyid);
$setting = new admin_setting_configcheckbox($name, $title, $description, $default);
$setting->set_updatedcallback('theme_reset_all_caches');
$page->add($setting);

// Hide selected items in the primary navigation (custom menu).
$hideitemsoptions = [];
if ($CFG->branch >= 502) {
    if (!empty($CFG->enablemyhome)) {
        $hideitemsoptions['home'] = get_string('home');
    }
    if (!empty($CFG->enabledashboard)) {
        $hideitemsoptions['myhome'] = get_string('myhome');
    }
    if (!empty($CFG->enablemycourses)) {
        $hideitemsoptions['courses'] = get_string('mycourses');
    }
} else {
    $hideitemsoptions['home'] = get_string('home');
    if (!empty($CFG->enabledashboard)) {
        $hideitemsoptions['myhome'] = get_string('myhome');
    }
    $hideitemsoptions['courses'] = get_string('mycourses');
}
$hideitemsoptions['siteadminnode'] = get_string('administrationsite');
$name = 'theme_trema/hideprimarynavigationitems' . $sfx;
$title = get_string('hideprimarynavigationitems', $themename, null, true);
$description = get_string('hideprimarynavigationitems_desc', $themename, null, true);
$setting = new admin_setting_configmulticheckbox($name, $title, $description, [], $hideitemsoptions);
$setting->set_updatedcallback('theme_reset_all_caches');
$page->add($setting);

// Primary navigation (custom menu) alignment.
$name = 'theme_trema/custommenualignment' . $sfx;
$title = get_string('custommenualignment', $themename);
$description = get_string('custommenualignment_desc', $themename);
$default = theme_trema_get_global_default('custommenualignment', 'left', $editingcompanyid);
$choices = [
    'left' => get_string('left', 'editor'),
    'center' => get_string('middle', 'editor'),
    'right' => get_string('right', 'editor'),
];
$setting = new admin_setting_configselect($name, $title, $description, $default, $choices);
$setting->set_updatedcallback('theme_reset_all_caches');
$page->add($setting);

// Hide the User menu / Logout link.
$name = 'theme_trema/showumlogoutlink' . $sfx;
$title = get_string('showumlogoutlink', $themename);
$description = get_string('showumlogoutlink_desc', $themename);
$default = theme_trema_get_global_default('showumlogoutlink', 1, $editingcompanyid);
$setting = new admin_setting_configcheckbox($name, $title, $description, $default);
$setting->set_updatedcallback('theme_reset_all_caches');
$page->add($setting);

// Favicon image setting.
$name = 'theme_trema/favicon' . $sfx;
$title = get_string('favicon', $themename);
$description = get_string('favicon_desc', $themename);
$setting = new admin_setting_configstoredfile($name, $title, $description, 'favicon' . $sfx);
$setting->set_updatedcallback('theme_reset_all_caches');
$page->add($setting);

// Enable Admin Dashboard.
$name = 'theme_trema/enableadmindashboard' . $sfx;
$title = get_string('enableadmindashboard', $themename);
$description = get_string('enableadmindashboard_desc', $themename, "$CFG->wwwroot/theme/trema/pix/examples/admindashboard.png");
$default = theme_trema_get_global_default('enableadmindashboard', 1, $editingcompanyid);
$setting = new admin_setting_configcheckbox($name, $title, $description, $default);
$setting->set_updatedcallback('theme_reset_all_caches');
$page->add($setting);

// Dashboard Box Settings (only shown if admin dashboard is enabled).
$boxoptions = ['none' => get_string('none')];

$boxdir = $CFG->dirroot . '/theme/trema/classes/dashboard/boxes';
if (is_dir($boxdir)) {
    $files = scandir($boxdir);
    foreach ($files as $file) {
        if (substr($file, 0, 5) === 'dash-' && substr($file, -4) === '.php') {
            require_once($boxdir . '/' . $file);
            $boxid = str_replace(['dash-', '.php'], '', $file);
            $classname = 'theme_trema\\dashboard\\boxes\\dash_' . $boxid;
            if (class_exists($classname)) {
                try {
                    $box = new $classname();
                    $boxoptions[$box->get_id()] = $box->get_name();
                } catch (Exception $e) {
                    unset($e);
                }
            }
        }
    }
}

// Dashboard Box 1.
$name = 'theme_trema/dashboardbox1' . $sfx;
$title = get_string('dashboardbox1', $themename);
$description = get_string('dashboardbox1_desc', $themename);
$default = theme_trema_get_global_default('dashboardbox1', 'diskusage', $editingcompanyid);
$setting = new admin_setting_configselect($name, $title, $description, $default, $boxoptions);
$setting->set_updatedcallback('theme_reset_all_caches');
$page->add($setting);

// Dashboard Box 2.
$name = 'theme_trema/dashboardbox2' . $sfx;
$title = get_string('dashboardbox2', $themename);
$description = get_string('dashboardbox2_desc', $themename);
$default = theme_trema_get_global_default('dashboardbox2', 'courses', $editingcompanyid);
$setting = new admin_setting_configselect($name, $title, $description, $default, $boxoptions);
$setting->set_updatedcallback('theme_reset_all_caches');
$page->add($setting);

// Dashboard Box 3.
$name = 'theme_trema/dashboardbox3' . $sfx;
$title = get_string('dashboardbox3', $themename);
$description = get_string('dashboardbox3_desc', $themename);
$default = theme_trema_get_global_default('dashboardbox3', 'enrolments', $editingcompanyid);
$setting = new admin_setting_configselect($name, $title, $description, $default, $boxoptions);
$setting->set_updatedcallback('theme_reset_all_caches');
$page->add($setting);

// Dashboard Box 4.
$name = 'theme_trema/dashboardbox4' . $sfx;
$title = get_string('dashboardbox4', $themename);
$description = get_string('dashboardbox4_desc', $themename);
$default = theme_trema_get_global_default('dashboardbox4', 'security', $editingcompanyid);
$setting = new admin_setting_configselect($name, $title, $description, $default, $boxoptions);
$setting->set_updatedcallback('theme_reset_all_caches');
$page->add($setting);

// Enable Trema Lines.
$name = 'theme_trema/enabletremalines' . $sfx;
$title = get_string('enabletremalines', $themename);
$description = get_string('enabletremalines_desc', $themename, "$CFG->wwwroot/theme/trema/pix/examples/tremalines.png");
$default = theme_trema_get_global_default('enabletremalines', 1, $editingcompanyid);
$setting = new admin_setting_configcheckbox($name, $title, $description, $default);
$setting->set_updatedcallback('theme_reset_all_caches');
$page->add($setting);

// Enable a softer look by rounding some corners.
$name = 'theme_trema/softness' . $sfx;
$title = get_string('softness', $themename);
$description = get_string('softness_desc', $themename);
$default = theme_trema_get_global_default('softness', 1, $editingcompanyid);
$setting = new admin_setting_configcheckbox($name, $title, $description, $default);
$setting->set_updatedcallback('theme_reset_all_caches');
$page->add($setting);

$settings->add($page);

$settings->hide_if('theme_trema/dashboardbox1' . $sfx, 'theme_trema/enableadmindashboard' . $sfx);
$settings->hide_if('theme_trema/dashboardbox2' . $sfx, 'theme_trema/enableadmindashboard' . $sfx);
$settings->hide_if('theme_trema/dashboardbox3' . $sfx, 'theme_trema/enableadmindashboard' . $sfx);
$settings->hide_if('theme_trema/dashboardbox4' . $sfx, 'theme_trema/enableadmindashboard' . $sfx);
