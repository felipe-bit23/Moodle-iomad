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
 * Frontpage file with IOMAD Multi-Tenant and Central Block Region support.
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

require_once("$CFG->dirroot/theme/trema/locallib.php");
require_once($CFG->libdir . '/behat/lib.php');

// Add block button in editing mode.
$addblockbutton = $OUTPUT->addblockbutton();
$forceblockdraweropen = $OUTPUT->firstview_fakeblocks();
$activecompanyid = theme_trema_get_active_companyid(true);
$pluginsettings = theme_trema_get_plugin_settings($activecompanyid);

if (isloggedin()) {
    $blockdraweropen = (get_user_preferences('drawer-open-block') == true);
} else if (isset($pluginsettings->frontpagedraweropen) && $pluginsettings->frontpagedraweropen) {
    $blockdraweropen = true;
} else {
    $blockdraweropen = false;
}

if (defined('BEHAT_SITE_RUNNING') && get_user_preferences('behat_keep_drawer_closed') != 1) {
    $blockdraweropen = true;
}

$extraclasses = ['uses-drawers'];
if ($blockdraweropen) {
    $extraclasses[] = 'drawer-open-block';
}
$bodyattributes = $OUTPUT->body_attributes($extraclasses);
$blockshtml = $OUTPUT->blocks('side-pre');
$hasblocks = (strpos($blockshtml, 'data-block=') !== false || !empty($addblockbutton));
if (!$hasblocks) {
    $blockdraweropen = false;
}

$secondarynavigation = false;
$overflow = '';
if ($PAGE->has_secondary_navigation()) {
    $moremenu = new \core\navigation\output\more_menu($PAGE->secondarynav, 'nav-tabs');
    $secondarynavigation = $moremenu->export_for_template($OUTPUT);
    $overflowdata = $PAGE->secondarynav->get_overflow_menu_data();
    if (!is_null($overflowdata)) {
        $overflow = $overflowdata->export_for_template($OUTPUT);
    }
}

$primary = new theme_trema\output\primary_navigation($PAGE);

$renderer = $PAGE->get_renderer('core');
$primarymenu = $primary->export_for_template($renderer);

// Refresh active company ID and plugin settings after primary_navigation export (which initializes IOMAD session state).
$activecompanyid = theme_trema_get_active_companyid(true);
$pluginsettings = theme_trema_get_plugin_settings($activecompanyid);

// =========================================================================
// Capturar bloques y botón para el cuerpo central ('content' o aislado por compañía 'content-cID')
// =========================================================================
$contentregion = ($activecompanyid > 0 && !empty($pluginsettings->isolatecompanyblocks))
    ? 'content-c' . $activecompanyid
    : 'content';
$contentblockshtml = $OUTPUT->blocks($contentregion);
$addblockbuttoncontent = $OUTPUT->addblockbutton($contentregion);
$hascontentblocks = (strpos($contentblockshtml, 'data-block=') !== false || !empty($addblockbuttoncontent));

$buildregionmainsettings = !$PAGE->include_region_main_settings_in_header_actions() && !$PAGE->has_secondary_navigation();
$regionmainsettingsmenu = $buildregionmainsettings ? $OUTPUT->region_main_settings_menu() : false;

$header = $PAGE->activityheader;
$headercontent = $header->export_for_template($renderer);

$adminblockshtml = $OUTPUT->blocks('side-admin');
$numberofimages = isset($pluginsettings->numberofimages) ? (int)$pluginsettings->numberofimages : 1;
$overlayimage = $OUTPUT->image_url('frontpage/overlay', 'theme');
$context = \context_system::instance();

// Frontpage carousel images.
if ($numberofimages > 1) {
    $frontpagecarrousel = [];
    $active = true;
    for ($i = 1; $i <= $numberofimages; $i++) {
        $title = "carrouseltitle{$i}";
        $subtitle = "carrouselsubtitle{$i}";
        $btntext = "carrouselbtntext{$i}";
        $btnhref = "carrouselbtnhref{$i}";
        $btnclass = "carrouselbtnclass{$i}";
        $url = theme_trema_setting_file_url("frontpageimage{$i}", "frontpageimage{$i}", $PAGE->theme, $activecompanyid);
        if (empty($url)) {
            $url = $OUTPUT->image_url('frontpage/banner' . $i, 'theme');
        }
        $frontpagecarrousel[$i]['image'] = (isset($pluginsettings->frontpageenabledarkoverlay) && (string)$pluginsettings->frontpageenabledarkoverlay === '0')
            ? "background-image: url('$url');"
            : "background-image: url('$overlayimage'), url('$url');";
        $frontpagecarrousel[$i]['index']    = $i - 1;
        $frontpagecarrousel[$i]['title']    = !empty($pluginsettings->$title)
            ? \format_string($pluginsettings->$title)
            : \format_string(get_string('frontpagetitle_default', 'theme_trema'));
        $frontpagecarrousel[$i]['subtitle'] = !empty($pluginsettings->$subtitle)
            ? \format_text($pluginsettings->$subtitle, FORMAT_HTML, ['context' => $context])
            : \format_text(get_string('frontpagesubtitle_default', 'theme_trema'), FORMAT_HTML, ['context' => $context]);
        $frontpagecarrousel[$i]['btntext']  = !empty($pluginsettings->$btntext)
            ? \format_string($pluginsettings->$btntext)
            : \format_string(get_string('frontpagebuttontext_default', 'theme_trema'));
        $frontpagecarrousel[$i]['btnhref']  = !empty($pluginsettings->$btnhref) ? $pluginsettings->$btnhref : '#topofcontent';
        $frontpagecarrousel[$i]['btnclass'] = !empty($pluginsettings->$btnclass) ? $pluginsettings->$btnclass : 'btn-primary';
        if ($active) {
            $frontpagecarrousel[$i]['active'] = "active";
            $active = false;
        }
    }
    $frontpagecarrousel = array_values($frontpagecarrousel);
} else {
    $frontpagecarrousel = false;
}

