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
 * Security, Role, Capability, and Service Token Manager for local_iomadtransfer.
 *
 * Guarantees that the service user and execution context possess 100% of Moodle 4.5+
 * sensitive data capabilities (user identity, user creation, role assignment, manual/IOMAD
 * enrolments, full gradebook, question bank categories, and quiz attempts).
 *
 * @package    local_iomadtransfer
 * @copyright  2026 IOMAD Multi-Tenant Consolidation Engine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_iomadtransfer;

use context_system;
use stdClass;
use Throwable;

defined('MOODLE_INTERNAL') || die();

/**
 * Security and token provisioning manager.
 */
class security_manager {
    /** @var string Service user and role shortname */
    const USERNAME_WS = 'local_iomadtransfer_ws';

    /** @var string External service shortname */
    const SERVICE_SHORTNAME = 'local_iomadtransfer';

    /**
     * All capabilities required to export and restore sensitive student data, grades,
     * enrolments, role assignments, question banks, and quiz attempts without Moodle blocking.
     *
     * @return string[]
     */
    public static function get_required_capabilities(): array {
        return [
            // Plugin capabilities.
            'local/iomadtransfer:manage',
            'local/iomadtransfer:restore',
            'local/iomadtransfer:consolidate_iomad',
            'local/iomadtransfer:view_logs',
            // Webservice protocol.
            'webservice/rest:use',
            // Course & Category visibility and management.
            'moodle/category:manage',
            'moodle/category:viewcourselist',
            'moodle/category:viewhiddencategories',
            'moodle/course:create',
            'moodle/course:delete',
            'moodle/course:update',
            'moodle/course:view',
            'moodle/course:viewhiddencourses',
            'moodle/course:viewhiddenactivities',
            'moodle/course:viewhiddensections',
            'moodle/course:ignoreavailabilityrestrictions',
            'moodle/course:manageactivities',
            'moodle/course:activityvisibility',
            'moodle/course:enrolconfig',
            'moodle/course:enrolreview',
            'moodle/course:managegroups',
            // Full Backup capabilities (including sensitive userinfo).
            'moodle/backup:backupcourse',
            'moodle/backup:backupactivity',
            'moodle/backup:backupsection',
            'moodle/backup:backuptargetimport',
            'moodle/backup:Configure',
            'moodle/backup:configure',
            'moodle/backup:downloadfile',
            'moodle/backup:userinfo',
            'moodle/backup:anonymise',
            // Full Restore capabilities (including sensitive userinfo, user creation & role assignment).
            'moodle/restore:restorecourse',
            'moodle/restore:restoreactivity',
            'moodle/restore:restoresection',
            'moodle/restore:restoretargetimport',
            'moodle/restore:uploadfile',
            'moodle/restore:rolldates',
            'moodle/restore:userinfo',
            'moodle/restore:createuser',
            'moodle/restore:configure',
            'moodle/restore:viewautomatedfilearea',
            // Sensitive User Identity, Profile & Role Assignment capabilities.
            'moodle/user:create',
            'moodle/user:update',
            'moodle/user:viewdetails',
            'moodle/user:viewhiddendetails',
            'moodle/user:viewalldetails',
            'moodle/site:viewuseridentity',
            'moodle/site:viewfullnames',
            'moodle/site:maintenanceaccess',
            'moodle/site:configview',
            'moodle/role:assign',
            'moodle/role:manage',
            'moodle/role:override',
            'moodle/role:review',
            // Enrolment capabilities so restored students get enrolled cleanly.
            'enrol/manual:enrol',
            'enrol/manual:manage',
            'enrol/manual:unenrol',
            // Gradebook & Completion capabilities.
            'moodle/grade:viewall',
            'moodle/grade:manage',
            'moodle/grade:edit',
            'moodle/grade:import',
            'moodle/grade:export',
            'moodle/grade:unlock',
            // Question Bank & Quiz / Assignment Attempt capabilities.
            'moodle/question:add',
            'moodle/question:editall',
            'moodle/question:viewall',
            'moodle/question:useall',
            'moodle/question:moveall',
            'moodle/question:managecategory',
            'mod/quiz:view',
            'mod/quiz:viewreports',
            'mod/quiz:grade',
            'mod/quiz:regrade',
            'mod/quiz:manage',
            'mod/assign:view',
            'mod/assign:grade',
            'mod/assign:viewgrades',
            // Certificate capabilities if present.
            'tool/certificate:manage',
            'tool/certificate:issue',
            'tool/certificate:viewallcertificates',
            'mod/coursecertificate:addinstance',
            'mod/coursecertificate:view',
            'mod/coursecertificate:viewreport',
        ];
    }

