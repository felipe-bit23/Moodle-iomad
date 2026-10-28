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
 * Frontpage settings and HTML with IOMAD Multi-Tenant support.
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

// Frontpage settings.
$page = new admin_settingpage('theme_trema_frontpagecontent', get_string('frontpage', $themename));

// IOMAD Multi-Tenant Company Selector.
if (!empty($tenantselector)) {
    $setting = (class_exists('theme_trema_admin_setting_html')
        ? new theme_trema_admin_setting_html('theme_trema_tenant_selector_frontpage' . $sfx, '', $tenantselector)
        : new admin_setting_heading('theme_trema_tenant_selector_frontpage' . $sfx, '', $tenantselector));
    $page->add($setting);
}

// Frontpage image.
$name = 'theme_trema_frontpageimages' . $sfx;
$title = get_string('frontpageimages', $themename);
$description = '';
$format = FORMAT_MARKDOWN;
$setting = new admin_setting_heading($name, $title, $description, $format);
$page->add($setting);

$name = 'theme_trema/numberofimages' . $sfx;
$title = get_string('numberofimages', $themename);
$description = get_string('numberofimages_desc', $themename);
// Place '1' (Single banner) FIRST so browsers never coerce an uninitialized select to '0' (disabled).
$choices = [
    1 => '1',
    2 => '2',
    3 => '3',
    4 => '4',
    5 => '5',
];
$default = (int)theme_trema_get_global_default('numberofimages', 1, $editingcompanyid);
$setting = new admin_setting_configselect($name, $title, $description, $default, $choices);
$setting->set_updatedcallback('theme_reset_all_caches');
$page->add($setting);

// Enable/disable dark banner overlay.
$name = 'theme_trema/frontpageenabledarkoverlay' . $sfx;
$title = get_string('frontpageenabledarkoverlay', $themename);
$description = get_string('frontpageenabledarkoverlay_desc', $themename);
$default = theme_trema_get_global_default('frontpageenabledarkoverlay', 1, $editingcompanyid);
$setting = new admin_setting_configcheckbox($name, $title, $description, $default);
$setting->set_updatedcallback('theme_reset_all_caches');
$page->add($setting);

// Enable frontpage drawer by default.
$name = 'theme_trema/frontpagedraweropen' . $sfx;
$title = get_string('frontpagedraweropen', $themename);
$description = get_string('frontpagedraweropen_desc', $themename);
$default = theme_trema_get_global_default('frontpagedraweropen', 0, $editingcompanyid);
$setting = new admin_setting_configcheckbox($name, $title, $description, $default);
$setting->set_updatedcallback('theme_reset_all_caches');
$page->add($setting);

// Frontpage banner text alignment (place 'center' first).
$choices = [
    'center' => get_string('middle', 'editor'),
    'left' => get_string('left', 'editor'),
    'right' => get_string('right', 'editor'),
];
$name = 'theme_trema/frontpagebannercontentalign' . $sfx;
$title = get_string('frontpagebannercontentalign', $themename);
$description = get_string('frontpagebannercontentalign_desc', $themename);
$default = theme_trema_get_global_default('frontpagebannercontentalign', 'center', $editingcompanyid);
$setting = new admin_setting_configselect($name, $title, $description, $default, $choices);
$setting->set_updatedcallback('theme_reset_all_caches');
$page->add($setting);

// Frontpage banner height (place '100vh' first).
$choices = ['100vh' => '100%', '90vh' => '90%', '80vh' => '80%', '70vh' => '70%', '60vh' => '60%', '50vh' => '50%'];
$name = 'theme_trema/bannerheight' . $sfx;
$title = get_string('bannerheight', $themename);
$description = get_string('bannerheight_desc', $themename);
$default = theme_trema_get_global_default('bannerheight', '100vh', $editingcompanyid);
$setting = new admin_setting_configselect($name, $title, $description, $default, $choices);
$setting->set_updatedcallback('theme_reset_all_caches');
$page->add($setting);

// Frontpage button class.
$btnchoices = [
    'btn-primary' => 'btn-primary',
    'btn-secondary' => 'btn-secondary',
    'btn-success' => 'btn-success',
    'btn-danger' => 'btn-danger',
    'btn-warning' => 'btn-warning',
    'btn-info' => 'btn-info',
    'btn-light' => 'btn-light',
    'btn-dark' => 'btn-dark',
];

$numberofcarousel = theme_trema_get_setting('numberofimages', $editingcompanyid, false);