$regionmainsettingsmenu = $OUTPUT->region_main_settings_menu();
$databs = $CFG->branch >= 500 ? 'bs-' : '';

// Resolve single banner and frontpage content with automatic fallback to Global and Trema factory defaults.
$fptitle = theme_trema_get_setting('frontpagetitle', $activecompanyid, get_string('frontpagetitle_default', 'theme_trema'));
$fpsubtitle = theme_trema_get_setting('frontpagesubtitle', $activecompanyid, get_string('frontpagesubtitle_default', 'theme_trema'));
$fpbtntext = theme_trema_get_setting('frontpagebuttontext', $activecompanyid, get_string('frontpagebuttontext_default', 'theme_trema'));
$fpbtnhref = theme_trema_get_setting('frontpagebuttonhref', $activecompanyid, '#topofcontent');
if (!empty($fpbtntext) && empty($fpbtnhref)) {
    $fpbtnhref = '#topofcontent';
}
$fpbtnclass = theme_trema_get_setting('frontpagebuttonclass', $activecompanyid, 'btn-primary');
$fpbody = theme_trema_get_setting('defaultfrontpagebody', $activecompanyid, '');
$fpfooter = theme_trema_get_setting('defaultfooter', $activecompanyid, get_string('defaultfooter_default', 'theme_trema'));
$fpenablecards = theme_trema_get_setting('frontpageenablecards', $activecompanyid, '1');
$fpcardstitle = theme_trema_get_setting('frontpagecardstitle', $activecompanyid, get_string('cardtitle', 'theme_trema'));
$fpcardssubtitle = theme_trema_get_setting('frontpagecardssubtitle', $activecompanyid, get_string('cardsubtitle', 'theme_trema'));

$templatecontext = [
    'sitename' => theme_trema_get_tenant_sitename(false, $activecompanyid),
    'output' => $OUTPUT,
    'sidepreblocks' => $blockshtml,
    'sideadminblocks' => $adminblockshtml,
    'hasblocks' => $hasblocks,
    'hasadminblocks' => is_siteadmin(),
    'contentblocks' => $contentblockshtml,
    'addblockbuttoncontent' => $addblockbuttoncontent,
    'hascontentblocks' => $hascontentblocks,
    'forceblockdraweropen' => $forceblockdraweropen,
    'bodyattributes' => $bodyattributes,
    'primarymoremenu' => $primarymenu['moremenu'],
    'secondarymoremenu' => $secondarynavigation ?: false,
    'mobileprimarynav' => $primarymenu['mobileprimarynav'],
    'usermenu' => $primarymenu['user'],
    'langmenu' => $primarymenu['lang'],
    'regionmainsettingsmenu' => $regionmainsettingsmenu,
    'hasregionmainsettingsmenu' => !empty($regionmainsettingsmenu),
    'headercontent' => $headercontent,
    'overflow' => $overflow,
    'addblockbutton' => $addblockbutton,
    'defaultfrontpagebody' => !empty($fpbody) ?
            \format_text($fpbody, FORMAT_HTML, ['context' => $context]) : '',
    'defaultfooter' => !empty($fpfooter) ?
            \format_text($fpfooter, FORMAT_HTML, ['context' => $context]) : '',
    'showbanner' => ($numberofimages > 0),
    'frontpagecarrousel' => $frontpagecarrousel,
    'ifcarrousel' => ($numberofimages > 1),
    'frontpagetitle' => ($fptitle !== '' && $fptitle !== false && $fptitle !== null) ?
        \format_string($fptitle) : '',
    'frontpagesubtitle' => ($fpsubtitle !== '' && $fpsubtitle !== false && $fpsubtitle !== null) ?
        \format_text($fpsubtitle, FORMAT_HTML, ['context' => $context]) : '',
    'frontpagebuttontext' => ($fpbtntext !== '' && $fpbtntext !== false && $fpbtntext !== null) ?
        \format_string($fpbtntext) : '',
    'frontpagebuttonclass' => !empty($fpbtnclass) ? $fpbtnclass : 'btn-primary',
    'frontpagebuttonhref' => !empty($fpbtnhref) ? $fpbtnhref : '#topofcontent',
    'hascards' => ((string)$fpenablecards !== '0' && !empty($fpenablecards)),
    'cardstitle' => ($fpcardstitle !== '' && $fpcardstitle !== false && $fpcardstitle !== null) ?
        \format_string($fpcardstitle) : '',
    'cardssubtitle' => ($fpcardssubtitle !== '' && $fpcardssubtitle !== false && $fpcardssubtitle !== null) ?
        \format_string($fpcardssubtitle) : '',
    'cardssettings' => theme_trema_get_cards_settings($activecompanyid),
    'enabletremafooter' => isset($pluginsettings->enabletremafooter) ? !empty($pluginsettings->enabletremafooter) : true,
    'footerinfo' => isset($pluginsettings->enablefooterinfo) ? !empty($pluginsettings->enablefooterinfo) : true,
    'showbranding' => isset($pluginsettings->showbranding) ? !empty($pluginsettings->showbranding) : true,
    'databs' => $databs,
];

if ($blockdraweropen && debugging() == false) {
    $templatecontext['blockdraweropen'] = $blockdraweropen;
}

echo $OUTPUT->render_from_template('theme_trema/frontpage', $templatecontext);