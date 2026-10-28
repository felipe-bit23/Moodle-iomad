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
 * Administration settings for local_iomadtransfer.
 *
 * @package    local_iomadtransfer
 * @copyright  2026 IOMAD Multi-Tenant Consolidation Engine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    global $ADMIN, $CFG;

    if (!$ADMIN->locate('local_iomadtransfer_category')) {
        $ADMIN->add('modules', new admin_category(
            'local_iomadtransfer_category',
            new lang_string('pluginname', 'local_iomadtransfer')
        ));
    }

    $ADMIN->add('local_iomadtransfer_category', new admin_externalpage(
        'local_iomadtransfer_summary',
        get_string('nav_summary', 'local_iomadtransfer'),
        new moodle_url('/local/iomadtransfer/index.php')
    ));

    $ADMIN->add('local_iomadtransfer_category', new admin_externalpage(
        'local_iomadtransfer_sites',
        get_string('nav_sites', 'local_iomadtransfer'),
        new moodle_url('/local/iomadtransfer/sites.php')
    ));

    $ADMIN->add('local_iomadtransfer_category', new admin_externalpage(
        'local_iomadtransfer_consolidate',
        get_string('nav_consolidate', 'local_iomadtransfer'),
        new moodle_url('/local/iomadtransfer/consolidate.php')
    ));

    $ADMIN->add('local_iomadtransfer_category', new admin_externalpage(
        'local_iomadtransfer_logs',
        get_string('nav_logs', 'local_iomadtransfer'),
        new moodle_url('/local/iomadtransfer/logs.php')
    ));

    $settings = new admin_settingpage(
        'local_iomadtransfer',
        get_string('pluginname', 'local_iomadtransfer')
    );
    $ADMIN->add('localplugins', $settings);

    $settings->add(new admin_setting_heading(
        'local_iomadtransfer/general_heading',
        get_string('settings_general_heading', 'local_iomadtransfer'),
        get_string('settings_general_desc', 'local_iomadtransfer')
    ));

    $settings->add(new admin_setting_configtext(
        'local_iomadtransfer/max_backup_size_mb',
        get_string('setting_max_backup_size_mb', 'local_iomadtransfer'),
        get_string('setting_max_backup_size_mb_desc', 'local_iomadtransfer'),
        2048,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_iomadtransfer/request_timeout',
        get_string('setting_request_timeout', 'local_iomadtransfer'),
        get_string('setting_request_timeout_desc', 'local_iomadtransfer'),
        60,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_iomadtransfer/ignorecurlsecurity',
        get_string('setting_ignorecurlsecurity', 'local_iomadtransfer'),
        get_string('setting_ignorecurlsecurity_desc', 'local_iomadtransfer'),
        1
    ));

    $settings->add(new admin_setting_configselect(
        'local_iomadtransfer/user_match_field',
        get_string('setting_user_match_field', 'local_iomadtransfer'),
        get_string('setting_user_match_field_desc', 'local_iomadtransfer'),
        'email',
        [
            'email' => 'email',
            'username' => 'username',
            'idnumber' => 'idnumber',
        ]
    ));
}