$frontpagetitledefault = theme_trema_get_global_default('frontpagetitle', get_string('frontpagetitle_default', $themename), $editingcompanyid);
$frontpagesubtitledefault = theme_trema_get_global_default('frontpagesubtitle', get_string('frontpagesubtitle_default', $themename), $editingcompanyid);
$frontpagebuttontextdefault = theme_trema_get_global_default('frontpagebuttontext', get_string('frontpagebuttontext_default', $themename), $editingcompanyid);
$cardtitledefault = get_string('cardtitle', $themename);
$cardsubtitledefault = get_string('cardsubtitle', $themename);

// Set some settings so that they can initially have a default value but later be set blank.
if ($numberofcarousel === false) {
    $numberofcarousel = 1;
    set_config('frontpagetitledefault', $frontpagetitledefault, $themename);
    set_config('frontpagesubtitledefault', $frontpagesubtitledefault, $themename);
    set_config('frontpagebuttontextdefault', $frontpagebuttontextdefault, $themename);
    set_config('cardtitledefault', $cardtitledefault, $themename);
}

// Always render the single-banner fields when $numberofcarousel <= 1 so they are never hidden from the admin panel!
if ((int)$numberofcarousel <= 1) {
    // Frontpage single banner.
    $name = 'theme_trema/frontpagebanner' . $sfx;
    $title = get_string('frontpagebanner', $themename);
    $description = get_string('frontpagebanner_desc', $themename);
    $setting = new admin_setting_configstoredfile($name, $title, $description, 'frontpagebanner' . $sfx);
    $setting->set_updatedcallback('theme_reset_all_caches');
    $page->add($setting);

    // Frontpage title.
    $name = 'theme_trema/frontpagetitle' . $sfx;
    $title = get_string('frontpagetitle', $themename);
    $description = '';
    $default = $frontpagetitledefault;
    $setting = new admin_setting_configtext($name, $title, $description, $default);
    $setting->set_updatedcallback('theme_reset_all_caches');
    $page->add($setting);

    // Frontpage subtitle.
    $name = 'theme_trema/frontpagesubtitle' . $sfx;
    $title = get_string('frontpagesubtitle', $themename);
    $description = '';
    $default = $frontpagesubtitledefault;
    $setting = new admin_setting_configtext($name, $title, $description, $default);
    $setting->set_updatedcallback('theme_reset_all_caches');
    $page->add($setting);

    // Frontpage button text.
    $name = 'theme_trema/frontpagebuttontext' . $sfx;
    $title = get_string('frontpagebuttontext', $themename);
    $description = '';
    $default = $frontpagebuttontextdefault;
    $setting = new admin_setting_configtext($name, $title, $description, $default);
    $setting->set_updatedcallback('theme_reset_all_caches');
    $page->add($setting);

    // Frontpage button link.
    $name = 'theme_trema/frontpagebuttonhref' . $sfx;
    $title = get_string('frontpagebuttonhref', $themename);
    $description = get_string('frontpagebuttonhref_desc', $themename);
    $default = theme_trema_get_global_default('frontpagebuttonhref', '#topofcontent', $editingcompanyid);
    $setting = new admin_setting_configtext($name, $title, $description, $default);
    $setting->set_updatedcallback('theme_reset_all_caches');
    $page->add($setting);

    // Frontpage button class.
    $name = 'theme_trema/frontpagebuttonclass' . $sfx;
    $title = get_string('frontpagebuttonclass', $themename);
    $description = get_string('frontpagebuttonclass_desc', $themename);
    $default = theme_trema_get_global_default('frontpagebuttonclass', 'btn-primary', $editingcompanyid);
    $choices = $btnchoices;
    $setting = new admin_setting_configselect($name, $title, $description, $default, $choices);
    $setting->set_updatedcallback('theme_reset_all_caches');
    $page->add($setting);
} else if ((int)$numberofcarousel >= 2) {
    for ($i = 1; $i <= $numberofcarousel; $i++) {
        $name = 'theme_trema_frontpageimage' . $i . $sfx;
        $title = get_string('frontpageimage', $themename, $i);
        $description = '';
        $format = FORMAT_MARKDOWN;
        $setting = new admin_setting_heading($name, $title, $description, $format);
        $page->add($setting);

        // Carousel image.
        $name = 'theme_trema/frontpageimage' . $i . $sfx;
        $title = get_string('image', $themename, $i);
        $description = get_string('frontpageimage_desc', $themename, $i);
        $setting = new admin_setting_configstoredfile($name, $title, $description, "frontpageimage{$i}" . $sfx);
        $setting->set_updatedcallback('theme_reset_all_caches');
        $page->add($setting);

        // Carousel title.
        $name = 'theme_trema/carrouseltitle' . $i . $sfx;
        $title = get_string('title', $themename) . " $i";
        $description = get_string('title_desc', $themename, $i);
        $default = theme_trema_get_global_default('carrouseltitle' . $i, get_string('frontpagetitle_default', $themename), $editingcompanyid);
        $setting = new admin_setting_configtext($name, $title, $description, $default);
        $setting->set_updatedcallback('theme_reset_all_caches');
        $page->add($setting);

        // Carousel description.
        $name = 'theme_trema/carrouselsubtitle' . $i . $sfx;
        $title = get_string('subtitle', $themename) . " $i";
        $description = get_string('subtitle_desc', $themename, $i);
        $default = theme_trema_get_global_default('carrouselsubtitle' . $i, get_string('frontpagesubtitle_default', $themename), $editingcompanyid);
        $setting = new admin_setting_configtext($name, $title, $description, $default);
        $setting->set_updatedcallback('theme_reset_all_caches');
        $page->add($setting);

        // Carousel button text.
        $name = 'theme_trema/carrouselbtntext' . $i . $sfx;
        $title = get_string('carrouselbtntext', $themename, $i);
        $description = get_string('carrouselbtntext_desc', $themename, $i);
        $default = theme_trema_get_global_default('carrouselbtntext' . $i, get_string('frontpagebuttontext_default', $themename), $editingcompanyid);
        $setting = new admin_setting_configtext($name, $title, $description, $default);
        $setting->set_updatedcallback('theme_reset_all_caches');
        $page->add($setting);

        // Carousel button link.
        $name = 'theme_trema/carrouselbtnhref' . $i . $sfx;
        $title = get_string('carrouselbtnhref', $themename, $i);
        $description = get_string('carrouselbtnhref_desc', $themename, $i);
        $default = '#topofcontent';
        $setting = new admin_setting_configtext($name, $title, $description, $default);
        $setting->set_updatedcallback('theme_reset_all_caches');
        $page->add($setting);

        // Carousel button class.
        $name = 'theme_trema/carrouselbtnclass' . $i . $sfx;
        $title = get_string('carrouselbtnclass', $themename, $i);
        $description = get_string('carrouselbtnclass_desc', $themename, $i);
        $default = 'btn-primary';
        $setting = new admin_setting_configtext($name, $title, $description, $default);
        $setting->set_updatedcallback('theme_reset_all_caches');
        $page->add($setting);
    }
}

