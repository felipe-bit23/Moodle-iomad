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
 * admin_plugins.php
 *
 * @package   mod_videoprogress
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once($CFG->libdir . '/adminlib.php');

$type = required_param("type", PARAM_ALPHA);
$types = [
    "videoprogresssource" => [
        "section" => "mod_videoprogress_sourceplugins",
        "title" => "subplugintype_videoprogresssource_plural",
        "description" => "sourcepluginshelp",
    ],
    "videoprogressmaterial" => [
        "section" => "mod_videoprogress_materialplugins",
        "title" => "subplugintype_videoprogressmaterial_plural",
        "description" => "materialpluginshelp",
    ],
    "videoprogresscontent" => [
        "section" => "mod_videoprogress_contentplugins",
        "title" => "subplugintype_videoprogresscontent_plural",
        "description" => "contentpluginshelp",
    ],
    "videoprogressobjective" => [
        "section" => "mod_videoprogress_objectiveplugins",
        "title" => "subplugintype_videoprogressobjective_plural",
        "description" => "objectivepluginshelp",
    ],
];
if (!isset($types[$type])) {
    throw new invalid_parameter_exception(get_string("invalidsubplugintype", "videoprogress"));
}

require_admin();
admin_externalpage_setup($types[$type]["section"]);

$pluginmanager = core_plugin_manager::instance();
$plugins = [];
foreach ($pluginmanager->get_plugins_of_type($type) as $plugininfo) {
    $settingsurl = $plugininfo->get_settings_url();
    $canuninstall = $pluginmanager->can_uninstall_plugin($plugininfo->component);
    $plugins[] = [
        "name" => $plugininfo->displayname,
        "component" => $plugininfo->component,
        "version" => $plugininfo->versiondisk ?? get_string("unknown", "videoprogress"),
        "release" => $plugininfo->release ?? get_string("unknown", "videoprogress"),
        "status" => get_string(
            $plugininfo->is_installed_and_upgraded() ? "pluginstatusready" : "pluginstatusupgrade",
            "videoprogress"
        ),
        "settingsurl" => $settingsurl ? $settingsurl->out(false) : null,
        "uninstallurl" => $canuninstall ? $plugininfo->get_default_uninstall_url("manage")->out(false) : null,
        "canuninstall" => $canuninstall,
    ];
}

$PAGE->set_title(get_string($types[$type]["title"], "videoprogress"));
$PAGE->set_heading(get_string("pluginadministration", "videoprogress"));

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('mod_videoprogress/admin_plugins', [
    "title" => get_string($types[$type]["title"], "videoprogress"),
    "description" => get_string($types[$type]["description"], "videoprogress"),
    "plugins" => $plugins,
    "hasplugins" => !empty($plugins),
    "componentlabel" => get_string("plugincomponent", "videoprogress"),
    "versionlabel" => get_string("pluginversion", "videoprogress"),
    "releaselabel" => get_string("pluginrelease", "videoprogress"),
    "statuslabel" => get_string("pluginstatus", "videoprogress"),
    "settingslabel" => get_string("settings"),
    "uninstalllabel" => get_string("uninstallplugin", "core_admin"),
    "inuselabel" => get_string("subplugininuse", "videoprogress"),
    "emptytitle" => get_string("nosubplugins", "videoprogress"),
    "emptydescription" => get_string("nosubpluginshelp", "videoprogress"),
]);
echo $OUTPUT->footer();