    /**
     * Resolves the optimal user ID to execute backup_controller and restore_controller.
     * Always prefers the primary site administrator (get_admin()->id) so Moodle's internal
     * backup_check_security and restore_check_security never strip sensitive student data,
     * grades, or quiz attempts.
     *
     * @param int $fallbackuserid
     * @return int
     */
    public static function resolve_execution_userid(int $fallbackuserid = 0): int {
        $admin = get_admin();
        if ($admin && !empty($admin->id)) {
            return (int)$admin->id;
        }
        return $fallbackuserid > 0 ? $fallbackuserid : 2;
    }

    /**
     * Provisions (or repairs) the Web Service Role, User, Capabilities, Role Allow Assign matrix,
     * Site Policy acceptance, and Permanent REST Token in one atomic operation.
     *
     * @param bool $regeneratetoken If true, revokes any existing token and generates a fresh one.
     * @return string|null The active Web Service token.
     */
    public static function provision_service_and_token(bool $regeneratetoken = false): ?string {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/user/lib.php');
        require_once($CFG->libdir . '/accesslib.php');
        if (!defined('EXTERNAL_TOKEN_PERMANENT')) {
            if (file_exists($CFG->dirroot . '/webservice/lib.php')) {
                require_once($CFG->dirroot . '/webservice/lib.php');
            }
            if (!defined('EXTERNAL_TOKEN_PERMANENT')) {
                define('EXTERNAL_TOKEN_PERMANENT', 0);
            }
        }

        $syscontext = context_system::instance();

        // 1. Enable Web Services and REST protocol globally.
        set_config('enablewebservices', 1);
        $activeprotocols = (string)get_config('core', 'webserviceprotocols');
        $protocols = array_filter(array_map('trim', explode(',', $activeprotocols)));
        if (!in_array('rest', $protocols, true)) {
            $protocols[] = 'rest';
            set_config('webserviceprotocols', implode(',', $protocols));
        }
        set_config('enablewsdocumentation', 1);

        // 2. Create or update the dedicated role with 'manager' archetype.
        $rolerecord = $DB->get_record('role', ['shortname' => self::USERNAME_WS]);
        if (!$rolerecord) {
            $roleid = create_role(
                'IOMAD Course Transfer & Consolidator WS',
                self::USERNAME_WS,
                'Full-privilege role for local_iomadtransfer web services, backups, restores and IOMAD consolidation',
                'manager'
            );
        } else {
            $roleid = (int)$rolerecord->id;
        }

        // Ensure role is assignable at all context levels (System, Category, Course, Module).
        set_role_contextlevels($roleid, [CONTEXT_SYSTEM, CONTEXT_COURSECAT, CONTEXT_COURSE, CONTEXT_MODULE]);

        // 3. Grant all existing capabilities from our list that are registered in mdl_capabilities.
        $installedcaps = $DB->get_records_menu('capabilities', null, '', 'name, id');
        foreach (self::get_required_capabilities() as $cap) {
            if (isset($installedcaps[$cap])) {
                assign_capability($cap, CAP_ALLOW, $roleid, $syscontext->id, true);
            }
        }

        // 4. Populate mdl_role_allow_assign, mdl_role_allow_override, and mdl_role_allow_switch
        // for ALL roles so restore_controller never raises a role mapping warning when assigning 'student'.
        $allroles = $DB->get_records('role', null, '', 'id, shortname');
        foreach ($allroles as $targetrole) {
            $tid = (int)$targetrole->id;
            if (!$DB->record_exists('role_allow_assign', ['roleid' => $roleid, 'allowassign' => $tid])) {
                if (function_exists('core_role_set_assign_allowed')) {
                    core_role_set_assign_allowed($roleid, $tid);
                } else {
                    $rec = new stdClass();
                    $rec->roleid = $roleid;
                    $rec->allowassign = $tid;
                    $DB->insert_record('role_allow_assign', $rec, false);
                }
            }
            if (!$DB->record_exists('role_allow_override', ['roleid' => $roleid, 'allowoverride' => $tid])) {
                if (function_exists('core_role_set_override_allowed')) {
                    core_role_set_override_allowed($roleid, $tid);
                } else {
                    $rec = new stdClass();
                    $rec->roleid = $roleid;
                    $rec->allowoverride = $tid;
                    $DB->insert_record('role_allow_override', $rec, false);
                }
            }
            if (!$DB->record_exists('role_allow_switch', ['roleid' => $roleid, 'allowswitch' => $tid])) {
                if (function_exists('core_role_set_switch_allowed')) {
                    core_role_set_switch_allowed($roleid, $tid);
                } else {
                    $rec = new stdClass();
                    $rec->roleid = $roleid;
                    $rec->allowswitch = $tid;
                    $DB->insert_record('role_allow_switch', $rec, false);
                }
            }
        }

        // 5. Create or retrieve the service user AND make sure it is active, confirmed, and assigned the role.
        $wsuser = $DB->get_record('user', ['username' => self::USERNAME_WS, 'deleted' => 0]);
        if (!$wsuser) {
            $newuser = new stdClass();
            $newuser->username = self::USERNAME_WS;
            $newuser->password = generate_password(16);
            $newuser->firstname = 'IOMAD Transfer';
            $newuser->lastname = 'Service';
            $newuser->email = 'iomadtransfer_ws@localhost.local';
            $newuser->description = 'Automated Web Service user for local_iomadtransfer';
            $newuser->confirmed = 1;
            $newuser->mnethostid = (int)$CFG->mnet_localhost_id;
            $wsuserid = (int)user_create_user($newuser, false, false);
        } else {
            $wsuserid = (int)$wsuser->id;
            if ((int)$wsuser->suspended === 1 || (int)$wsuser->confirmed === 0) {
                $DB->set_field('user', 'suspended', 0, ['id' => $wsuserid]);
                $DB->set_field('user', 'confirmed', 1, ['id' => $wsuserid]);
            }
        }

        role_assign($roleid, $wsuserid, $syscontext->id);

        // Also assign the standard 'manager' role at system context if it exists as a second layer of defense.
        $managerrole = $DB->get_record('role', ['shortname' => 'manager']);
        if ($managerrole) {
            role_assign((int)$managerrole->id, $wsuserid, $syscontext->id);
        }

        // 6. Accept site policies (tool_policy) for both WS user and Admin so pluginfile downloads never fail.
        self::accept_site_policies($wsuserid);
        $admin = get_admin();
        if ($admin) {
            self::accept_site_policies((int)$admin->id);
        }

        // 7. Ensure the external service exists and has downloadfiles=1, uploadfiles=1, enabled=1.
        $service = $DB->get_record('external_services', ['component' => 'local_iomadtransfer']);
        if (!$service) {
            $service = $DB->get_record('external_services', ['shortname' => self::SERVICE_SHORTNAME]);
        }
        if (!$service) {
            $service = new stdClass();
            $service->name = 'IOMAD Course Transfer & Consolidator Service';
            $service->shortname = self::SERVICE_SHORTNAME;
            $service->component = 'local_iomadtransfer';
            $service->enabled = 1;
            $service->restrictedusers = 1;
            $service->downloadfiles = 1;
            $service->uploadfiles = 1;
            $service->timecreated = time();
            $service->timemodified = time();
            $service->id = $DB->insert_record('external_services', $service);
        } else {
            $service->enabled = 1;
            $service->downloadfiles = 1;
            $service->uploadfiles = 1;
            $service->shortname = self::SERVICE_SHORTNAME;
            $service->timemodified = time();
            $DB->update_record('external_services', $service);
        }

        // Register all 6 functions in external_services_functions if missing.
        $fnnames = [
            'local_iomadtransfer_ping',
            'local_iomadtransfer_origin_get_courses',
            'local_iomadtransfer_origin_get_course_templates',
            'local_iomadtransfer_origin_backup_course',
            'local_iomadtransfer_target_backup_completed',
            'local_iomadtransfer_target_backup_error',
        ];
        foreach ($fnnames as $fn) {
            if ($DB->record_exists('external_functions', ['name' => $fn]) &&
                !$DB->record_exists('external_services_functions', ['externalserviceid' => $service->id, 'functionname' => $fn])) {
                $frec = new stdClass();
                $frec->externalserviceid = $service->id;
                $frec->functionname = $fn;
                $DB->insert_record('external_services_functions', $frec);
            }
        }

        // 8. Authorize both the WS user and the primary Admin user on the service.
        $tokenuserid = $admin ? (int)$admin->id : $wsuserid;
        foreach (array_unique([$wsuserid, $tokenuserid]) as $uid) {
            if (!$DB->record_exists('external_services_users', ['externalserviceid' => $service->id, 'userid' => $uid])) {
                $esu = new stdClass();
                $esu->externalserviceid = $service->id;
                $esu->userid = $uid;
                $esu->iprestriction = '';
                $esu->validuntil = 0;
                $esu->timecreated = time();
                $DB->insert_record('external_services_users', $esu);
            }
        }

        // 9. Generate or retrieve the permanent token (bound to primary Admin user so all WS calls
        // execute with full siteadmin privileges on both Origin and Target!).
        if ($regeneratetoken) {
            $DB->delete_records('external_tokens', [
                'externalserviceid' => $service->id,
                'tokentype' => EXTERNAL_TOKEN_PERMANENT,
            ]);
        }

        $existingtokens = $DB->get_records('external_tokens', [
            'externalserviceid' => $service->id,
            'tokentype' => EXTERNAL_TOKEN_PERMANENT,
        ], 'timecreated DESC');

        if (!empty($existingtokens)) {
            $first = reset($existingtokens);
            // Ensure the token belongs to the admin user so WS calls bypass all non-admin restrictions.
            if ((int)$first->userid !== $tokenuserid) {
                $DB->set_field('external_tokens', 'userid', $tokenuserid, ['id' => $first->id]);
            }
            return $first->token;
        }

        if (class_exists('\core_external\util')) {
            return \core_external\util::generate_token(
                EXTERNAL_TOKEN_PERMANENT,
                $service,
                $tokenuserid,
                $syscontext
            );
        }
        require_once($CFG->libdir . '/externallib.php');
        return external_generate_token(
            EXTERNAL_TOKEN_PERMANENT,
            $service,
            $tokenuserid,
            $syscontext
        );
    }