// Frontpage content.
$name = 'theme_trema_frontpagecontent' . $sfx;
$title = get_string('content');
$description = '';
$format = FORMAT_MARKDOWN;
$setting = new admin_setting_heading($name, $title, $description, $format);
$page->add($setting);

// HTML to include in the main content of frontpage.
$name = 'theme_trema/defaultfrontpagebody' . $sfx;
$title = get_string('defaultfrontpagebody', $themename);
$description = get_string('defaultfrontpagebody_desc', $themename);
$default = theme_trema_get_global_default('defaultfrontpagebody', '', $editingcompanyid);
$format = PARAM_RAW;
$setting = new admin_setting_confightmleditor($name, $title, $description, $default, $format);
$setting->set_updatedcallback('theme_reset_all_caches');
$page->add($setting);

// Show/Hide links to Pages activities on Frontpage.
$name = 'theme_trema/showfrontpagelinkstopages' . $sfx;
$title = get_string('showfrontpagelinkstopages', $themename);
$description = get_string('showfrontpagelinkstopages_desc', $themename);
$default = theme_trema_get_global_default('showfrontpagelinkstopages', 1, $editingcompanyid);
$setting = new admin_setting_configcheckbox($name, $title, $description, $default);
$setting->set_updatedcallback('theme_reset_all_caches');
$page->add($setting);

// Frontpage cards.
$name = 'theme_trema_cards' . $sfx;
$title = get_string('frontpagecards', $themename);
$description = '';
$format = FORMAT_MARKDOWN;
$setting = new admin_setting_heading($name, $title, $description, $format);
$setting->set_updatedcallback('theme_reset_all_caches');
$page->add($setting);

// Enable/disable frontpage cards.
$name = 'theme_trema/frontpageenablecards' . $sfx;
$title = get_string('frontpageenablecards', $themename);
$description = get_string('frontpageenablecards_desc', $themename, "$CFG->wwwroot/theme/trema/pix/examples/cards.png");
$default = theme_trema_get_global_default('frontpageenablecards', 1, $editingcompanyid);
$setting = new admin_setting_configcheckbox($name, $title, $description, $default);
$setting->set_updatedcallback('theme_reset_all_caches');
$page->add($setting);

