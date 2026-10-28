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
 * Course Restore and IOMAD Consolidation Orchestrator for local_iomadtransfer.
 *
 * @package    local_iomadtransfer
 * @copyright  2026 IOMAD Multi-Tenant Consolidation Engine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_iomadtransfer;

use backup;
use backup_general_helper;
use base_setting;
use core\task\manager;
use local_iomadtransfer\task\restore_consolidate_task;
use restore_controller;
use stdClass;
use stored_file;
use Throwable;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

/**
 * Orchestrates course extraction, pre-restore sanitization, restore_controller execution,
 * and post-restore IOMAD consolidation.
 */
class restore_manager {
    /**
     * Enqueues the asynchronous restore and IOMAD consolidation task.
     *
     * @param int $requestid
     * @param int $fileid
     * @return bool
     */
    public static function enqueue_restore_task(int $requestid, int $fileid): bool {
        $adminid = security_manager::resolve_execution_userid();
        $task = new restore_consolidate_task();
        $task->set_custom_data([
            'requestid' => $requestid,
            'fileid' => $fileid,
        ]);
        $task->set_userid($adminid);
        return (bool)manager::queue_adhoc_task($task);
    }

    /**
     * Executes the full restore and IOMAD consolidation pipeline for a downloaded .mbz file.
     *
     * @param stdClass $request Record from local_iomadtransfer_request
     * @param stored_file $file Stored .mbz file
     * @return bool
     */
    public static function execute_restore_and_consolidate(stdClass $request, stored_file $file): bool {
        $filepath = '';
        try {
            $courseid = (int)$request->target_course_id;
            $companyid = (int)$request->companyid;
            $mode = (int)$request->target_target;
            $includeusers = true;
            $adminid = security_manager::resolve_execution_userid((int)$request->userid);

            // Ensure global $USER session is the primary administrator during background restore execution.
            $adminuser = get_admin();
            if ($adminuser && !empty($adminuser->id)) {
                \core\session\manager::set_user($adminuser);
                $adminid = (int)$adminuser->id;
            }

            // 1. Capture pre-restore snapshot of Master Course activities, groups, and enrolled users.
            $snapshot = iomad_consolidator::snapshot_course_state($courseid);
            $hadmasteractivities = !empty($snapshot['master_cms']);
            $isconsolidatemode = ($mode === request_manager::TARGET_CONSOLIDATE_IOMAD);

            // 2. Extract .mbz into Moodle's backup temp directory.
            $filepath = restore_controller::get_tempdir_name($file->get_contextid(), $adminid);
            $backuptempdir = make_backup_temp_directory('', false);
            $extractdir = $backuptempdir . '/' . $filepath;
            $fb = get_file_packer('application/vnd.moodle.backup');
            $fb->extract_to_pathname($file, $extractdir . '/');

            // 3. Pre-restore Sanitization & User Identity Harmonization (proven v1.0.0 user extraction):
            //    - Empties groups.xml so zero external groups from origin Moodle pollute IOMAD.
            //    - Parses users.xml without filtering out any student, aligns existing users in users.xml
            //      so Moodle's precheck_user() maps them with zero conflicts, and snapshots grades from XML.
            $backupidentities = iomad_consolidator::prepare_extracted_backup($extractdir);

            // 4. Determine Moodle restore_controller target constant (exact v1.0.0 logic):
            //    - If consolidating into an existing Master Course that already has activities,
            //      use TARGET_EXISTING_ADDING so Moodle imports all user attempts/grades without deleting
            //      existing students' data, and our consolidator merges & cleans the temporary modules afterward!
            //    - If the target course has 0 activities yet (or mode is NEW_COURSE), use TARGET_EXISTING_DELETING
            //      with keep_roles_and_enrolments = true and keep_groups_and_groupings = true.
            if ($isconsolidatemode) {
                $rctarget = $hadmasteractivities ? backup::TARGET_EXISTING_ADDING : backup::TARGET_EXISTING_DELETING;
            } else if ($mode === request_manager::TARGET_NEW_COURSE) {
                $rctarget = backup::TARGET_EXISTING_DELETING;
            } else if ($mode === request_manager::TARGET_REPLACE_COURSE) {
                $rctarget = backup::TARGET_EXISTING_DELETING;
            } else {
                $rctarget = backup::TARGET_EXISTING_ADDING;
            }

            $rc = new restore_controller(
                $filepath,
                $courseid,
                backup::INTERACTIVE_NO,
                backup::MODE_GENERAL,
                $adminid,
                $rctarget
            );

            $rc->set_progress(new restore_progress((int)$request->id));
            $plan = $rc->get_plan();

            if (!$plan) {
                request_manager::mark_error((int)$request->id, 'PLAN_NULL', 'Restore plan is NULL for extracted backup.');
                return false;
            }

            // 5. Explicitly configure restore settings:
            //    - Always preserve existing roles/enrolments and existing IOMAD groups!
            //    - Force users, role_assignments, userscompletion = 1 (preserving all quiz attempts, responses & final grades).
            //    - Force logs = 0, grade_histories = 0, calendarevents = 0 to skip historical log/audit overhead.
            //    - Never import external groups (groups = 0).
            $userflag = $includeusers ? 1 : 0;
            $planoptions = [
                'overwrite_conf' => ($mode === request_manager::TARGET_NEW_COURSE || !$hadmasteractivities),
                'keep_roles_and_enrolments' => true,
                'keep_groups_and_groupings' => true,
                'users' => $userflag,
                'role_assignments' => $userflag,
                'userscompletion' => $userflag,
                'logs' => 0,
                'grade_histories' => 0,
                'calendarevents' => 0,
                'comments' => $userflag,
                'badges' => $userflag,
                'groups' => 0,
            ];

            if ($mode === request_manager::TARGET_NEW_COURSE || !$hadmasteractivities) {
                if (!empty($request->origin_course_fullname)) {
                    $planoptions['course_fullname'] = $request->origin_course_fullname;
                }
                if (!empty($request->origin_course_shortname)) {
                    $planoptions['course_shortname'] = $request->origin_course_shortname;
                }
            }

            foreach ($planoptions as $optname => $optval) {
                if ($plan->setting_exists($optname)) {
                    $s = $plan->get_setting($optname);
                    $s->set_status(base_setting::NOT_LOCKED);
                    $s->set_value($optval);
                }
            }

            // Also ensure every activity setting in the restore plan has _userinfo = $userflag unlocked and enabled.
            foreach ($plan->get_settings() as $setting) {
                $sname = $setting->get_name();
                if (substr($sname, -9) === '_userinfo') {
                    $setting->set_status(base_setting::NOT_LOCKED);
                    $setting->set_value($userflag);
                }
            }

            if ($rc->get_status() == backup::STATUS_REQUIRE_CONV) {
                $rc->convert();
            }

            // 6. Execute precheck and ensure all users in $backupidentities are mapped in backup_ids_temp.
            $precheckok = $rc->execute_precheck();
            $precheckresults = $rc->get_precheck_results();

            iomad_consolidator::ensure_precheck_user_mappings($rc, $backupidentities);

            if ($rc->get_status() !== backup::STATUS_AWAITING) {
                iomad_consolidator::force_controller_awaiting($rc);
            }

            // 7. Execute the Moodle restore plan.
            $rc->execute_plan();
            $rc->destroy();

            // 7b. Import and link the Origin course's Workplace tool_certificate template BEFORE
            //     consolidate_after_restore() runs so issued certificates are immediately created under
            //     the real imported template (and never under the default demo template).
            try {
                iomad_consolidator::sync_and_import_origin_certificate_templates($request, true);
            } catch (Throwable $tplerr) {
                // Non-fatal: continue consolidation.
            }

            // 8. Run IOMAD Post-Restore Consolidation:
            //    - Resolves (or creates if missing) all migrated students from $backupidentities and enrolls them.
            //    - If consolidating into an existing Master Course, merges all quiz attempts, submissions,
            //      completions, and grades into the Master Course activities and deletes duplicate modules.
            //    - Backfills any missing activity or course grades from the pre-extracted XML grade snapshot
            //      and triggers grade_regrade_final_grades().
            //    - Binds the course and migrated users exclusively to the single selected IOMAD company & group,
            //      invokes company_user::enrol(), and populates mdl_local_iomad_track.
            $request->status = request_manager::STATUS_CONSOLIDATING;
            request_manager::save($request);

            $summary = iomad_consolidator::consolidate_after_restore(
                $courseid,
                $companyid,
                $snapshot,
                $backupidentities,
                ($isconsolidatemode && $hadmasteractivities)
            );

            $request->company_groupid = (int)($summary['iomad_groupid'] ?? 0);
            $request->consolidate_summary = json_encode($summary);
            $request->restored = 100;
            $request->status = request_manager::STATUS_COMPLETED;
            $request->error_code = '';
            $request->error_message = '';
            request_manager::save($request);
            return true;
        } catch (Throwable $e) {
            $diag = self::build_diagnostic_error($e, $filepath);
            request_manager::mark_error((int)$request->id, 'RESTORE_EX', $diag);
            return false;
        }
    }

    /**
     * Builds a diagnostic error message including missing target activity modules if applicable.
     *
     * @param Throwable $e
     * @param string $filepath
     * @return string
     */
    protected static function build_diagnostic_error(Throwable $e, string $filepath): string {
        global $DB;
        $msg = get_class($e) . ': ' . $e->getMessage();
        if ($filepath !== '') {
            try {
                $info = backup_general_helper::get_backup_information($filepath);
                if (!empty($info->activities)) {
                    $installed = $DB->get_records_menu('modules', null, '', 'name, id');
                    $missing = [];
                    foreach ($info->activities as $act) {
                        if (!empty($act->modulename) && !isset($installed[$act->modulename])) {
                            $missing[$act->modulename] = $act->modulename;
                        }
                    }
                    if (!empty($missing)) {
                        $msg .= ' | Missing activity plugins on target: ' . implode(', ', $missing);
                    }
                }
            } catch (Throwable $ignored) {
                // Ignore.
            }
        }
        return $msg;
    }
}