    /**
     * Returns the currently active permanent token without regenerating it (or creates one if none exists).
     *
     * @return string|null
     */
    public static function get_or_create_token(): ?string {
        return self::provision_service_and_token(false);
    }

    /**
     * Accepts any active tool_policy site policies on behalf of the given user ID.
     *
     * @param int $userid
     */
    public static function accept_site_policies(int $userid): void {
        if ($userid <= 0 || !class_exists('\tool_policy\api')) {
            return;
        }
        try {
            $versionids = \tool_policy\api::get_current_versions_ids();
            if (!empty($versionids)) {
                \tool_policy\api::accept_policies(array_values($versionids), $userid);
            }
        } catch (Throwable $e) {
            // Ignore if no policies are active.
        }
    }

    /**
     * Resolves the physical database table name for IOMAD across both classic IOMAD (4.x)
     * and new IOMAD (4.5 experimental / 5.1+ local_iomad_* naming convention).
     *
     * @param string $logical Logical table name (e.g. company, company_users, department)
     * @return string|null Physical table name if it exists, or null
     */
    public static function resolve_iomad_table(string $logical): ?string {
        global $DB;
        static $map = [
            'company' => ['company', 'local_iomad_companies'],
            'company_users' => ['company_users', 'local_iomad_company_users'],
            'company_course' => ['company_course', 'local_iomad_company_courses'],
            'company_course_groups' => ['company_course_groups', 'local_iomad_company_course_groups'],
            'company_shared_courses' => ['company_shared_courses', 'local_iomad_company_shared_courses'],
            'company_created_courses' => ['company_created_courses', 'local_iomad_company_created_courses'],
            'department' => ['department', 'local_iomad_company_departments'],
            'iomad_courses' => ['iomad_courses', 'local_iomad_courses'],
            'local_iomad_track' => ['local_iomad_track', 'local_iomad_tracks'],
            'companylicense' => ['companylicense', 'local_iomad_company_licenses'],
            'companylicense_users' => ['companylicense_users', 'local_iomad_company_license_users'],
        ];
        try {
            $dbman = $DB->get_manager();
            $candidates = $map[$logical] ?? [$logical];
            foreach ($candidates as $tbl) {
                if ($dbman->table_exists($tbl)) {
                    return $tbl;
                }
            }
        } catch (Throwable $e) {
            return null;
        }
        return null;
    }

