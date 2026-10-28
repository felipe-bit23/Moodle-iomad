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
 * Transfer Request State Machine, Fatal Shutdown Handler, Company Manager Sync & Certificate Auditor
 * (supporting both mod_customcert and Moodle Workplace mod_coursecertificate + tool_certificate).
 *
 * @package    local_iomadtransfer
 * @copyright  2026 IOMAD Multi-Tenant Consolidation Engine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_iomadtransfer;

use context_course;
use context_module;
use core_shutdown_manager;
use moodle_url;
use stdClass;
use Throwable;

defined('MOODLE_INTERNAL') || die();

/**
 * State machine and on-demand audit helper for local_iomadtransfer_request records.
 */
class request_manager {
    /** @var string */
    const TABLE = 'local_iomadtransfer_request';

    /** @var int Target modes */
    const TARGET_NEW_COURSE = 2;
    const TARGET_REPLACE_COURSE = 3;
    const TARGET_MERGE_COURSE = 4;
    const TARGET_CONSOLIDATE_IOMAD = 5;

    /** @var int Request directions */
    const DIRECTION_TARGET = 0;
    const DIRECTION_ORIGIN = 1;

    /** @var int Status codes */
    const STATUS_ERROR = 0;
    const STATUS_NOT_STARTED = 1;
    const STATUS_IN_PROGRESS = 10;
    const STATUS_BACKUP = 30;
    const STATUS_DOWNLOAD = 50;
    const STATUS_DOWNLOADED = 70;
    const STATUS_RESTORE = 80;
    const STATUS_CONSOLIDATING = 85;
    const STATUS_COMPLETED = 100;

    /**
     * Retrieves a request record by ID.
     *
     * @param int $id
     * @return stdClass|false
     */
    public static function get(int $id) {
        global $DB;
        return $DB->get_record(self::TABLE, ['id' => $id]);
    }

    /**
     * Inserts or updates a request record.
     *
     * @param stdClass $record
     * @return int Record ID
     */
    public static function save(stdClass $record): int {
        global $DB;
        $record->timemodified = time();
        if (!empty($record->id)) {
            $DB->update_record(self::TABLE, $record);
            return (int)$record->id;
        }
        if (empty($record->timecreated)) {
            $record->timecreated = $record->timemodified;
        }
        $record->id = (int)$DB->insert_record(self::TABLE, $record);
        return $record->id;
    }

    /**
     * Marks a request as failed with error code and message.
     *
     * @param int $requestid
     * @param string $code
     * @param string $message
     */
    public static function mark_error(int $requestid, string $code, string $message): void {
        $req = self::get($requestid);
        if (!$req) {
            return;
        }
        $req->status = self::STATUS_ERROR;
        $req->error_code = substr($code, 0, 32);
        $req->error_message = $message;
        self::save($req);
    }

