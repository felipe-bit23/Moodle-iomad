<?php
// This file is part of the mod_coursecertificate plugin for Moodle - http://moodle.org/
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
 * The helper for the Coursecertificate module with Multi-Tenant IOMAD & Group Template Resolution.
 *
 * @package     mod_coursecertificate
 * @copyright   2020 Mikel Martín <mikel@moodle.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_coursecertificate;

use context_coursecat;
use context_system;
use core_availability\info_module;
use Throwable;
use tool_certificate\certificate;
use tool_certificate\template;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/grade/querylib.php');
require_once($CFG->libdir . '/gradelib.php');

/**
 * The helper for the Coursecertificate module.
 *
 * @package     mod_coursecertificate
 * @copyright   2020 Mikel Martín <mikel@moodle.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class helper {

    /**
     * Detects and returns the currently active IOMAD Company ID from the session, request parameters,
     * theme functions (e.g. Trema navbar selector), or IOMAD core APIs.
     *
     * @return int Company ID, or 0 if none is active.
     */
    public static function get_active_iomad_company_id(): int {
        global $SESSION, $USER, $DB;

        // 1. Explicit request parameter (URL param company or companyid).
        $paramcompany = optional_param('company', 0, PARAM_INT);
        if ($paramcompany <= 0) {
            $paramcompany = optional_param('companyid', 0, PARAM_INT);
        }
        if ($paramcompany > 0) {
            if (isset($SESSION)) {
                $SESSION->currenteditingcompany = $paramcompany;
            }
            return $paramcompany;
        }

        // 2. IOMAD active editing company stored in session (set when user changes company in the navbar).
        if (!empty($SESSION->currenteditingcompany) && (int)$SESSION->currenteditingcompany > 0) {
            return (int)$SESSION->currenteditingcompany;
        }

        // 3. Theme Trema or similar custom theme navbar company accessor.
        if (function_exists('theme_trema_get_active_companyid')) {
            $tremacompany = (int)theme_trema_get_active_companyid();
            if ($tremacompany > 0) {
                if (isset($SESSION)) {
                    $SESSION->currenteditingcompany = $tremacompany;
                }
                return $tremacompany;
            }
        }

        // 4. IOMAD core API check.
        if (class_exists('\iomad') && method_exists('\iomad', 'get_my_companyid')) {
            try {
                $iomadcompany = (int)\iomad::get_my_companyid(\context_system::instance(), false);
                if ($iomadcompany > 0) {
                    return $iomadcompany;
                }
            } catch (Throwable $e) {
                // Non-fatal.
            }
        }

        // 5. If user is a standard company manager or company user, fallback to their primary company.
        if (!empty($USER->id) && !is_siteadmin($USER->id) && $DB->get_manager()->table_exists('company_users')) {
            try {
                $cu = $DB->get_record('company_users', ['userid' => (int)$USER->id, 'suspended' => 0], 'companyid');
                if ($cu && !empty($cu->companyid)) {
                    return (int)$cu->companyid;
                }
            } catch (Throwable $e) {
                // Non-fatal.
            }
        }

        return 0;
    }

    /**
     * Searches tool_certificate_templates for a template matching any of the candidate company/group names
     * (supporting "Certificado - {Nombre}", "Certificado {Nombre}", and "{Nombre}" case-insensitively)
     * or residing inside the given course category context.
     *
     * @param string[] $names Candidate company/group names or shortnames
     * @param int $categoryid Optional IOMAD company course category ID
     * @return int Matched template ID or 0 if none found
     */
    public static function find_matching_template_id(array $names, int $categoryid = 0): int {
        global $DB;
        if (!$DB->get_manager()->table_exists('tool_certificate_templates')) {
            return 0;
        }

        try {
            // 1. Match by "Certificado - {Name}", "Certificado {Name}", or exact "{Name}" (case-insensitive).
            $expanded = [];
            foreach ($names as $rawname) {
                $clean = trim((string)$rawname);
                if ($clean === '') {
                    continue;
                }
                $expanded[] = 'Certificado - ' . $clean;
                $expanded[] = 'Certificado ' . $clean;
                $expanded[] = $clean;
            }

            foreach ($expanded as $cand) {
                $matches = $DB->get_records_select(
                    'tool_certificate_templates',
                    'LOWER(TRIM(name)) = LOWER(:tname)',
                    ['tname' => $cand],
                    'id DESC',
                    'id',
                    0,
                    1
                );
                if (!empty($matches)) {
                    $tpl = reset($matches);
                    return (int)$tpl->id;
                }
            }

            // 2. If the IOMAD company has a dedicated course category, check if a template lives in that category context.
            if ($categoryid > 0 && $DB->record_exists('course_categories', ['id' => $categoryid])) {
                $catctx = context_coursecat::instance($categoryid, IGNORE_MISSING);
                if ($catctx) {
                    $catmatches = $DB->get_records(
                        'tool_certificate_templates',
                        ['contextid' => $catctx->id],
                        'id DESC',
                        'id',
                        0,
                        1
                    );
                    if (!empty($catmatches)) {
                        $tpl = reset($catmatches);
                        return (int)$tpl->id;
                    }
                }
            }
        } catch (Throwable $e) {
            // Non-fatal: fall through to default template.
        }

        return 0;
    }

    /**
     * Resolves the effective tool_certificate_templates ID for a specific user in a course
     * following the 3-level Multi-Tenant IOMAD & Course Group cascade:
     * - Priority 1: Template matching the user's Course Group / Subgroup name or idnumber in $courseid,
     *               or the IOMAD Company bound to that group in mdl_company_course_groups
     *               (supports "Certificado - {Company}", "Certificado {Company}", and "{Company}").
     * - Priority 2: Template matching the user's IOMAD Company (name, shortname, or company category context)
     *               in mdl_company_users (covers manual IOMAD course assignments and subgroups).
     * - Priority 3 (Fallback): The default template configured on the coursecertificate activity ($defaulttemplateid),
     *               verified to exist in mdl_tool_certificate_templates (returns 0 if missing).
     *
     * @param int $userid
     * @param int $courseid
     * @param int $defaulttemplateid
     * @return int Resolved template ID or 0 if no valid template exists
     */
    public static function resolve_user_template_id(int $userid, int $courseid, int $defaulttemplateid): int {
        global $DB;

        if ($userid > 0 && $courseid > 0) {
            try {
                $dbman = $DB->get_manager();
                $hascompany = $dbman->table_exists('company');
                $hasccg = $dbman->table_exists('company_course_groups');
                $hascu = $dbman->table_exists('company_users');
                $hascc = $dbman->table_exists('company_course');

                // Priority 1: Check the user's groups in this course (mdl_groups_members -> mdl_groups).
                $sqlgrp = "SELECT g.id, g.name, g.idnumber
                             FROM {groups} g
                             JOIN {groups_members} gm ON gm.groupid = g.id
                            WHERE g.courseid = :courseid AND gm.userid = :userid
                         ORDER BY g.id DESC";
                $usergroups = $DB->get_records_sql($sqlgrp, ['courseid' => $courseid, 'userid' => $userid]);

                foreach ($usergroups as $grp) {
                    // 1A. Direct match by group name or group idnumber.
                    $candidates = [(string)$grp->name];
                    if (!empty($grp->idnumber)) {
                        $candidates[] = (string)$grp->idnumber;
                    }
                    $matched = self::find_matching_template_id($candidates);
                    if ($matched > 0) {
                        return $matched;
                    }

                    // 1B. If the group belongs to an IOMAD company (even if it's a subgroup like "Empresa - Ventas"),
                    //     check the parent IOMAD company's name, shortname, and category context.
                    if ($hascompany && $hasccg) {
                        $ccg = $DB->get_record('company_course_groups', [
                            'courseid' => $courseid,
                            'groupid' => (int)$grp->id,
                        ]);
                        if ($ccg && !empty($ccg->companyid)) {
                            $comp = $DB->get_record('company', ['id' => (int)$ccg->companyid]);
                            if ($comp) {
                                $compnames = [(string)$comp->name, (string)($comp->shortname ?? '')];
                                $compcat = (int)($comp->category ?? 0);
                                $matched = self::find_matching_template_id($compnames, $compcat);
                                if ($matched > 0) {
                                    return $matched;
                                }
                            }
                        }
                    }
                }

                // Priority 2: Check the user's IOMAD company in mdl_company_users (prioritizing companies
                // assigned to $courseid in mdl_company_course).
                if ($hascompany && $hascu) {
                    $sqlcomp = "SELECT c.id, c.name, c.shortname, c.category,
                                       (CASE WHEN cc.id IS NOT NULL THEN 0 ELSE 1 END) AS coursepriority
                                  FROM {company_users} cu
                                  JOIN {company} c ON c.id = cu.companyid
                             LEFT JOIN {company_course} cc ON cc.companyid = c.id AND cc.courseid = :courseid
                                 WHERE cu.userid = :userid AND cu.suspended = 0
                              ORDER BY coursepriority ASC, cu.id DESC";
                    if (!$hascc) {
                        $sqlcomp = "SELECT c.id, c.name, c.shortname, c.category
                                      FROM {company_users} cu
                                      JOIN {company} c ON c.id = cu.companyid
                                     WHERE cu.userid = :userid AND cu.suspended = 0
                                  ORDER BY cu.id DESC";
                    }
                    $usercompanies = $DB->get_records_sql($sqlcomp, ['courseid' => $courseid, 'userid' => $userid]);
                    foreach ($usercompanies as $comp) {
                        $compnames = [(string)$comp->name, (string)($comp->shortname ?? '')];
                        $compcat = (int)($comp->category ?? 0);
                        $matched = self::find_matching_template_id($compnames, $compcat);
                        if ($matched > 0) {
                            return $matched;
                        }
                    }
                }
            } catch (Throwable $e) {
                // Non-fatal: fall through to default template.
            }
        }

        // Priority 3 (Safe Fallback): Return the default template configured on the course activity
        // ONLY if it physically exists in mdl_tool_certificate_templates (never return a dangling ID).
        if ($defaulttemplateid > 0 &&
            $DB->get_manager()->table_exists('tool_certificate_templates') &&
            $DB->record_exists('tool_certificate_templates', ['id' => $defaulttemplateid])) {
            return $defaulttemplateid;
        }

        return 0;
    }

    /**
     * Ensures that all existing certificate issues in $courseid belonging to members of $groupid
     * are aligned with $targettemplateid so the group's report table always displays 100% of its certified members.
     *
     * @param int $courseid
     * @param int $groupid
     * @param int $targettemplateid
     */
    protected static function align_group_issues_to_template(int $courseid, int $groupid, int $targettemplateid): void {
        global $DB;
        if ($courseid <= 1 || $groupid <= 0 || $targettemplateid <= 0) {
            return;
        }
        try {
            $sql = "SELECT ci.*
                      FROM {tool_certificate_issues} ci
                      JOIN {groups_members} gm ON gm.userid = ci.userid
                     WHERE ci.courseid = :courseid
                       AND gm.groupid = :groupid
                       AND ci.archived = 0
                       AND ci.templateid <> :targettid";
            $misaligned = $DB->get_records_sql($sql, [
                'courseid' => $courseid,
                'groupid' => $groupid,
                'targettid' => $targettemplateid,
            ]);
            if (empty($misaligned)) {
                return;
            }
            $fs = get_file_storage();
            $syscontextid = (int)context_system::instance()->id;
            foreach ($misaligned as $iss) {
                $iss->templateid = $targettemplateid;
                $iss->component = 'mod_coursecertificate';
                $DB->update_record('tool_certificate_issues', $iss);
                try {
                    $fs->delete_area_files($syscontextid, 'tool_certificate', 'issues', (int)$iss->id);
                } catch (Throwable $ignored) {
                    // Ignore file cleanup warnings.
                }
            }
        } catch (Throwable $e) {
            // Non-fatal.
        }
    }

    /**
     * Resolves the template ID to display in the report table when a teacher/Admin/Company Manager filters by $groupid.
     * Guarantees the returned template ID exists in mdl_tool_certificate_templates (or returns 0).
     *
     * @param int $groupid
     * @param int $courseid
     * @param int $defaulttemplateid
     * @param int $vieweruserid
     * @return int
     */
    public static function resolve_group_template_id(
        int $groupid,
        int $courseid,
        int $defaulttemplateid,
        int $vieweruserid = 0
    ): int {
        global $DB;

        try {
            $dbman = $DB->get_manager();
            $hascompany = $dbman->table_exists('company');
            $hasccg = $dbman->table_exists('company_course_groups');
            $hasissues = $dbman->table_exists('tool_certificate_issues');
            $hastpls = $dbman->table_exists('tool_certificate_templates');

            if ($groupid > 0) {
                $matched = 0;
                $grp = $DB->get_record('groups', ['id' => $groupid, 'courseid' => $courseid]);
                if ($grp) {
                    $candidates = [(string)$grp->name];
                    if (!empty($grp->idnumber)) {
                        $candidates[] = (string)$grp->idnumber;
                    }
                    $matched = self::find_matching_template_id($candidates);
                }
                if ($matched <= 0 && $hascompany && $hasccg) {
                    $ccg = $DB->get_record('company_course_groups', ['courseid' => $courseid, 'groupid' => $groupid]);
                    if ($ccg && !empty($ccg->companyid)) {
                        $comp = $DB->get_record('company', ['id' => (int)$ccg->companyid]);
                        if ($comp) {
                            $matched = self::find_matching_template_id(
                                [(string)$comp->name, (string)($comp->shortname ?? '')],
                                (int)($comp->category ?? 0)
                            );
                        }
                    }
                }

                if ($matched > 0) {
                    if ($hasissues) {
                        self::align_group_issues_to_template($courseid, $groupid, $matched);
                    }
                    return $matched;
                }
            }

            // Multi-Tenant IOMAD Navbar Company Resolution:
            // If active company is selected in the navbar (or group did not match a specific template),
            // resolve template directly using the active navbar company.
            $navbarcompid = self::get_active_iomad_company_id();
            if ($navbarcompid > 0 && $hascompany) {
                $comp = $DB->get_record('company', ['id' => $navbarcompid]);
                if ($comp) {
                    $compnames = [(string)$comp->name, (string)($comp->shortname ?? '')];
                    $compcat = (int)($comp->category ?? 0);
                    $matched = self::find_matching_template_id($compnames, $compcat);
                    if ($matched > 0) {
                        if ($groupid > 0 && $hasissues) {
                            self::align_group_issues_to_template($courseid, $groupid, $matched);
                        } else if ($hasccg && $hasissues) {
                            $ccg = $DB->get_record('company_course_groups', [
                                'companyid' => $navbarcompid,
                                'courseid' => $courseid,
                            ]);
                            if ($ccg && !empty($ccg->groupid)) {
                                self::align_group_issues_to_template($courseid, (int)$ccg->groupid, $matched);
                            }
                        }
                        return $matched;
                    }
                }

                // If not matched by name, check if members of this navbar company have issues under a template.
                if ($hasissues && $hastpls) {
                    $sqlcompissues = "SELECT ci.templateid, COUNT(ci.id) AS issuecnt
                                        FROM {tool_certificate_issues} ci
                                        JOIN {company_users} cu ON cu.userid = ci.userid
                                        JOIN {tool_certificate_templates} ct ON ct.id = ci.templateid
                                       WHERE ci.courseid = :courseid
                                         AND cu.companyid = :navbarcompid
                                         AND ci.archived = 0
                                    GROUP BY ci.templateid
                                    ORDER BY issuecnt DESC, ci.templateid DESC";
                    $topcomp = $DB->get_records_sql($sqlcompissues, [
                        'courseid' => $courseid,
                        'navbarcompid' => $navbarcompid,
                    ], 0, 1);
                    if (!empty($topcomp)) {
                        $firstcomp = reset($topcomp);
                        return (int)$firstcomp->templateid;
                    }
                }
            }

            if ($groupid > 0) {
                // If this group does not have a dedicated template by name yet, check if members of this group
                // already have issued certificates in this course under a valid template!
                if ($hasissues && $hastpls) {
                    $sqlgrpissues = "SELECT ci.templateid, COUNT(ci.id) AS issuecnt
                                       FROM {tool_certificate_issues} ci
                                       JOIN {groups_members} gm ON gm.userid = ci.userid
                                       JOIN {tool_certificate_templates} ct ON ct.id = ci.templateid
                                      WHERE ci.courseid = :courseid
                                        AND gm.groupid = :groupid
                                        AND ci.archived = 0
                                   GROUP BY ci.templateid
                                   ORDER BY issuecnt DESC, ci.templateid DESC";
                    $topgrp = $DB->get_records_sql($sqlgrpissues, [
                        'courseid' => $courseid,
                        'groupid' => $groupid,
                    ], 0, 1);
                    if (!empty($topgrp)) {
                        $firstgrp = reset($topgrp);
                        return (int)$firstgrp->templateid;
                    }
                }
            }

            if ($vieweruserid > 0 && !is_siteadmin($vieweruserid)) {
                $viewerresolved = self::resolve_user_template_id($vieweruserid, $courseid, $defaulttemplateid);
                if ($viewerresolved > 0) {
                    return $viewerresolved;
                }
            }

            // If $defaulttemplateid has 0 issues in this course while another valid template has active issues, use it.
            if ($courseid > 0 && $hasissues && $hastpls) {
                $hasdefaultissues = $defaulttemplateid > 0 && $DB->record_exists('tool_certificate_issues', [
                    'courseid' => $courseid,
                    'templateid' => $defaulttemplateid,
                    'archived' => 0,
                ]);
                if (!$hasdefaultissues) {
                    $sqlactive = "SELECT ci.templateid, COUNT(ci.id) AS issuecnt
                                    FROM {tool_certificate_issues} ci
                                    JOIN {tool_certificate_templates} ct ON ct.id = ci.templateid
                                   WHERE ci.courseid = :courseid AND ci.archived = 0
                                GROUP BY ci.templateid
                                ORDER BY issuecnt DESC, ci.templateid DESC";
                    $top = $DB->get_records_sql($sqlactive, ['courseid' => $courseid], 0, 1);
                    if (!empty($top)) {
                        $first = reset($top);
                        return (int)$first->templateid;
                    }
                }
            }
        } catch (Throwable $e) {
            // Fall through to default template check.
        }

        if ($defaulttemplateid > 0 &&
            $DB->get_manager()->table_exists('tool_certificate_templates') &&
            $DB->record_exists('tool_certificate_templates', ['id' => $defaulttemplateid])) {
            return $defaulttemplateid;
        }

        return 0;
    }

    /**
     * Gets users who meet access restrictions and had not been issued a certificate in this course.
     *
     * @param \stdClass $coursecertificate
     * @param \cm_info $cm
     * @return array
     */
    public static function get_users_to_issue(\stdClass $coursecertificate, \cm_info $cm): array {
        global $DB;

        $context = \context_course::instance($coursecertificate->course);
        // Get users already issued subquery (across any template in this course so company templates are respected).
        [$usersissuedsql, $usersissuedparams] = self::get_users_issued_select(
            $coursecertificate->course,
            $coursecertificate->template
        );
        // Get users enrolled with receive capabilities subquery.
        [$enrolledsql, $enrolledparams] = get_enrolled_sql($context, 'mod/coursecertificate:receive', 0, true);
        $sql = "SELECT eu.id FROM ($enrolledsql) eu WHERE eu.id NOT IN ($usersissuedsql)";
        $params = array_merge($enrolledparams, $usersissuedparams);
        $potentialusers = $DB->get_records_sql($sql, $params);

        // Filter only users with access to the activity.
        $info = new info_module($cm);
        $filteredusers = $info->filter_user_list($potentialusers);

        $users = [];
        foreach ($filteredusers as $filtereduser) {
            $modinfouser = get_fast_modinfo($cm->get_course(), $filtereduser->id);
            $cmuser = $modinfouser->get_cms()[$cm->id] ?? null;
            if ($cmuser && $cmuser->uservisible && $cmuser->available) {
                $users[] = $filtereduser;
            }
        }
        return $users;
    }

    /**
     * Returns the record for the certificate user has in a given course.
     * Resolves the user's company/group template first, and falls back to any active certificate in the course.
     *
     * @param int $userid
     * @param int $courseid
     * @param int $templateid
     * @return \stdClass|null
     */
    public static function get_user_certificate(int $userid, int $courseid, int $templateid): ?\stdClass {
        global $DB;

        $resolvedtemplateid = self::resolve_user_template_id($userid, $courseid, $templateid);

        if ($resolvedtemplateid > 0) {
            // 1. Check for an issue matching the resolved company/group template.
            $sql = "SELECT *
                      FROM {tool_certificate_issues} ci
                     WHERE component = :component
                       AND courseid = :courseid
                       AND templateid = :templateid
                       AND userid = :userid
                       AND archived = 0
                  ORDER BY id DESC";
            $params = [
                'component' => 'mod_coursecertificate',
                'courseid' => $courseid,
                'templateid' => $resolvedtemplateid,
                'userid' => $userid,
            ];
            $records = $DB->get_records_sql($sql, $params);
            if (!empty($records)) {
                return reset($records);
            }
        }

        // 2. Fallback: check if the user already has an active certificate in this course under any valid template
        //    (e.g., issued prior to company template creation or migrated via local_iomadtransfer).
        $sqlany = "SELECT ci.*
                     FROM {tool_certificate_issues} ci
                     JOIN {tool_certificate_templates} ct ON ct.id = ci.templateid
                    WHERE ci.component = :component
                      AND ci.courseid = :courseid
                      AND ci.userid = :userid
                      AND ci.archived = 0
                 ORDER BY ci.id DESC";
        $anyrecords = $DB->get_records_sql($sqlany, [
            'component' => 'mod_coursecertificate',
            'courseid' => $courseid,
            'userid' => $userid,
        ]);
        return !empty($anyrecords) ? reset($anyrecords) : null;
    }

    /**
     * Issue a course certificate to the user if they don't already have one, automatically
     * selecting the user's IOMAD company / group template (or falling back to the course default template).
     *
     * @param \stdClass $user
     * @param \stdClass $coursecertificate
     * @param \stdClass|null $course course record, if known (to save on retrieving one)
     * @param template|null $template template, if known (for performance reasons when called in a loop)
     * @return int id of the certificate issue or 0 if user already had an issued certificate
     */
    public static function issue_certificate(
        \stdClass $user,
        \stdClass $coursecertificate,
        ?\stdClass $course = null,
        ?template $template = null
    ): int {
        $targettemplateid = self::resolve_user_template_id(
            (int)$user->id,
            (int)$coursecertificate->course,
            (int)$coursecertificate->template
        );
        if ($targettemplateid <= 0) {
            return 0;
        }

        $lockfactory = \core\lock\lock_config::get_lock_factory('mod_coursecertificate_issue');
        $lock = $lockfactory->get_lock("i_{$user->id}_{$targettemplateid}_{$coursecertificate->course}", MINSECS);
        if (!$lock) {
            throw new \moodle_exception('locktimeout');
        }

        try {
            if (self::get_user_certificate($user->id, $coursecertificate->course, $targettemplateid)) {
                // If user already has a certificate in this course - do not issue a new one.
                return 0;
            }

            $course = $course ?? get_course($coursecertificate->course);
            if ($template === null || (int)$template->get_id() !== $targettemplateid) {
                $template = template::instance($targettemplateid);
            }
            $issuedata = self::get_issue_data($course, $user, $coursecertificate);
            $expirydate = certificate::calculate_expirydate(
                $coursecertificate->expirydatetype,
                $coursecertificate->expirydateoffset,
                $coursecertificate->expirydateoffset
            );
            return $template->issue_certificate(
                $user->id,
                $expirydate,
                $issuedata,
                'mod_coursecertificate',
                $course->id,
                $lock
            );
        } finally {
            $lock->release();
        }
    }

    /**
     * Returns select for the users that have already been issued a certificate in this course
     * (across any template, preventing duplicate cron emissions when company templates are used).
     *
     * @param int $courseid
     * @param int $templateid
     * @return array
     */
    private static function get_users_issued_select(int $courseid, int $templateid): array {
        $sql = "SELECT DISTINCT ci.userid
                  FROM {tool_certificate_issues} ci
                 WHERE component = :component
                   AND courseid = :courseid
                   AND archived = 0";
        $params = [
            'component' => 'mod_coursecertificate',
            'courseid' => $courseid,
        ];
        return [$sql, $params];
    }

    /**
     * Get data for the issue. Important course fields (id, shortname, fullname and URL) and course customfields.
     *
     * @param \stdClass $course
     * @param \stdClass $user
     * @param \stdClass|null $coursecertificate
     * @return array
     */
    public static function get_issue_data(\stdClass $course, \stdClass $user, ?\stdClass $coursecertificate = null): array {
        global $DB;

        // Get user course completion date.
        $result = $DB->get_field(
            'course_completions',
            'timecompleted',
            ['course' => $course->id, 'userid' => $user->id]
        );
        $completiondate = $result ? userdate($result, get_string('strftimedatefullshort')) : '';

        // Get user course grade.
        $grade = grade_get_course_grade($user->id, $course->id);
        if ($grade && $grade->grade) {
            $gradestr = $grade->str_grade;
        }

        $sectionname = '';
        $activityname = '';
        if ($coursecertificate) {
            $cm = get_coursemodule_from_instance('coursecertificate', $coursecertificate->id, $course->id);
            if ($cm) {
                // 1. Check if certificate is conditioned on another activity in availability restrictions.
                if (!empty($cm->availability)) {
                    $avail = json_decode($cm->availability, true);
                    if (is_array($avail)) {
                        $findreqactivity = function ($node) use (&$findreqactivity, $course) {
                            if (!is_array($node)) {
                                return '';
                            }
                            if (isset($node['type']) && $node['type'] === 'completion' && !empty($node['cm'])) {
                                try {
                                    $modinfo = get_fast_modinfo($course);
                                    if (isset($modinfo->cms[$node['cm']])) {
                                        return $modinfo->cms[$node['cm']]->name;
                                    }
                                } catch (\Throwable $e) {
                                    // Ignore.
                                }
                            }
                            if (isset($node['type']) && $node['type'] === 'grade' && !empty($node['id'])) {
                                global $DB;
                                $gi = $DB->get_record('grade_items', ['id' => $node['id'], 'courseid' => $course->id]);
                                if ($gi && !empty($gi->itemname)) {
                                    return $gi->itemname;
                                }
                            }
                            if (!empty($node['c']) && is_array($node['c'])) {
                                foreach ($node['c'] as $child) {
                                    $res = $findreqactivity($child);
                                    if (!empty($res)) {
                                        return $res;
                                    }
                                }
                            }
                            return '';
                        };
                        $activityname = $findreqactivity($avail);
                    }
                }

                // 2. Fallback to certificate activity name itself.
                if (empty($activityname)) {
                    $activityname = $coursecertificate->name;
                }

                // 3. Resolve section name.
                if (!empty($cm->section)) {
                    $section = $DB->get_record('course_sections', ['id' => $cm->section]);
                    if ($section) {
                        $sectionname = get_section_name($course, $section);
                    }
                }
            } else {
                $activityname = $coursecertificate->name;
            }
        }

        $issuedata = [
            'courseid' => $course->id,
            'courseshortname' => $course->shortname,
            'coursefullname' => $course->fullname,
            'courseurl' => course_get_url($course)->out(),
            'coursecompletiondate' => $completiondate,
            'coursegrade' => $gradestr ?? '',
            'coursesectionname' => $sectionname,
            'courseactivityname' => $activityname,
        ];
        // Add course custom fields data.
        $handler = \core_course\customfield\course_handler::create();
        foreach ($handler->get_instance_data($course->id, true) as $data) {
            $issuedata['coursecustomfield_' . $data->get_field()->get('shortname')] = $data->export_value();
        }

        return $issuedata;
    }
}
