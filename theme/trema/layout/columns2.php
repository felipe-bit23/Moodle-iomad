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
 * A two column layout for the Trema theme with IOMAD Multi-Tenant support.
 *
 * @package     theme_trema
 * @copyright   2022-2026 Trema - {@link https://trema.tech/}
 * @copyright   2024-2026 TNG Consulting Inc. - {@link https://www.tngconsulting.ca/}
 * @author      Rodrigo Mady
 * @author      Trevor Furtado
 * @author      Michael Milette
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($CFG->branch <= 402) {
    user_preference_allow_ajax_update('drawer-open-nav', PARAM_ALPHA);
}
require_once($CFG->libdir . '/behat/lib.php');
require_once($CFG->dirroot . '/theme/trema/locallib.php');

// Add block button in editing mode.
$addblockbutton = $OUTPUT->addblockbutton();

$extraclasses = [];
$bodyattributes = $OUTPUT->body_attributes($extraclasses);
$blockshtml = $OUTPUT->blocks('side-pre');
$hasblocks = (strpos($blockshtml, 'data-block=') !== false || !empty($addblockbutton));

$secondarynavigation = false;
$overflow = '';
if ($PAGE->has_secondary_navigation()) {
    $tablistnav = $PAGE->has_tablist_secondary_navigation();
    $moremenu = new \core\navigation\output\more_menu($PAGE->secondarynav, 'nav-tabs', true, $tablistnav);
    $secondarynavigation = $moremenu->export_for_template($OUTPUT);
    $overflowdata = $PAGE->secondarynav->get_overflow_menu_data();
    if (!is_null($overflowdata)) {
        $overflow = $overflowdata->export_for_template($OUTPUT);
    }
}

$primary = new theme_trema\output\primary_navigation($PAGE);

$renderer = $PAGE->get_renderer('core');
$primarymenu = $primary->export_for_template($renderer);
$activecompanyid = theme_trema_get_active_companyid(true);
$pluginsettings = theme_trema_get_plugin_settings($activecompanyid);
$buildregionmainsettings = !$PAGE->include_region_main_settings_in_header_actions()  && !$PAGE->has_secondary_navigation();
$regionmainsettingsmenu = $buildregionmainsettings ? $OUTPUT->region_main_settings_menu() : false;

$header = $PAGE->activityheader;
$headercontent = $header->export_for_template($renderer);

$pluginsettings = theme_trema_get_plugin_settings();
$context = \context_system::instance();

$templatecontext = [
    'sitename' => theme_trema_get_tenant_sitename(false),
    'output' => $OUTPUT,
    'sidepreblocks' => $blockshtml,
    'hasblocks' => $hasblocks,
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
    'defaultfooter' => \format_text($pluginsettings->defaultfooter ?? '', FORMAT_HTML, ['context' => $context, 'noclean' => true]),
    'showbranding' => isset($pluginsettings->showbranding) ? !empty($pluginsettings->showbranding) : true,
];

echo $OUTPUT->render_from_template('theme_trema/columns2', $templatecontext);