    /**
     * Registers a PHP shutdown callback so uncatchable fatal errors (OOM, max_execution_time)
     * during heavy backup/download/restore tasks are recorded in the request log instead of leaving
     * the request stuck forever.
     *
     * @param int $requestid
     * @param string $errorcode
     */
    public static function register_fatal_shutdown(int $requestid, string $errorcode = 'FATAL'): void {
        core_shutdown_manager::register_function(function () use ($requestid, $errorcode) {
            $err = error_get_last();
            if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                $req = self::get($requestid);
                if ($req && (int)$req->status !== self::STATUS_COMPLETED && (int)$req->status !== self::STATUS_ERROR) {
                    $msg = 'Fatal PHP Error: ' . ($err['message'] ?? '') . ' in ' . ($err['file'] ?? '') . ':' . ($err['line'] ?? '');
                    self::mark_error($requestid, $errorcode, $msg);
                }
            }
        });
    }

    /**
     * Returns human-readable badge info for a status code.
     *
     * @param int $status
     * @return array ['label' => string, 'class' => string]
     */
    public static function get_status_badge(int $status): array {
        $map = [
            self::STATUS_ERROR => ['label' => get_string('status_error', 'local_iomadtransfer'), 'class' => 'it-badge-danger'],
            self::STATUS_NOT_STARTED => ['label' => get_string('status_not_started', 'local_iomadtransfer'), 'class' => 'it-badge-warning'],
            self::STATUS_IN_PROGRESS => ['label' => get_string('status_in_progress', 'local_iomadtransfer'), 'class' => 'it-badge-info'],
            self::STATUS_BACKUP => ['label' => get_string('status_backup', 'local_iomadtransfer'), 'class' => 'it-badge-info'],
            self::STATUS_DOWNLOAD => ['label' => get_string('status_download', 'local_iomadtransfer'), 'class' => 'it-badge-info'],
            self::STATUS_DOWNLOADED => ['label' => get_string('status_downloaded', 'local_iomadtransfer'), 'class' => 'it-badge-info'],
            self::STATUS_RESTORE => ['label' => get_string('status_restore', 'local_iomadtransfer'), 'class' => 'it-badge-warning'],
            self::STATUS_CONSOLIDATING => ['label' => get_string('status_consolidating', 'local_iomadtransfer'), 'class' => 'it-badge-warning'],
            self::STATUS_COMPLETED => ['label' => get_string('status_completed', 'local_iomadtransfer'), 'class' => 'it-badge-success'],
        ];
        return $map[$status] ?? ['label' => (string)$status, 'class' => 'it-badge-info'];
    }

    /**
     * Collects certificate instances and issued certificates in $courseid across both supported engines:
     * 1. mod_customcert (mdl_customcert + mdl_customcert_issues -> /mod/customcert/verify_certificate.php)
     * 2. Moodle Workplace Course Certificate (mdl_coursecertificate + mdl_tool_certificate_issues -> /admin/tool/certificate/index.php)
     *
     * @param int $courseid
     * @param array|null $migfilter Optional map of [userid => index] to restrict to migrated students
     * @return array ['instances' => int, 'customcert_instances' => int, 'workplace_instances' => int, 'issued' => int, 'items' => array]
     */
    protected static function collect_course_certificates(int $courseid, ?array $migfilter = null): array {
        global $DB;

        $result = [
            'instances' => 0,
            'customcert_instances' => 0,
            'workplace_instances' => 0,
            'issued' => 0,
            'items' => [],
        ];

        if ($courseid <= 1) {
            return $result;
        }

        $dbman = $DB->get_manager();

        // A. Check mod_customcert (mdl_customcert + mdl_customcert_issues).
        try {
            if ($dbman->table_exists('customcert') && $dbman->table_exists('customcert_issues')) {
                $certs = $DB->get_records('customcert', ['course' => $courseid], 'id ASC');
                $result['customcert_instances'] = count($certs);
                $result['instances'] += count($certs);

                foreach ($certs as $cert) {
                    $cm = get_coursemodule_from_instance('customcert', $cert->id, $courseid, false, IGNORE_MISSING);
                    $cmid = $cm ? (int)$cm->id : 0;
                    $modctxid = $cmid > 0 ? (int)context_module::instance($cmid)->id : 0;

                    $sqliss = "SELECT ci.*, u.firstname, u.lastname, u.email
                                 FROM {customcert_issues} ci
                                 JOIN {user} u ON u.id = ci.userid
                                WHERE ci.customcertid = :certid AND u.deleted = 0
                             ORDER BY ci.timecreated DESC";
                    $issues = $DB->get_records_sql($sqliss, ['certid' => $cert->id]);
                    foreach ($issues as $iss) {
                        $iuid = (int)$iss->userid;
                        if ($migfilter !== null && !isset($migfilter[$iuid])) {
                            continue;
                        }
                        $code = trim((string)($iss->code ?? ''));
                        $vparams = ['code' => $code];
                        if ($modctxid > 0) {
                            $vparams['contextid'] = $modctxid;
                        }
                        $vurl = (new moodle_url('/mod/customcert/verify_certificate.php', $vparams))->out(false);

                        $result['issued']++;
                        $result['items'][] = [
                            'plugin' => 'customcert',
                            'pluginlabel' => 'CustomCert',
                            'certid' => (int)$cert->id,
                            'certname' => (string)$cert->name,
                            'cmid' => $cmid,
                            'contextid' => $modctxid,
                            'userid' => $iuid,
                            'fullname' => trim($iss->firstname . ' ' . $iss->lastname),
                            'email' => (string)$iss->email,
                            'code' => $code,
                            'verifyurl' => $vurl,
                            'timecreated' => (int)($iss->timecreated ?? 0),
                        ];
                    }
                }
            }
        } catch (Throwable $e) {
            // Non-fatal: continue.
        }

        // B. Check Moodle Workplace Course Certificate (mdl_coursecertificate + mdl_tool_certificate_issues).
        try {
            $haswpmod = $dbman->table_exists('coursecertificate');
            $haswptool = $dbman->table_exists('tool_certificate_issues');

            if ($haswpmod || $haswptool) {
                $wpcerts = $haswpmod ? $DB->get_records('coursecertificate', ['course' => $courseid], 'id ASC') : [];
                $result['workplace_instances'] = count($wpcerts);
                $result['instances'] += count($wpcerts);

                $defaultcertname = 'Certificado del curso (Workplace)';
                $defaultcmid = 0;
                $defaultctxid = 0;
                $templatecerts = [];
                foreach ($wpcerts as $wpc) {
                    $cm = get_coursemodule_from_instance('coursecertificate', $wpc->id, $courseid, false, IGNORE_MISSING);
                    $cmid = $cm ? (int)$cm->id : 0;
                    $modctxid = $cmid > 0 ? (int)context_module::instance($cmid)->id : 0;
                    if ($defaultcmid === 0) {
                        $defaultcertname = (string)$wpc->name;
                        $defaultcmid = $cmid;
                        $defaultctxid = $modctxid;
                    }
                    $tid = (int)($wpc->template ?? 0);
                    if ($tid > 0) {
                        $templatecerts[$tid] = [
                            'name' => (string)$wpc->name,
                            'cmid' => $cmid,
                            'contextid' => $modctxid,
                        ];
                    }
                }

                if ($haswptool) {
                    $tcols = $DB->get_columns('tool_certificate_issues');
                    $seenissueids = [];
                    $wpissues = [];

                    if (isset($tcols['courseid'])) {
                        $sqlwp = "SELECT tci.*, u.firstname, u.lastname, u.email
                                    FROM {tool_certificate_issues} tci
                                    JOIN {user} u ON u.id = tci.userid
                                   WHERE tci.courseid = :courseid AND u.deleted = 0
                                ORDER BY tci.timecreated DESC";
                        $rows = $DB->get_records_sql($sqlwp, ['courseid' => $courseid]);
                        foreach ($rows as $r) {
                            $seenissueids[(int)$r->id] = true;
                            $wpissues[] = $r;
                        }
                    }

                    if (!empty($templatecerts)) {
                        list($tsql, $tparams) = $DB->get_in_or_equal(array_keys($templatecerts), SQL_PARAMS_NAMED, 'tid');
                        $sqlwpt = "SELECT tci.*, u.firstname, u.lastname, u.email
                                     FROM {tool_certificate_issues} tci
                                     JOIN {user} u ON u.id = tci.userid
                                    WHERE tci.templateid {$tsql} AND u.deleted = 0
                                 ORDER BY tci.timecreated DESC";
                        $trows = $DB->get_records_sql($sqlwpt, $tparams);
                        foreach ($trows as $tr) {
                            if (isset($seenissueids[(int)$tr->id])) {
                                continue;
                            }
                            if (isset($tcols['courseid']) && !empty($tr->courseid) && (int)$tr->courseid !== $courseid) {
                                continue;
                            }
                            $seenissueids[(int)$tr->id] = true;
                            $wpissues[] = $tr;
                        }
                    }

                    foreach ($wpissues as $iss) {
                        $iuid = (int)$iss->userid;
                        if ($migfilter !== null && !isset($migfilter[$iuid])) {
                            continue;
                        }
                        $tid = (int)($iss->templateid ?? 0);
                        $meta = $templatecerts[$tid] ?? [
                            'name' => $defaultcertname,
                            'cmid' => $defaultcmid,
                            'contextid' => $defaultctxid,
                        ];
                        $code = trim((string)($iss->code ?? ''));
                        $vurl = (new moodle_url('/admin/tool/certificate/index.php', ['code' => $code]))->out(false);

                        $result['issued']++;
                        $result['items'][] = [
                            'plugin' => 'coursecertificate',
                            'pluginlabel' => 'Workplace Certificate',
                            'certid' => $tid,
                            'certname' => $meta['name'],
                            'cmid' => $meta['cmid'],
                            'contextid' => $meta['contextid'],
                            'userid' => $iuid,
                            'fullname' => trim($iss->firstname . ' ' . $iss->lastname),
                            'email' => (string)$iss->email,
                            'code' => $code,
                            'verifyurl' => $vurl,
                            'timecreated' => (int)($iss->timecreated ?? 0),
                        ];
                    }
                }
            }
        } catch (Throwable $e) {
            // Non-fatal: continue.
        }

        return $result;
    }

    /**
     * Additively synchronizes IOMAD Company Managers / Department Managers / Educators into the Master Course
     * strictly with the "Client Course Access" role and across ALL groups/subgroups belonging to their company in
     * mdl_company_course_groups (without removing existing groups or affecting other companies), and audits
     * both mod_customcert and Workplace mod_coursecertificate + tool_certificate certificates.
     *
     * @param stdClass $req Record from local_iomadtransfer_request
     * @return array Updated consolidation summary array
     */
    public static function sync_company_managers_and_certificates(stdClass $req): array {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/group/lib.php');
        require_once($CFG->libdir . '/enrollib.php');

        $courseid = (int)($req->target_course_id ?? 0);
        $companyid = (int)($req->companyid ?? 0);
        $groupid = (int)($req->company_groupid ?? 0);

        $sum = !empty($req->consolidate_summary) ? json_decode($req->consolidate_summary, true) : [];
        if (!is_array($sum)) {
            $sum = [];
        }

        if ($courseid <= 1 || !$DB->record_exists('course', ['id' => $courseid])) {
            return $sum;
        }

        $ctx = context_course::instance($courseid);
        $safetimestart = time() - 3600;

        // Ensure course has SEPARATEGROUPS (1) and groupmodeforce (1) so Company Managers can switch between
        // all of their company's subgroups inside course activities and gradebook reports.
        $course = $DB->get_record('course', ['id' => $courseid], 'id, groupmode, groupmodeforce');
        if ($course && ((int)$course->groupmode !== SEPARATEGROUPS || (int)$course->groupmodeforce !== 1)) {
            $course->groupmode = SEPARATEGROUPS;
            $course->groupmodeforce = 1;
            $DB->update_record('course', $course);
        }

        // 1. Collect ALL valid group IDs (main group + any subgroups) registered for ($companyid, $courseid)
        //    in mdl_company_course_groups so Company Managers retain access to every subgroup of their company.
        $companygroupids = [];
        if ($groupid > 0 && $DB->record_exists('groups', ['id' => $groupid, 'courseid' => $courseid])) {
            $companygroupids[$groupid] = $groupid;
        }
        $tblccg = security_manager::resolve_iomad_table('company_course_groups');
        if ($tblccg && $companyid > 0) {
            try {
                $ccgcols = $DB->get_columns($tblccg);
                $compcolccg = isset($ccgcols['companyid']) ? 'companyid' : 'company';
                $ccgrows = $DB->get_records($tblccg, [$compcolccg => $companyid, 'courseid' => $courseid], 'id ASC');
                foreach ($ccgrows as $ccg) {
                    $gid = (int)($ccg->groupid ?? 0);
                    if ($gid > 0 && $DB->record_exists('groups', ['id' => $gid, 'courseid' => $courseid])) {
                        $companygroupids[$gid] = $gid;
                        if ($groupid <= 0) {
                            $groupid = $gid;
                        }
                    }
                }
            } catch (Throwable $e) {
                // Non-fatal.
            }
        }

        // 2. Enrol and assign IOMAD Company Managers & Educators strictly with "Client Course Access".
        $managerssynced = 0;
        $managernames = [];
        if ($companyid > 0 && security_manager::has_iomad()) {
            $tblcu = security_manager::resolve_iomad_table('company_users');
            if ($tblcu) {
                try {
                    $cucols = $DB->get_columns($tblcu);
                    $compcol = isset($cucols['companyid']) ? 'companyid' : 'company';

                    // Resolve the official IOMAD "Client Course Access" role by shortname or name.
                    $rclientaccess = $DB->get_record('role', ['shortname' => 'clientcourseaccess']);
                    if (!$rclientaccess) {
                        $rclientaccess = $DB->get_record('role', ['shortname' => 'companycoursenoneditor']);
                    }
                    if (!$rclientaccess) {
                        $rclientaccess = $DB->get_record_select('role', "LOWER(name) = :name OR LOWER(shortname) = :sname", [
                            'name' => 'client course access',
                            'sname' => 'client course access',
                        ]);
                    }
                    if (!$rclientaccess) {
                        $rclientaccess = $DB->get_record('role', ['shortname' => 'teacher']);
                    }
                    $clientaccessroleid = $rclientaccess ? (int)$rclientaccess->id : 4;

                    // Identify editing roles (clientcourseeditor, companycourseeditor) to unassign if present.
                    $reditor = $DB->get_record('role', ['shortname' => 'clientcourseeditor']);
                    if (!$reditor) {
                        $reditor = $DB->get_record('role', ['shortname' => 'companycourseeditor']);
                    }
                    $editorroleid = $reditor ? (int)$reditor->id : 0;

                    $enrol = $DB->get_record('enrol', ['courseid' => $courseid, 'enrol' => 'manual']);
                    $manualplugin = enrol_get_plugin('manual');

                    $sqlmgr = "SELECT cu.*, u.firstname, u.lastname, u.email
                                 FROM {{$tblcu}} cu
                                 JOIN {user} u ON u.id = cu.userid
                                WHERE cu.{$compcol} = :cid
                                  AND u.deleted = 0
                                  AND (cu.managertype > 0 OR cu.educator > 0)";
                    $mgrrows = $DB->get_records_sql($sqlmgr, ['cid' => $companyid]);

                    foreach ($mgrrows as $mgr) {
                        $muid = (int)$mgr->userid;
                        if ($muid <= 2) {
                            continue;
                        }
                        if (isset($mgr->suspended) && (int)$mgr->suspended !== 0) {
                            continue;
                        }

                        // ALWAYS assign Client Course Access role as requested.
                        $targetroleid = $clientaccessroleid;

                        if (!is_enrolled($ctx, $muid, '', true)) {
                            if ($manualplugin && $enrol) {
                                $manualplugin->enrol_user($enrol, $muid, $targetroleid, $safetimestart, 0, ENROL_USER_ACTIVE);
                            } else {
                                enrol_try_internal_enrol($courseid, $muid, $targetroleid, $safetimestart);
                            }
                        }

                        // Ensure Client Course Access is assigned.
                        if (!user_has_role_assignment($muid, $targetroleid, $ctx->id)) {
                            role_assign($targetroleid, $muid, $ctx->id);
                        }

                        // If user previously had an editing role (Client Course Editor), remove it so they strictly have Client Course Access.
                        if ($editorroleid > 0 && $editorroleid !== $targetroleid && user_has_role_assignment($muid, $editorroleid, $ctx->id)) {
                            role_unassign($editorroleid, $muid, $ctx->id);
                        }

                        // Additively ensure the manager belongs to ALL groups/subgroups of their company in this course.
                        foreach ($companygroupids as $cgid) {
                            if (!$DB->record_exists('groups_members', ['groupid' => $cgid, 'userid' => $muid])) {
                                groups_add_member($cgid, $muid);
                            }
                        }

                        $managerssynced++;
                        $mtype = ((int)($mgr->managertype ?? 0) === 1)
                            ? 'Company Manager'
                            : (((int)($mgr->managertype ?? 0) === 2) ? 'Department Manager' : 'Educator');
                        $managernames[] = trim($mgr->firstname . ' ' . $mgr->lastname) . ' (' . $mtype . ' · Client Course Access)';
                    }
                } catch (Throwable $e) {
                    // Non-fatal: continue.
                }
            }
        }

        $sum['company_managers_synced'] = $managerssynced;
        $sum['company_manager_names'] = $managernames;
        $sum['company_groups_count'] = count($companygroupids);

        // 3. Align Workplace tool_certificate_issues with the course's real imported template (moving any issues
        //    that were mistakenly placed in "Plantilla demo de certificado") and audit certificates.
        $miguserids = !empty($sum['migrated_userids']) && is_array($sum['migrated_userids'])
            ? array_map('intval', $sum['migrated_userids'])
            : [];
        if (method_exists(iomad_consolidator::class, 'align_course_workplace_certificate_issues')) {
            iomad_consolidator::align_course_workplace_certificate_issues($courseid, $miguserids, [], $companyid);
        }
        $migfilter = !empty($miguserids) ? array_flip($miguserids) : null;

        $certdata = self::collect_course_certificates($courseid, $migfilter);
        $sum['customcert_total_instances'] = (int)$certdata['instances'];
        $sum['customcert_instances_count'] = (int)$certdata['customcert_instances'];
        $sum['workplace_instances_count'] = (int)$certdata['workplace_instances'];
        $sum['customcert_issued_count'] = (int)$certdata['issued'];
        $sum['customcert_items'] = $certdata['items'];

        if ($groupid > 0 && (int)$req->company_groupid !== $groupid) {
            $req->company_groupid = $groupid;
        }
        $req->consolidate_summary = json_encode($sum);
        $DB->update_record(self::TABLE, $req);

        return $sum;
    }

    /**
     * Builds an on-demand detailed audit report for a single request in log_detail.php:
     * - Per-student breakdown (name, email, group membership, final course grade, activities completed, certificate codes).
     * - Company Managers enrolled in the Master Course (with Client Course Access) and their company subgroups count.
     * - Certificate verification links and codes for both mod_customcert and Workplace mod_coursecertificate + tool_certificate.
     *
     * @param stdClass $req
     * @return array
     */
    public static function get_detailed_audit_report(stdClass $req): array {
        global $DB;

        $courseid = (int)($req->target_course_id ?? 0);
        $companyid = (int)($req->companyid ?? 0);
        $groupid = (int)($req->company_groupid ?? 0);
        $sum = !empty($req->consolidate_summary) ? json_decode($req->consolidate_summary, true) : [];
        if (!is_array($sum)) {
            $sum = [];
        }

        $report = [
            'students' => [],
            'managers' => $sum['company_manager_names'] ?? [],
            'managers_count' => (int)($sum['company_managers_synced'] ?? 0),
            'company_groups_count' => (int)($sum['company_groups_count'] ?? 0),
            'customcert_instances' => (int)($sum['customcert_total_instances'] ?? 0),
            'customcert_only_instances' => (int)($sum['customcert_instances_count'] ?? 0),
            'workplace_only_instances' => (int)($sum['workplace_instances_count'] ?? 0),
            'customcert_issued' => (int)($sum['customcert_issued_count'] ?? 0),
            'customcert_items' => $sum['customcert_items'] ?? [],
        ];

        if ($courseid <= 1 || !$DB->record_exists('course', ['id' => $courseid])) {
            return $report;
        }

        // Resolve all valid company group IDs for this course so we recognize students placed in any subgroup.
        $companygroupids = [];
        if ($groupid > 0) {
            $companygroupids[$groupid] = $groupid;
        }
        $tblccg = security_manager::resolve_iomad_table('company_course_groups');
        if ($tblccg && $companyid > 0) {
            try {
                $ccgcols = $DB->get_columns($tblccg);
                $compcolccg = isset($ccgcols['companyid']) ? 'companyid' : 'company';
                $ccgrows = $DB->get_records($tblccg, [$compcolccg => $companyid, 'courseid' => $courseid]);
                foreach ($ccgrows as $ccg) {
                    $gid = (int)($ccg->groupid ?? 0);
                    if ($gid > 0) {
                        $companygroupids[$gid] = $gid;
                    }
                }
            } catch (Throwable $e) {
                // Ignore.
            }
        }
        $report['company_groups_count'] = count($companygroupids);

        // Live-query Company Managers on-demand.
        if ($companyid > 0 && security_manager::has_iomad()) {
            $tblcu = security_manager::resolve_iomad_table('company_users');
            if ($tblcu) {
                try {
                    $cucols = $DB->get_columns($tblcu);
                    $compcol = isset($cucols['companyid']) ? 'companyid' : 'company';
                    $sqlmgr = "SELECT cu.userid, cu.managertype, cu.educator, u.firstname, u.lastname
                                 FROM {{$tblcu}} cu
                                 JOIN {user} u ON u.id = cu.userid
                                WHERE cu.{$compcol} = :cid AND u.deleted = 0 AND (cu.managertype > 0 OR cu.educator > 0)";
                    $mgrs = $DB->get_records_sql($sqlmgr, ['cid' => $companyid]);
                    $livemgrs = [];
                    foreach ($mgrs as $m) {
                        $mtype = ((int)$m->managertype === 1)
                            ? 'Company Manager'
                            : (((int)$m->managertype === 2) ? 'Department Manager' : 'Educator');
                        $livemgrs[] = trim($m->firstname . ' ' . $m->lastname) . ' (' . $mtype . ' · Client Course Access)';
                    }
                    $report['managers'] = $livemgrs;
                    $report['managers_count'] = count($livemgrs);
                } catch (Throwable $e) {
                    // Ignore.
                }
            }
        }

        // Live-query both mod_customcert and Workplace mod_coursecertificate + tool_certificate on-demand,
        // automatically aligning any issues that were on the demo template into the course's real template.
        $uids = !empty($sum['migrated_userids']) && is_array($sum['migrated_userids'])
            ? array_map('intval', $sum['migrated_userids'])
            : [];
        if (method_exists(iomad_consolidator::class, 'align_course_workplace_certificate_issues')) {
            iomad_consolidator::align_course_workplace_certificate_issues($courseid, $uids, [], $companyid);
        }
        $migfilter = !empty($uids) ? array_flip($uids) : null;
        $certdata = self::collect_course_certificates($courseid, $migfilter);

        $report['customcert_instances'] = (int)$certdata['instances'];
        $report['customcert_only_instances'] = (int)$certdata['customcert_instances'];
        $report['workplace_only_instances'] = (int)$certdata['workplace_instances'];
        $report['customcert_issued'] = (int)$certdata['issued'];
        $report['customcert_items'] = $certdata['items'];
        $report['workplace_templates'] = [];
        $report['has_missing_workplace_template'] = false;

        try {
            $dbman = $DB->get_manager();
            if ($dbman->table_exists('coursecertificate')) {
                $hastpltbl = $dbman->table_exists('tool_certificate_templates');
                $wpcrows = $DB->get_records('coursecertificate', ['course' => $courseid], 'id ASC');
                foreach ($wpcrows as $wpc) {
                    $tid = (int)($wpc->template ?? 0);
                    $tplrec = ($tid > 0 && $hastpltbl)
                        ? $DB->get_record('tool_certificate_templates', ['id' => $tid])
                        : null;
                    if (!$tplrec) {
                        $report['has_missing_workplace_template'] = true;
                    }
                    $report['workplace_templates'][] = [
                        'activity_name' => (string)$wpc->name,
                        'template_id' => $tid,
                        'exists' => (bool)$tplrec,
                        'template_name' => $tplrec ? (string)$tplrec->name : '',
                    ];
                }
            }
        } catch (Throwable $e) {
            // Non-fatal.
        }

        $certbyuser = [];
        foreach ($report['customcert_items'] as $ci) {
            $uid = (int)($ci['userid'] ?? 0);
            if ($uid > 0) {
                $certbyuser[$uid][] = $ci;
            }
        }

        // Per-student detailed audit rows.
        if (!empty($uids)) {
            $coursegi = $DB->get_record('grade_items', ['courseid' => $courseid, 'itemtype' => 'course']);
            foreach ($uids as $uid) {
                if ($uid <= 2) {
                    continue;
                }
                $u = $DB->get_record('user', ['id' => $uid, 'deleted' => 0], 'id, username, firstname, lastname, email');
                if (!$u) {
                    continue;
                }
                $ingroup = false;
                foreach ($companygroupids as $cgid) {
                    if ($DB->record_exists('groups_members', ['groupid' => $cgid, 'userid' => $uid])) {
                        $ingroup = true;
                        break;
                    }
                }
                $finalgrade = null;
                if ($coursegi) {
                    $gg = $DB->get_record('grade_grades', ['itemid' => $coursegi->id, 'userid' => $uid]);
                    if ($gg && $gg->finalgrade !== null) {
                        $finalgrade = round((float)$gg->finalgrade, 1) . ' / ' . round((float)$coursegi->grademax, 0);
                    }
                }
                $compcount = (int)$DB->count_records_sql(
                    "SELECT COUNT(cmc.id)
                       FROM {course_modules_completion} cmc
                       JOIN {course_modules} cm ON cm.id = cmc.coursemoduleid
                      WHERE cm.course = :cid AND cmc.userid = :uid AND cmc.completionstate > 0",
                    ['cid' => $courseid, 'uid' => $uid]
                );
                $report['students'][] = [
                    'userid' => $uid,
                    'fullname' => trim($u->firstname . ' ' . $u->lastname),
                    'username' => (string)$u->username,
                    'email' => (string)$u->email,
                    'in_group' => $ingroup,
                    'final_grade' => $finalgrade ?? 'Sin nota',
                    'completions' => $compcount,
                    'certificates' => $certbyuser[$uid] ?? [],
                ];
            }
        }

        return $report;
    }
}
