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
 * Course Backup Manager & Workplace Certificate Template Exporter for local_iomadtransfer.
 *
 * Builds full .mbz backups on Origin Moodles under Administrator privileges,
 * explicitly enabling all student data, grades, completion, and quiz attempts
 * while forcing groups = 0 so no unwanted origin groups pollute IOMAD.
 * Also exports Moodle Workplace tool_certificate templates (pages, elements, and
 * Base64-encoded images) so they can be reconstructed on the Target IOMAD server.
 *
 * @package    local_iomadtransfer
 * @copyright  2026 IOMAD Multi-Tenant Consolidation Engine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_iomadtransfer;

use backup;
use backup_controller;
use base_setting;
use context_course;
use context_system;
use core\task\manager;
use local_iomadtransfer\task\create_backup_task;
use moodle_url;
use stdClass;
use stored_file;
use Throwable;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

/**
 * Manages asynchronous backup creation, file serving, and certificate template export on Origin Moodles.
 */
class backup_manager {
    /**
     * Estimates the size in bytes of a course's files.
     *
     * @param int $courseid
     * @return int
     */
    public static function estimate_course_size(int $courseid): int {
        global $DB;
        try {
            $ctx = context_course::instance($courseid);
            $sql = "SELECT COALESCE(SUM(sub.filesize), 0)
                      FROM (
                            SELECT DISTINCT f.contenthash, f.filesize
                              FROM {files} f
                              JOIN {context} c ON c.id = f.contextid
                             WHERE (c.id = :ctxid OR " . $DB->sql_like('c.path', ':ctxpath') . ")
                               AND f.filename <> '.'
                               AND f.filearea NOT IN ('backup', 'automated')
                           ) sub";
            return (int)$DB->get_field_sql($sql, [
                'ctxid' => $ctx->id,
                'ctxpath' => $ctx->path . '/%',
            ]);
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * Counts enrolled users in a course.
     *
     * @param int $courseid
     * @return int
     */
    public static function count_enrolled_users(int $courseid): int {
        try {
            $ctx = context_course::instance($courseid);
            return count_enrolled_users($ctx);
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * Exports Moodle Workplace tool_certificate templates (including pages, elements, and Base64-encoded
     * background/logo images from mdl_files) used by mod_coursecertificate in the specified course.
     *
     * @param int $courseid Origin course ID (0 = export all templates or $specifictemplateid)
     * @param int $specifictemplateid Optional specific template ID to export
     * @return array List of exported template structures
     */
    public static function export_course_certificate_templates(int $courseid = 0, int $specifictemplateid = 0): array {
        global $DB;
        $dbman = $DB->get_manager();
        if (!$dbman->table_exists('tool_certificate_templates') ||
            !$dbman->table_exists('tool_certificate_pages') ||
            !$dbman->table_exists('tool_certificate_elements')) {
            return [];
        }

        try {
            $templateids = [];
            $coursetemplateids = [];
            if ($specifictemplateid > 0) {
                $templateids[$specifictemplateid] = $specifictemplateid;
                $coursetemplateids[$specifictemplateid] = $specifictemplateid;
            }

            if ($courseid > 0) {
                if ($dbman->table_exists('coursecertificate')) {
                    $certs = $DB->get_records('coursecertificate', ['course' => $courseid]);
                    foreach ($certs as $c) {
                        $tid = (int)($c->template ?? 0);
                        if ($tid > 0) {
                            $templateids[$tid] = $tid;
                            $coursetemplateids[$tid] = $tid;
                        }
                    }
                }
                if ($dbman->table_exists('tool_certificate_issues')) {
                    $icols = $DB->get_columns('tool_certificate_issues');
                    if (isset($icols['courseid'])) {
                        $issues = $DB->get_records('tool_certificate_issues', ['courseid' => $courseid], 'id ASC', 'id, templateid');
                        foreach ($issues as $iss) {
                            $tid = (int)($iss->templateid ?? 0);
                            if ($tid > 0) {
                                $templateids[$tid] = $tid;
                            }
                        }
                    }
                }
            }

            // If no specific template was found for the course (or courseid == 0), export all available templates on Origin
            // (up to 25 templates) so the Target IOMAD server receives the required template even if re-linked.
            if (!empty($templateids)) {
                list($insql, $inparams) = $DB->get_in_or_equal(array_values($templateids), SQL_PARAMS_NAMED, 'tid');
                $templates = $DB->get_records_select('tool_certificate_templates', "id {$insql}", $inparams, 'id ASC');
            } else {
                $templates = $DB->get_records('tool_certificate_templates', null, 'id ASC', '*', 0, 25);
            }

            if (empty($templates)) {
                return [];
            }

            $fs = get_file_storage();
            $syscontextid = (int)context_system::instance()->id;
            $exported = [];

            foreach ($templates as $tpl) {
                $tid = (int)$tpl->id;
                $tctxid = (int)($tpl->contextid ?: $syscontextid);

                $pages = $DB->get_records('tool_certificate_pages', ['templateid' => $tid], 'sequence ASC, id ASC');
                $pagesout = [];

                foreach ($pages as $pg) {
                    $pid = (int)$pg->id;
                    $elements = $DB->get_records('tool_certificate_elements', ['pageid' => $pid], 'sequence ASC, id ASC');
                    $elsout = [];

                    foreach ($elements as $el) {
                        $elid = (int)$el->id;
                        $filesout = [];
                        $seenfiles = [];

                        // 1. Export element-specific files in filearea 'element' and 'elementaux'.
                        foreach (['element', 'elementaux'] as $farea) {
                            $areafiles = $fs->get_area_files($tctxid, 'tool_certificate', $farea, $elid, 'filepath, filename', false);
                            foreach ($areafiles as $sf) {
                                if ($sf->is_directory()) {
                                    continue;
                                }
                                $fkey = $farea . ':' . $sf->get_filepath() . $sf->get_filename();
                                if (isset($seenfiles[$fkey])) {
                                    continue;
                                }
                                $seenfiles[$fkey] = true;
                                $filesout[] = [
                                    'filearea' => $farea,
                                    'filepath' => $sf->get_filepath(),
                                    'filename' => $sf->get_filename(),
                                    'mimetype' => $sf->get_mimetype(),
                                    'is_shared' => false,
                                    'content_b64' => base64_encode($sf->get_content()),
                                ];
                            }
                        }

                        // 2. Check if $el->data JSON references a shared image or signature file (e.g. filearea = 'image', itemid = 0).
                        $rawdata = (string)($el->data ?? '');
                        if ($rawdata !== '') {
                            $decoded = json_decode($rawdata, true);
                            if (is_array($decoded) && !empty($decoded['filename'])) {
                                $fctx = !empty($decoded['contextid']) ? (int)$decoded['contextid'] : $syscontextid;
                                $farea = !empty($decoded['filearea']) ? (string)$decoded['filearea'] : 'image';
                                $fitem = isset($decoded['itemid']) ? (int)$decoded['itemid'] : 0;
                                $fpath = !empty($decoded['filepath']) ? (string)$decoded['filepath'] : '/';
                                $fname = (string)$decoded['filename'];

                                $sharedsf = $fs->get_file($fctx, 'tool_certificate', $farea, $fitem, $fpath, $fname);
                                if (!$sharedsf && $fctx !== $syscontextid) {
                                    $sharedsf = $fs->get_file($syscontextid, 'tool_certificate', $farea, $fitem, $fpath, $fname);
                                }
                                if (!$sharedsf && $fctx !== $tctxid) {
                                    $sharedsf = $fs->get_file($tctxid, 'tool_certificate', $farea, $elid, $fpath, $fname);
                                }
                                if ($sharedsf && !$sharedsf->is_directory()) {
                                    $fkey = $farea . ':' . $sharedsf->get_filepath() . $sharedsf->get_filename();
                                    if (!isset($seenfiles[$fkey])) {
                                        $seenfiles[$fkey] = true;
                                        $filesout[] = [
                                            'filearea' => $farea,
                                            'filepath' => $sharedsf->get_filepath(),
                                            'filename' => $sharedsf->get_filename(),
                                            'mimetype' => $sharedsf->get_mimetype(),
                                            'is_shared' => true,
                                            'content_b64' => base64_encode($sharedsf->get_content()),
                                        ];
                                    }
                                }
                            }
                        }

                        $elsout[] = [
                            'origin_element_id' => $elid,
                            'name' => (string)($el->name ?? ''),
                            'element' => (string)($el->element ?? 'text'),
                            'data' => $rawdata,
                            'font' => $el->font ?? null,
                            'fontsize' => isset($el->fontsize) ? (int)$el->fontsize : null,
                            'colour' => $el->colour ?? null,
                            'posx' => isset($el->posx) ? (int)$el->posx : null,
                            'posy' => isset($el->posy) ? (int)$el->posy : null,
                            'width' => isset($el->width) ? (int)$el->width : null,
                            'refpoint' => isset($el->refpoint) ? (int)$el->refpoint : null,
                            'sequence' => isset($el->sequence) ? (int)$el->sequence : 1,
                            'files' => $filesout,
                        ];
                    }

                    $pagesout[] = [
                        'origin_page_id' => $pid,
                        'width' => (int)($pg->width ?? 297),
                        'height' => (int)($pg->height ?? 210),
                        'leftmargin' => (int)($pg->leftmargin ?? 0),
                        'rightmargin' => (int)($pg->rightmargin ?? 0),
                        'sequence' => (int)($pg->sequence ?? 1),
                        'elements' => $elsout,
                    ];
                }

                $exported[] = [
                    'origin_template_id' => $tid,
                    'name' => (string)$tpl->name,
                    'shared' => isset($tpl->shared) ? (int)$tpl->shared : 1,
                    'is_course_template' => isset($coursetemplateids[$tid]),
                    'pages' => $pagesout,
                ];
            }

            return $exported;
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Creates and queues an asynchronous backup task for the given course.
     *
     * @param int $courseid Origin course ID
     * @param int $targetsiteid Registered target site ID in local_iomadtransfer_target
     * @param int $targetrequestid Request ID on the target IOMAD server
     * @param int $originrequestid Request ID on this origin server
     * @param int $includeusers 1 to include users, grades, completion, and quiz attempts
     * @return bool
     */
    public static function enqueue_course_backup(
        int $courseid,
        int $targetsiteid,
        int $targetrequestid,
        int $originrequestid,
        int $includeusers = 1
    ): bool {
        $adminid = security_manager::resolve_execution_userid();
        $userflag = ($includeusers ? 1 : 0);

        $bc = new backup_controller(
            backup::TYPE_1COURSE,
            $courseid,
            backup::FORMAT_MOODLE,
            backup::INTERACTIVE_NO,
            backup::MODE_GENERAL,
            $adminid,
            backup::RELEASESESSION_YES
        );

        $bc->set_status(backup::STATUS_AWAITING);
        $plan = $bc->get_plan();

        // Unlock and set root user settings explicitly.
        // Crucial:
        // - groups = 0 so origin groups NEVER pollute IOMAD!
        // - logs = 0, grade_histories = 0, calendarevents = 0 so Moodle never serializes 100k+ clickstream rows
        //   or obsolete grade audit revisions, while keeping 100% of quiz attempts, question responses,
        //   submissions, completions, and final grades intact.
        $rootsettings = [
            'users' => $userflag,
            'role_assignments' => $userflag,
            'userscompletion' => $userflag,
            'logs' => 0,
            'grade_histories' => 0,
            'calendarevents' => 0,
            'comments' => $userflag,
            'badges' => $userflag,
            'questionbank' => 1,
            'groups' => 0,
        ];

        foreach ($rootsettings as $sname => $sval) {
            if ($plan->setting_exists($sname)) {
                $setting = $plan->get_setting($sname);
                $setting->set_status(base_setting::NOT_LOCKED);
                $setting->set_value($sval);
            }
        }

        // Explicitly enable _included = 1 and _userinfo = $userflag on EVERY section and activity
        // using clean integers (avoiding the boolean type bug present in local_coursetransfer).
        $modinfo = get_fast_modinfo($courseid);
        foreach ($modinfo->get_section_info_all() as $section) {
            $secinc = 'section_' . $section->id . '_included';
            $secusr = 'section_' . $section->id . '_userinfo';
            if ($plan->setting_exists($secinc)) {
                $plan->get_setting($secinc)->set_status(base_setting::NOT_LOCKED);
                $plan->get_setting($secinc)->set_value(1);
            }
            if ($plan->setting_exists($secusr)) {
                $plan->get_setting($secusr)->set_status(base_setting::NOT_LOCKED);
                $plan->get_setting($secusr)->set_value($userflag);
            }
        }

        foreach ($modinfo->get_cms() as $cm) {
            if ($cm->deletioninprogress) {
                continue;
            }
            $cminc = $cm->modname . '_' . $cm->id . '_included';
            $cmusr = $cm->modname . '_' . $cm->id . '_userinfo';
            if ($plan->setting_exists($cminc)) {
                $plan->get_setting($cminc)->set_status(base_setting::NOT_LOCKED);
                $plan->get_setting($cminc)->set_value(1);
            }
            if ($plan->setting_exists($cmusr)) {
                $plan->get_setting($cmusr)->set_status(base_setting::NOT_LOCKED);
                $plan->get_setting($cmusr)->set_value($userflag);
            }
        }

        $filename = 'local_iomadtransfer_' . $originrequestid . '_' . $courseid . '.mbz';
        if ($plan->setting_exists('filename')) {
            $plan->get_setting('filename')->set_value($filename);
        }

        $bc->set_execution(backup::EXECUTION_DELAYED);
        $bc->save_controller();

        $task = new create_backup_task();
        $task->set_custom_data([
            'backupid' => $bc->get_backupid(),
            'targetsiteid' => $targetsiteid,
            'targetrequestid' => $targetrequestid,
            'originrequestid' => $originrequestid,
        ]);
        $task->set_userid($adminid);
        return (bool)manager::queue_adhoc_task($task);
    }

    /**
     * Moves the generated backup file into local_iomadtransfer's webservice-accessible filearea
     * and returns its download URL and size.
     *
     * @param int $courseid
     * @param stored_file $file
     * @param int $originrequestid
     * @return stdClass {success: bool, fileurl: string, filesize: int, error: string}
     */
    public static function publish_backup_file(int $courseid, stored_file $file, int $originrequestid): stdClass {
        $res = new stdClass();
        $res->success = false;
        $res->fileurl = '';
        $res->filesize = 0;
        $res->error = '';

        try {
            $ctx = context_course::instance($courseid);
            $fs = get_file_storage();
            $now = time();

            // Clean any previous file for this itemid.
            $fs->delete_area_files($ctx->id, 'local_iomadtransfer', 'backup', $originrequestid);

            $filerecord = [
                'contextid' => $ctx->id,
                'component' => 'local_iomadtransfer',
                'filearea' => 'backup',
                'itemid' => $originrequestid,
                'filepath' => '/',
                'filename' => 'backup.mbz',
                'timecreated' => $now,
                'timemodified' => $now,
            ];
            $stored = $fs->create_file_from_storedfile($filerecord, $file);
            $filesize = (int)$stored->get_filesize();
            $file->delete();

            $url = moodle_url::make_webservice_pluginfile_url(
                $stored->get_contextid(),
                $stored->get_component(),
                $stored->get_filearea(),
                $stored->get_itemid(),
                $stored->get_filepath(),
                $stored->get_filename()
            )->out(false);

            $maxmb = (int)get_config('local_iomadtransfer', 'max_backup_size_mb');
            if ($maxmb > 0 && $filesize > ($maxmb * 1048576)) {
                $res->error = 'Backup size (' . round($filesize / 1048576, 1) . ' MB) exceeds configured limit (' . $maxmb . ' MB)';
                return $res;
            }

            $res->success = true;
            $res->fileurl = $url;
            $res->filesize = $filesize;
        } catch (Throwable $e) {
            $res->error = $e->getMessage();
        }
        return $res;
    }
}