// Always register card settings in the admin form so they never disappear when saving or toggling.
if (true) {
    // Title.
    $name = 'theme_trema/frontpagecardstitle' . $sfx;
    $title = get_string('title', $themename);
    $description = '';
    $default = theme_trema_get_global_default('frontpagecardstitle', get_string('cardtitle', $themename), $editingcompanyid);
    $setting = new admin_setting_configtext($name, $title, $description, $default);
    $setting->set_updatedcallback('theme_reset_all_caches');
    $page->add($setting);

    // Subtitle.
    $name = 'theme_trema/frontpagecardssubtitle' . $sfx;
    $title = get_string('subtitle', $themename);
    $description = '';
    $default = theme_trema_get_global_default('frontpagecardssubtitle', get_string('cardsubtitle', $themename), $editingcompanyid);
    $setting = new admin_setting_configtext($name, $title, $description, $default);
    $setting->set_updatedcallback('theme_reset_all_caches');
    $page->add($setting);

    // Number of cards (place 4 first).
    $name = 'theme_trema/numberofcards' . $sfx;
    $title = get_string('numberofcards', $themename);
    $description = get_string('numberofcards_desc', $themename);
    $default = (int)theme_trema_get_global_default('numberofcards', 4, $editingcompanyid);
    $choices = [
        4 => '4',
        2 => '2',
        6 => '6',
    ];
    $setting = new admin_setting_configselect($name, $title, $description, $default, $choices);
    $setting->set_updatedcallback('theme_reset_all_caches');
    $page->add($setting);

    $numberofcards = theme_trema_get_setting('numberofcards', $editingcompanyid, 4) ?: 4;
    for ($i = 1; $i <= $numberofcards; $i++) {
        // Card header.
        $name = 'theme_trema_card' . $i . $sfx;
        $title = get_string('card', $themename) . ' ' . $i;
        $description = '';
        $format = FORMAT_MARKDOWN;
        $setting = new admin_setting_heading($name, $title, $description, $format);
        $page->add($setting);

        // Card icon.
        $name = 'theme_trema/cardicon' . $i . $sfx;
        $title = get_string('cardicon', $themename) . ' ' . $i;
        $description = get_string('cardicon_desc', $themename);
        $default = theme_trema_get_global_default('cardicon' . $i, 'fa-paper-plane', $editingcompanyid);
        $setting = new admin_setting_configtext($name, $title, $description, $default);
        $setting->set_updatedcallback('theme_reset_all_caches');
        $page->add($setting);

        // Card icon color.
        $name = 'theme_trema/cardiconcolor' . $i . $sfx;
        $title = get_string('cardiconcolor', $themename) . ' ' . $i;
        $description = '';
        $default = theme_trema_get_global_default('cardiconcolor' . $i, '#000000', $editingcompanyid);
        $setting = new admin_setting_configcolourpicker($name, $title, $description, $default);
        $setting->set_updatedcallback('theme_reset_all_caches');
        $page->add($setting);

        // Card title.
        $name = 'theme_trema/cardtitle' . $i . $sfx;
        $title = $cardtitledefault . ' ' . $i;
        $description = '';
        $default = theme_trema_get_global_default('cardtitle' . $i, get_string('cardtitle_default', $themename) . ' ' . $i, $editingcompanyid);
        $setting = new admin_setting_configtext($name, $title, $description, $default);
        $setting->set_updatedcallback('theme_reset_all_caches');
        $page->add($setting);

        // Card description.
        $name = 'theme_trema/cardsubtitle' . $i . $sfx;
        $title = $cardsubtitledefault . ' ' . $i;
        $description = '';
        $default = theme_trema_get_global_default('cardsubtitle' . $i, get_string('cardsubtitle_default', $themename), $editingcompanyid);
        $setting = new admin_setting_configtext($name, $title, $description, $default);
        $setting->set_updatedcallback('theme_reset_all_caches');
        $page->add($setting);

        // Card link.
        $name = 'theme_trema/cardlink' . $i . $sfx;
        $title = get_string('cardlink', $themename) . ' ' . $i;
        $description = '';
        $default = theme_trema_get_global_default('cardlink' . $i, '', $editingcompanyid);
        $setting = new admin_setting_configtext($name, $title, $description, $default);
        $setting->set_updatedcallback('theme_reset_all_caches');
        $page->add($setting);
    }
}

$settings->add($page);
