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
 * settings.php
 *
 * @package   mod_videoprogress
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

if ($hassiteconfig) {
    $ADMIN->add("modsettings", new admin_externalpage(
        "mod_videoprogress_sourceplugins",
        get_string("subplugintype_videoprogresssource_plural", "videoprogress"),
        new moodle_url('/mod/videoprogress/admin_plugins.php', ["type" => "videoprogresssource"]),
        'moodle/site:config'
    ));

    $ADMIN->add("modsettings", new admin_externalpage(
        "mod_videoprogress_materialplugins",
        get_string("subplugintype_videoprogressmaterial_plural", "videoprogress"),
        new moodle_url('/mod/videoprogress/admin_plugins.php', ["type" => "videoprogressmaterial"]),
        'moodle/site:config'
    ));

    $ADMIN->add("modsettings", new admin_externalpage(
        "mod_videoprogress_contentplugins",
        get_string("subplugintype_videoprogresscontent_plural", "videoprogress"),
        new moodle_url('/mod/videoprogress/admin_plugins.php', ["type" => "videoprogresscontent"]),
        'moodle/site:config'
    ));

    $ADMIN->add("modsettings", new admin_externalpage(
        "mod_videoprogress_objectiveplugins",
        get_string("subplugintype_videoprogressobjective_plural", "videoprogress"),
        new moodle_url('/mod/videoprogress/admin_plugins.php', ["type" => "videoprogressobjective"]),
        'moodle/site:config'
    ));

    $ADMIN->add("reports", new admin_externalpage(
        "mod_videoprogress_diagnostics",
        get_string("diagnosticstitle", "videoprogress"),
        new moodle_url('/mod/videoprogress/admin/diagnostics.php'),
        'moodle/site:config'
    ));
}
