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
 * Adhoc Task: Restores the downloaded .mbz and runs IOMAD Smart Consolidation.
 *
 * @package    local_iomadtransfer
 * @copyright  2026 IOMAD Multi-Tenant Consolidation Engine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_iomadtransfer\task;

use core\task\adhoc_task;
use core_php_time_limit;
use local_iomadtransfer\iomad_consolidator;
use local_iomadtransfer\request_manager;
use local_iomadtransfer\restore_manager;

defined('MOODLE_INTERNAL') || die();

/**
 * Executes course restoration and IOMAD consolidation in the background.
 */
class restore_consolidate_task extends adhoc_task {
    use \core\task\logging_trait;

    /**
     * Executes the restore and consolidation task.
     */
    public function execute(): void {
        $data = $this->get_custom_data();
        $requestid = (int)($data->requestid ?? 0);
        $fileid = (int)($data->fileid ?? 0);

        $req = request_manager::get($requestid);
        if (!$req) {
            return;
        }

        core_php_time_limit::raise();
        raise_memory_limit(MEMORY_HUGE);
        request_manager::register_fatal_shutdown($requestid, 'RESTORE_FATAL');

        $fs = get_file_storage();
        $file = $fs->get_file_by_id($fileid);
        if (!$file) {
            request_manager::mark_error($requestid, 'FILE_MISSING', 'Backup stored_file #' . $fileid . ' not found.');
            return;
        }

        $this->log_start('local_iomadtransfer: Restoring & consolidating request #' . $requestid);
        $req->status = request_manager::STATUS_RESTORE;
        request_manager::save($req);

        $ok = restore_manager::execute_restore_and_consolidate($req, $file);
        if ($ok) {
            // Automatically import & re-link any Workplace tool_certificate templates from Origin,
            // then synchronize IOMAD Company Managers (across all company subgroups) and audit certificates.
            $freshreq = request_manager::get($requestid);
            if ($freshreq) {
                iomad_consolidator::sync_and_import_origin_certificate_templates($freshreq);
                request_manager::sync_company_managers_and_certificates($freshreq);
            }
            // Delete the temporary .mbz file from the course backup filearea once completed.
            $file->delete();
            $this->log_finish('local_iomadtransfer: Request #' . $requestid . ' completed successfully.');
        } else {
            $this->log_finish('local_iomadtransfer: Request #' . $requestid . ' failed.');
        }
    }
}
