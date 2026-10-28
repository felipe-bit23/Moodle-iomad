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
 * Fonts and Colors settings with IOMAD Multi-Tenant support.
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

$page = new admin_settingpage('theme_trema_fontscolors', get_string('fontscolorssettings', $themename));

// IOMAD Multi-Tenant Company Selector.
if (!empty($tenantselector)) {
    $setting = (class_exists('theme_trema_admin_setting_html')
        ? new theme_trema_admin_setting_html('theme_trema_tenant_selector_fontscolors' . $sfx, '', $tenantselector)
        : new admin_setting_heading('theme_trema_tenant_selector_fontscolors' . $sfx, '', $tenantselector));
    $page->add($setting);
}

// Heading for Colors section.
$name = 'theme_trema_colors' . $sfx;
$title = get_string('colors', $themename);
$description = '';
$format = FORMAT_MARKDOWN;
$setting = new admin_setting_heading($name, $title, $description, $format);
$page->add($setting);

$name = 'theme_trema/primarycolor' . $sfx;
$title = get_string('primarycolor', $themename);
$description = get_string('primarycolor_desc', $themename);
$default = theme_trema_get_global_default('primarycolor', '#1c6ca3', $editingcompanyid);
$setting = new admin_setting_configcolourpicker($name, $title, $description, $default);
$setting->set_updatedcallback('theme_reset_all_caches');
$page->add($setting);

$name = 'theme_trema/secondarycolor' . $sfx;
$title = get_string('secondarycolor', $themename);
$description = get_string('secondarycolor_desc', $themename);
$default = theme_trema_get_global_default('secondarycolor', '#343a40', $editingcompanyid);
$setting = new admin_setting_configcolourpicker($name, $title, $description, $default);
$setting->set_updatedcallback('theme_reset_all_caches');
$page->add($setting);

$name = 'theme_trema/bodybackgroundcolor' . $sfx;
$title = get_string('bodybackgroundcolor', $themename);
$description = get_string('bodybackgroundcolor_desc', $themename);
$default = theme_trema_get_global_default('bodybackgroundcolor', '#f1f1f1', $editingcompanyid);
$setting = new admin_setting_configcolourpicker($name, $title, $description, $default);
$setting->set_updatedcallback('theme_reset_all_caches');
$page->add($setting);

$coloroptions = [
    '$white' => get_string('white', $themename),
    '$black' => get_string('black', $themename),
    '$gray-200' => get_string('light', $themename),
    '$gray-800' => get_string('dark', $themename),
    'primarycolor' => get_string('sameprimarycolor', $themename),
    'secondarycolor' => get_string('samesecondarycolor', $themename),
    'bodybackgroundcolor' => get_string('samebasecolor', $themename),
];

// Background for header.
$name = 'theme_trema/headerbgcolor' . $sfx;
$title = get_string('headerbgcolor', $themename);
$description = get_string('headerbgcolor_desc', $themename);
$default = theme_trema_get_global_default('headerbgcolor', '$white', $editingcompanyid);
$setting = new admin_setting_configselect($name, $title, $description, $default, $coloroptions);
$setting->set_updatedcallback('theme_reset_all_caches');
$page->add($setting);

// Background for Log In button in header.
$name = 'theme_trema/loginbtnbgcolor' . $sfx;
$title = get_string('loginbtnbgcolor', $themename);
$description = get_string('loginbtnbgcolor_desc', $themename);
$default = theme_trema_get_global_default('loginbtnbgcolor', 'primarycolor', $editingcompanyid);
$setting = new admin_setting_configselect($name, $title, $description, $default, $coloroptions);
$setting->set_updatedcallback('theme_reset_all_caches');
$page->add($setting);

// Background for drawers.
$name = 'theme_trema/drawerbgcolor' . $sfx;
$title = get_string('drawerbgcolor', $themename);
$description = get_string('drawerbgcolor_desc', $themename);
$default = theme_trema_get_global_default('drawerbgcolor', '$gray-200', $editingcompanyid);
$setting = new admin_setting_configselect($name, $title, $description, $default, $coloroptions);
$setting->set_updatedcallback('theme_reset_all_caches');
$page->add($setting);

// Background for footer.
$name = 'theme_trema/footerbgcolor' . $sfx;
$title = get_string('footerbgcolor', $themename);
$description = get_string('footerbgcolor_desc', $themename);
$default = theme_trema_get_global_default('footerbgcolor', '$gray-800', $editingcompanyid);
$setting = new admin_setting_configselect($name, $title, $description, $default, $coloroptions);
$setting->set_updatedcallback('theme_reset_all_caches');
$page->add($setting);

// Heading for Fonts section.
$name = 'theme_trema_fonts' . $sfx;
$title = get_string('fonts', $themename);
$description = '';
$format = FORMAT_MARKDOWN;
$setting = new admin_setting_heading($name, $title, $description, $format);
$page->add($setting);

