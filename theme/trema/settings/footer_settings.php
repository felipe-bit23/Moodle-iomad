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
 * Footer settings with IOMAD Multi-Tenant support.
 *
 * @package     theme_trema
 * @copyright   2019 Trema - {@link https://trema.tech/}
 * @author      Rodrigo Mady
 * @author      Trevor Furtado
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$page = new admin_settingpage('theme_trema_footer', get_string('defaultfooter', $themename));

// IOMAD Multi-Tenant Company Selector.
if (!empty($tenantselector)) {
    $setting = (class_exists('theme_trema_admin_setting_html')
        ? new theme_trema_admin_setting_html('theme_trema_tenant_selector_footer' . $sfx, '', $tenantselector)
        : new admin_setting_heading('theme_trema_tenant_selector_footer' . $sfx, '', $tenantselector));
    $page->add($setting);
}

// Toggle between Moodle 4.x footer and a configurable traditional footer.
$name = 'theme_trema/enabletremafooter' . $sfx;
$title = get_string('enabletremafooter', $themename);
$description = get_string('enabletremafooter_desc', $themename, "$CFG->wwwroot/theme/trema/pix/examples/footerinfo.png");
$default = theme_trema_get_global_default('enabletremafooter', 1, $editingcompanyid);
$setting = new admin_setting_configcheckbox($name, $title, $description, $default);
$setting->set_updatedcallback('theme_reset_all_caches');
$page->add($setting);

// Select footer opacity.
$options = [];
for ($i = 100; $i >= 0; $i -= 10) {
    $options['' . ($i / 100)] = $i;
}
$name = 'theme_trema/footeropacity' . $sfx;
$title = get_string('footeropacity', $themename);
$description = get_string('footeropacity_desc', $themename);
$default = theme_trema_get_global_default('footeropacity', '1', $editingcompanyid);
$setting = new admin_setting_configselect($name, $title, $description, $default, $options);
$setting->set_updatedcallback('theme_reset_all_caches');
$page->add($setting);

// HTML to include in the footer content.
$name = 'theme_trema/defaultfooter' . $sfx;
$title = get_string('defaultfooter', $themename);
$description = get_string('defaultfooter_desc', $themename);
$default = theme_trema_get_global_default('defaultfooter', get_string('defaultfooter_default', $themename), $editingcompanyid);
$setting = new admin_setting_confightmleditor($name, $title, $description, $default, PARAM_RAW);
$setting->set_updatedcallback('theme_reset_all_caches');
$page->add($setting);

// Show/hide footer information.
$name = 'theme_trema/enablefooterinfo' . $sfx;
$title = get_string('enablefooterinfo', $themename);
$description = get_string('enablefooterinfo_desc', $themename, "$CFG->wwwroot/theme/trema/pix/examples/footerinfo.png");
$default = theme_trema_get_global_default('enablefooterinfo', 1, $editingcompanyid);
$setting = new admin_setting_configcheckbox($name, $title, $description, $default);
$setting->set_updatedcallback('theme_reset_all_caches');
$page->add($setting);

// Show/Hide Moodle branding.
$name = 'theme_trema/showbranding' . $sfx;
$title = get_string('showbranding', $themename);
$description = get_string('showbranding_desc', $themename);
$default = theme_trema_get_global_default('showbranding', 1, $editingcompanyid);
$setting = new admin_setting_configcheckbox($name, $title, $description, $default);
$setting->set_updatedcallback('theme_reset_all_caches');
$page->add($setting);

$settings->add($page);
