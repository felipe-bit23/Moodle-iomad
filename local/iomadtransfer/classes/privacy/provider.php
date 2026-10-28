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
 * Privacy Subsystem implementation for local_iomadtransfer.
 *
 * @package    local_iomadtransfer
 * @copyright  2026 IOMAD Multi-Tenant Consolidation Engine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_iomadtransfer\privacy;

use context;
use context_system;
use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

defined('MOODLE_INTERNAL') || die();

/**
 * GDPR / Privacy API provider for local_iomadtransfer.
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\core_userlist_provider {

    /**
     * Returns metadata about the tables and external data stored by this plugin.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(
            'local_iomadtransfer_request',
            [
                'userid' => 'privacy:metadata:local_iomadtransfer_request:userid',
                'siteurl' => 'privacy:metadata:local_iomadtransfer_request:siteurl',
                'origin_course_id' => 'privacy:metadata:local_iomadtransfer_request:origin_course_id',
                'target_course_id' => 'privacy:metadata:local_iomadtransfer_request:target_course_id',
                'companyid' => 'privacy:metadata:local_iomadtransfer_request:companyid',
                'status' => 'privacy:metadata:local_iomadtransfer_request:status',
                'timecreated' => 'privacy:metadata:local_iomadtransfer_request:timecreated',
            ],
            'privacy:metadata:local_iomadtransfer_request'
        );

        $collection->add_database_table(
            'local_iomadtransfer_origin',
            [
                'userid' => 'privacy:metadata:local_iomadtransfer_origin:userid',
                'host' => 'privacy:metadata:local_iomadtransfer_origin:host',
                'timecreated' => 'privacy:metadata:local_iomadtransfer_origin:timecreated',
            ],
            'privacy:metadata:local_iomadtransfer_origin'
        );

        $collection->add_database_table(
            'local_iomadtransfer_target',
            [
                'userid' => 'privacy:metadata:local_iomadtransfer_target:userid',
                'host' => 'privacy:metadata:local_iomadtransfer_target:host',
                'timecreated' => 'privacy:metadata:local_iomadtransfer_target:timecreated',
            ],
            'privacy:metadata:local_iomadtransfer_target'
        );

        $collection->add_external_location_link(
            'remote_moodle',
            [
                'userid' => 'privacy:metadata:remote_moodle:userid',
                'courseid' => 'privacy:metadata:remote_moodle:courseid',
            ],
            'privacy:metadata:remote_moodle'
        );

        return $collection;
    }

    /**
     * Get the list of contexts that contain user information for the specified user.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        global $DB;
        $contextlist = new contextlist();
        if (
            $DB->record_exists('local_iomadtransfer_request', ['userid' => $userid]) ||
            $DB->record_exists('local_iomadtransfer_origin', ['userid' => $userid]) ||
            $DB->record_exists('local_iomadtransfer_target', ['userid' => $userid])
        ) {
            $contextlist->add_system_context();
        }
        return $contextlist;
    }

    /**
     * Get the list of users who have data within a context.
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if (!$context instanceof context_system) {
            return;
        }
        $userlist->add_from_sql("SELECT userid FROM {local_iomadtransfer_request}", []);
        $userlist->add_from_sql("SELECT userid FROM {local_iomadtransfer_origin}", []);
        $userlist->add_from_sql("SELECT userid FROM {local_iomadtransfer_target}", []);
    }

    /**
     * Export all user data for the specified user, in the specified contexts.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;
        if (empty($contextlist->count())) {
            return;
        }
        $userid = $contextlist->get_user()->id;
        $sysctx = context_system::instance();

        $requests = $DB->get_records('local_iomadtransfer_request', ['userid' => $userid]);
        if (!empty($requests)) {
            writer::with_context($sysctx)->export_data(
                [get_string('pluginname', 'local_iomadtransfer'), 'requests'],
                (object)['requests' => array_values($requests)]
            );
        }
    }

    /**
     * Delete all data for all users in the specified context.
     *
     * @param context $context
     */
    public static function delete_data_for_all_users_in_context(context $context): void {
        // Administrative audit logs are retained under system admin ownership.
    }

    /**
     * Delete all user data for the specified user, in the specified contexts.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;
        $userid = $contextlist->get_user()->id;
        $admin = get_admin();
        $adminid = $admin ? (int)$admin->id : 2;
        $DB->set_field('local_iomadtransfer_request', 'userid', $adminid, ['userid' => $userid]);
    }

    /**
     * Delete multiple users within a single context.
     *
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;
        $context = $userlist->get_context();
        if (!$context instanceof context_system) {
            return;
        }
        $userids = $userlist->get_userids();
        if (empty($userids)) {
            return;
        }
        $admin = get_admin();
        $adminid = $admin ? (int)$admin->id : 2;
        list($insql, $inparams) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $DB->execute("UPDATE {local_iomadtransfer_request} SET userid = :adminid WHERE userid $insql",
            array_merge(['adminid' => $adminid], $inparams));
    }
}