// Place 'Lato, sans-serif' FIRST so browsers never fall back to 'Alegreya, serif' on uninitialized selects.
$fonts = [
    'Lato, sans-serif' => 'Lato',
    'Montserrat, sans-serif' => 'Montserrat',
    '"OpenSans", sans-serif' => 'OpenSans',
    'Poppins, sans-serif' => 'Poppins',
    'Roboto, Arial, Helvetica, sans-serif' => 'Roboto',
    '"NotoSans", sans-serif' => 'NotoSans',
    'Arial, Helvetica, sans-serif' => 'Arial',
    'Verdana, Tahoma, sans-serif' => 'Verdana',
    'Alegreya, serif' => 'Alegreya',
    '"CrimsonText", serif' => 'CrimsonText',
    '"EBGaramond", sans-serif' => 'EBGaramond',
    'Georgia, serif' => 'Georgia',
    '"PlayfairDisplay", serif' => 'PlayfairDisplay',
    '"Times New Roman", Times, serif' => 'TimesNewRoman',
    '"Courier New", Courier, monospace' => 'CourierNew',
];

// Site Font settings.
$name = 'theme_trema/sitefont' . $sfx;
$title = get_string('sitefont', $themename);
$description = get_string('sitefont_desc', $themename);
$default = theme_trema_get_global_default('sitefont', 'Lato, sans-serif', $editingcompanyid);
$setting = new admin_setting_configselect($name, $title, $description, $default, $fonts);
$setting->set_updatedcallback('theme_reset_all_caches');
$page->add($setting);

// Front page banner titles font settings.
$name = 'theme_trema/bannertitlesfont' . $sfx;
$title = get_string('bannertitlesfont', $themename);
$description = get_string('bannertitlesfont_desc', $themename);
$default = theme_trema_get_global_default('bannertitlesfont', 'Lato, sans-serif', $editingcompanyid);
$setting = new admin_setting_configselect($name, $title, $description, $default, $fonts);
$setting->set_updatedcallback('theme_reset_all_caches');
$page->add($setting);

// H1 Font settings.
$name = 'theme_trema/h1font' . $sfx;
$title = get_string('h1font', $themename);
$description = get_string('h1font_desc', $themename);
$default = theme_trema_get_global_default('h1font', 'Lato, sans-serif', $editingcompanyid);
$setting = new admin_setting_configselect($name, $title, $description, $default, $fonts);
$setting->set_updatedcallback('theme_reset_all_caches');
$page->add($setting);

// Hx Font settings.
$name = 'theme_trema/hxfont' . $sfx;
$title = get_string('hxfont', $themename);
$description = get_string('hxfont_desc', $themename);
$default = theme_trema_get_global_default('hxfont', 'Lato, sans-serif', $editingcompanyid);
$setting = new admin_setting_configselect($name, $title, $description, $default, $fonts);
$setting->set_updatedcallback('theme_reset_all_caches');
$page->add($setting);

// Heading for Appearance section.
$name = 'theme_trema_appearance' . $sfx;
$title = get_string('appearance');
$description = '';
$format = FORMAT_MARKDOWN;
$setting = new admin_setting_heading($name, $title, $description, $format);
$page->add($setting);

$transformoptions = [
    'none' => get_string('texttransform_none', $themename),
    'uppercase' => get_string('texttransform_uppercase', $themename),
    'lowercase' => get_string('texttransform_lowercase', $themename),
    'capitalize' => get_string('texttransform_capitalize', $themename),
];
$default = theme_trema_get_global_default('texttransform', 'none', $editingcompanyid);

$name = 'theme_trema/texttransform' . $sfx;
$title = get_string('texttransform', $themename);
$description = get_string('texttransform_desc', $themename);
$setting = new admin_setting_configselect($name, $title, $description, $default, $transformoptions);
$setting->set_updatedcallback('theme_reset_all_caches');
$page->add($setting);

$default = theme_trema_get_global_default('bannertitletransform', 'none', $editingcompanyid);
$name = 'theme_trema/bannertitletransform' . $sfx;
$title = get_string('bannertitletransform', $themename);
$description = get_string('bannertitletransform_desc', $themename);
$setting = new admin_setting_configselect($name, $title, $description, $default, $transformoptions);
$setting->set_updatedcallback('theme_reset_all_caches');
$page->add($setting);

$options = [
    'normal' => 'Normal',
    '0.5rem' => 'Medium',
    '1rem' => 'Large',
];
$default = theme_trema_get_global_default('bannertitlespacing', 'normal', $editingcompanyid);

$name = 'theme_trema/bannertitlespacing' . $sfx;
$title = get_string('bannertitlespacing', $themename);
$description = get_string('bannertitlespacing_desc', $themename);
$setting = new admin_setting_configselect($name, $title, $description, $default, $options);
$setting->set_updatedcallback('theme_reset_all_caches');
$page->add($setting);

$settings->add($page);
