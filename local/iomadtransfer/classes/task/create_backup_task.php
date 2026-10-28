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
 * Adhoc Task: Creates the .mbz backup on the Origin Moodle and notifies the Target IOMAD platform.
 *
 * @package    local_iomadtransfer
 * @copyright  2026 IOMAD Multi-Tenant Consolidation Engine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_iomadtransfer\task;

use backup;
use backup_controller;
use core\task\asynchronous_backup_task;
use core_php_time_limit;
use local_iomadtransfer\api\request;
use local_iomadtransfer\backup_manager;
use local_iomadtransfer\request_manager;
use local_iomadtransfer\sites_manager;
use Throwable;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');

/**
 * Asynchronous task that executes the backup_controller plan on the Origin Moodle.
 */
class create_backup_task extends asynchronous_backup_task {
    use \core\task\logging_trait;

    /**
     * Executes the backup task.
     */
    public function execute(): void {
        global $DB;

        $data = $this->get_custom_data();
        $backupid = (string)($data->backupid ?? '');
        $targetsiteid = (int)($data->targetsiteid ?? 0);
        $targetrequestid = (int)($data->targetrequestid ?? 0);
        $originrequestid = (int)($data->originrequestid ?? 0);

        core_php_time_limit::raise();
        raise_memory_limit(MEMORY_HUGE);
        if ($originrequestid > 0) {
            request_manager::register_fatal_shutdown($originrequestid, 'BACKUP_FATAL');
        }

        // Guarantee primary administrator session context so Moodle never strips userinfo during cron.
        $admin = get_admin();
        if ($admin && !empty($admin->id)) {
            \core\session\manager::set_user($admin);
        }

        $targetsite = sites_manager::get_site('target', $targetsiteid);
        $originreq = $originrequestid > 0 ? request_manager::get($originrequestid) : null;

        try {
            $this->log_start('local_iomadtransfer: Starting course backup (' . $backupid . ')...');

            if ($originreq) {
                $originreq->status = request_manager::STATUS_BACKUP;
                request_manager::save($originreq);
            }

            $bc = backup_controller::load_controller($backupid);
            $backuprec = $DB->get_record('backup_controllers', ['backupid' => $backupid], 'id, controller', MUST_EXIST);
            $bc->set_progress(new \core\progress\db_updater($backuprec->id, 'backup_controllers', 'progress'));

            if ($bc->get_status() == backup::STATUS_AWAITING && $bc->get_execution() == backup::EXECUTION_DELAYED) {
                $bc->execute_plan();
            } else {
                $bc->set_status(backup::STATUS_FINISHED_ERR);
            }

            $results = $bc->get_results();
            $courseid = (int)$bc->get_courseid();

            if ($bc->get_status() === backup::STATUS_FINISHED_OK && !empty($results['backup_destination'])) {
                $pub = backup_manager::publish_backup_file($courseid, $results['backup_destination'], $originrequestid);
                if ($pub->success) {
                    if ($originreq) {
                        $originreq->fileurl = $pub->fileurl;
                        $originreq->origin_backup_url = $pub->fileurl;
                        $originreq->origin_backup_size = $pub->filesize;
                        $originreq->status = request_manager::STATUS_COMPLETED;
                        request_manager::save($originreq);
                    }
                    if ($targetsite) {
                        $client = new request($targetsite);
                        $cbres = $client->target_backup_completed($targetrequestid, $pub->fileurl, $pub->filesize);
                        if (!$cbres->success && $originreq) {
                            $err = $cbres->errors[0]->msg ?? 'Target callback failed';
                            request_manager::mark_error($originrequestid, 'CALLBACK_ERR', $err);
                        }
                    }
                } else {
                    if ($originreq) {
                        request_manager::mark_error($originrequestid, 'PUBLISH_ERR', $pub->error);
                    }
                    if ($targetsite) {
                        $client = new request($targetsite);
                        $client->target_backup_error($targetrequestid, 'PUBLISH_ERR', $pub->error);
                    }
                }
            } else {
                $errmsg = 'Backup controller finished with status ' . $bc->get_status();
                if ($originreq) {
                    request_manager::mark_error($originrequestid, 'BC_ERR', $errmsg);
                }
                if ($targetsite) {
                    $client = new request($targetsite);
                    $client->target_backup_error($targetrequestid, 'BC_ERR', $errmsg);
                }
            }

            $bc->destroy();
            $this->log_finish('local_iomadtransfer: Backup finished.');
        } catch (Throwable $e) {
            if ($originreq) {
                request_manager::mark_error($originrequestid, 'BACKUP_EX', $e->getMessage());
            }
            if ($targetsite) {
                $client = new request($targetsite);
                $client->target_backup_error($targetrequestid, 'BACKUP_EX', $e->getMessage());
            }
            $this->log('local_iomadtransfer backup error: ' . $e->getMessage());
        }
    }
}