    /**
     * Checks whether IOMAD tables exist on this Moodle instance.
     *
     * @return bool
     */
    public static function has_iomad(): bool {
        static $hasiomad = null;
        if ($hasiomad !== null) {
            return $hasiomad;
        }
        $hasiomad = (self::resolve_iomad_table('company') !== null)
            && (self::resolve_iomad_table('company_users') !== null);
        return $hasiomad;
    }

    /**
     * Returns all active IOMAD companies on this instance.
     *
     * @return array
     */
    public static function get_iomad_companies(): array {
        global $DB;
        $tbl = self::resolve_iomad_table('company');
        if (!$tbl) {
            return [];
        }
        try {
            $cols = $DB->get_columns($tbl);
            if (isset($cols['suspended'])) {
                return $DB->get_records($tbl, ['suspended' => 0], 'name ASC');
            }
            return $DB->get_records($tbl, null, 'name ASC');
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Returns all departments for a specific company (or all companies if $companyid === 0).
     *
     * @param int $companyid
     * @return array
     */
    public static function get_company_departments(int $companyid = 0): array {
        global $DB;
        $tbl = self::resolve_iomad_table('department');
        if (!$tbl) {
            return [];
        }
        try {
            $cols = $DB->get_columns($tbl);
            $compcol = isset($cols['companyid']) ? 'companyid' : 'company';
            $parentcol = isset($cols['parentid']) ? 'parentid' : 'parent';
            if ($companyid > 0) {
                return $DB->get_records($tbl, [$compcol => $companyid], $parentcol . ' ASC, name ASC');
            }
            return $DB->get_records($tbl, null, $parentcol . ' ASC, name ASC');
        } catch (Throwable $e) {
            return [];
        }
    }
}
