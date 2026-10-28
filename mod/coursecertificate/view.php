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
 * Prints an instance of mod_coursecertificate (with Multi-Tenant Bulk ZIP Download per Group/Company).
 *
 * @package     mod_coursecertificate
 * @copyright   2020 Mikel Martín <mikel@moodle.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__.'/../../config.php');

global $PAGE, $USER, $CFG, $DB;

$id = required_param('id', PARAM_INT);
$page = optional_param('page', 0, PARAM_INT);
$perpage = optional_param('perpage', 10, PARAM_INT);
$download = optional_param('download', false, PARAM_BOOL);
$downloadall = optional_param('downloadall', false, PARAM_BOOL);
$explicitgroup = optional_param('group', 0, PARAM_INT);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'coursecertificate');

require_course_login($course, true, $cm);
$context = \context_module::instance($cm->id);

if ($downloadall) {
    require_capability('mod/coursecertificate:viewreport', $context);

    \core_php_time_limit::raise(300);
    raise_memory_limit(MEMORY_EXTRA);

    $certificate = $DB->get_record('coursecertificate', ['id' => $cm->instance], '*', MUST_EXIST);

    $canaccessall = has_capability('moodle/site:accessallgroups', $context);
    $groupid = $explicitgroup > 0 ? $explicitgroup : (int) groups_get_activity_group($cm, true);

    // Multi-Tenant IOMAD Navbar Company Synchronization for bulk download:
    $navbarcompid = \mod_coursecertificate\helper::get_active_iomad_company_id();
    if ($navbarcompid > 0 && $DB->get_manager()->table_exists('company_course_groups')) {
        $navbarccg = $DB->get_record('company_course_groups', [
            'companyid' => $navbarcompid,
            'courseid' => $course->id,
        ]);
        if ($navbarccg && !empty($navbarccg->groupid)) {
            if ($groupid === 0 || ($explicitgroup === 0 && $groupid !== (int)$navbarccg->groupid)) {
                $groupid = (int)$navbarccg->groupid;
            }
        }
    }

    $groupparams = ['component' => 'mod_coursecertificate', 'courseid' => $course->id];
    $groupsql = '';
    $groupnameSuffix = '';

    // Multi-Tenant Isolation Guarantee:
    // If user does not have 'accessallgroups' (e.g. IOMAD Company Manager with Client Course Access),
    // they are strictly restricted to the groups they belong to in this course!
    if (!$canaccessall) {
        $allowedgroups = groups_get_activity_allowed_groups($cm);
        $allowedgroupids = array_map('intval', array_keys($allowedgroups));

        if (empty($allowedgroupids)) {
            // Manager belongs to no groups in this course: strictly zero records returned.
            $groupsql = ' AND 1 = 0';
        } else if ($groupid && in_array($groupid, $allowedgroupids, true)) {
            // Specific allowed group selected in the dropdown.
            $groupsql = ' AND ci.userid IN (SELECT gm.userid FROM {groups_members} gm WHERE gm.groupid = :groupid)';
            $groupparams['groupid'] = $groupid;
            if (isset($allowedgroups[$groupid])) {
                $groupnameSuffix = '_' . $allowedgroups[$groupid]->name;
            }
        } else {
            // "All participants" or no group selected: restricted strictly to all allowed groups of their company!
            list($gsql, $gparams) = $DB->get_in_or_equal($allowedgroupids, SQL_PARAMS_NAMED, 'gid');
            $groupsql = " AND ci.userid IN (SELECT gm.userid FROM {groups_members} gm WHERE gm.groupid $gsql)";
            $groupparams = array_merge($groupparams, $gparams);
            $firstgrp = reset($allowedgroups);
            if ($firstgrp) {
                $groupnameSuffix = '_' . $firstgrp->name;
            }
        }
    } else if ($groupid) {
        // Admin or user with accessallgroups selecting a specific company group filter.
        $groupsql = ' AND ci.userid IN (SELECT gm.userid FROM {groups_members} gm WHERE gm.groupid = :groupid)';
        $groupparams['groupid'] = $groupid;
        $grprec = $DB->get_record('groups', ['id' => $groupid], 'id, name');
        if ($grprec) {
            $groupnameSuffix = '_' . $grprec->name;
        }
    } else if ($navbarcompid > 0) {
        // Admin with a navbar company active, even if no group exists in mdl_company_course_groups:
        $groupsql = ' AND ci.userid IN (SELECT cu.userid FROM {company_users} cu WHERE cu.companyid = :navbarcompid AND cu.suspended = 0)';
        $groupparams['navbarcompid'] = $navbarcompid;
        $comp = $DB->get_record('company', ['id' => $navbarcompid], 'id, name');
        if ($comp) {
            $groupnameSuffix = '_' . $comp->name;
        }
    }

    // Include all user name fields required by Moodle 4.5 fullname() to prevent any debugging notices.
    $userfields = \core_user\fields::for_name()->get_sql('u', false, '', '', false)->selects;

    // Fetch active certificates issued in this course (strictly isolated by company groups).
    $sql = "SELECT ci.*, u.username, u.idnumber, $userfields
              FROM {tool_certificate_issues} ci
              JOIN {user} u ON u.id = ci.userid
             WHERE ci.component = :component
               AND ci.courseid = :courseid
               AND ci.archived = 0
               $groupsql
          ORDER BY u.lastname ASC, u.firstname ASC";
    $issues = $DB->get_records_sql($sql, $groupparams);

    if (empty($issues)) {
        \core\notification::warning(get_string('noissuedcertificates', 'coursecertificate'));
        redirect(new \moodle_url('/mod/coursecertificate/view.php', ['id' => $id]));
    }

    $templatescache = [];
    $tempdir = make_request_directory();
    $filesforzipping = [];

    foreach ($issues as $issue) {
        $tid = (int)$issue->templateid;
        if (!isset($templatescache[$tid])) {
            try {
                $templatescache[$tid] = \tool_certificate\template::instance($tid);
            } catch (\Throwable $e) {
                $templatescache[$tid] = null;
            }
        }
        $template = $templatescache[$tid];
        if (!$template) {
            continue;
        }
        $file = $template->get_issue_file($issue);
        if ($file) {
            $userfullname = clean_filename(fullname($issue));
            if (empty($userfullname) || $userfullname === '_') {
                $userfullname = clean_filename(trim(($issue->firstname ?? '') . '_' . ($issue->lastname ?? '')));
            }
            if (empty($userfullname) || $userfullname === '_') {
                $userfullname = 'user_' . $issue->userid;
            }
            $filename = "{$userfullname}_{$issue->code}.pdf";
            $filepath = $tempdir . '/' . $filename;
            $idx = 1;
            while (file_exists($filepath) || isset($filesforzipping[$filename])) {
                $filename = "{$userfullname}_{$issue->code}_{$idx}.pdf";
                $filepath = $tempdir . '/' . $filename;
                $idx++;
            }
            $file->copy_content_to($filepath);
            $filesforzipping[$filename] = $filepath;
        }
    }

    if (empty($filesforzipping)) {
        \core\notification::warning(get_string('noissuedcertificates', 'coursecertificate'));
        redirect(new \moodle_url('/mod/coursecertificate/view.php', ['id' => $id]));
    }

    $zipname = clean_filename($course->shortname . $groupnameSuffix . '_' . $certificate->name . '_certificates.zip');
    $zippath = $tempdir . '/' . $zipname;

    // Build ZIP using native ZipArchive or Moodle file packer.
    $zipped = false;
    if (class_exists('ZipArchive')) {
        $zip = new \ZipArchive();
        if ($zip->open($zippath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true) {
            foreach ($filesforzipping as $arcname => $diskpath) {
                if (file_exists($diskpath) && filesize($diskpath) > 0) {
                    $zip->addFile($diskpath, $arcname);
                }
            }
            $zip->close();
            $zipped = file_exists($zippath) && filesize($zippath) > 22;
        }
    }

    if (!$zipped) {
        $packer = get_file_packer('application/zip');
        $packer->archive_to_pathname($filesforzipping, $zippath);
        $zipped = file_exists($zippath) && filesize($zippath) > 22;
    }

    if (!$zipped || !file_exists($zippath) || filesize($zippath) <= 22) {
        \core\notification::error(get_string('noissuedcertificates', 'coursecertificate'));
        redirect(new \moodle_url('/mod/coursecertificate/view.php', ['id' => $id]));
    }

    // Clean all output buffers and close session to prevent corruption.
    \core\session\manager::write_close();
    while (ob_get_level()) {
        ob_end_clean();
    }

    send_temp_file($zippath, $zipname, 0, 0, false, true, 'application/zip');
    exit;
}

$PAGE->set_url('/mod/coursecertificate/view.php', ['id' => $id]);
$PAGE->set_title($course->shortname . ': ' . $PAGE->activityrecord->name);
$PAGE->set_heading(format_string($course->fullname));

$output = $PAGE->get_renderer('coursecertificate');
$outputpage = new \mod_coursecertificate\output\view_page($id, $page, $perpage, $course, $cm);
$data = $outputpage->export_for_template($output);

if (!empty($data['viewurl']) && $download) {
    redirect($data['viewurl']);
}

echo $output->header();
echo $output->render_from_template('mod_coursecertificate/view_page', $data);
echo $output->footer();
