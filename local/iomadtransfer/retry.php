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
 * Retry failed transfer / consolidation request for local_iomadtransfer.
 *
 * @package    local_iomadtransfer
 * @copyright  2026 IOMAD Multi-Tenant Consolidation Engine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use core\task\manager;
use local_iomadtransfer\api\request;
use local_iomadtransfer\request_manager;
use local_iomadtransfer\security_manager;
use local_iomadtransfer\sites_manager;
use local_iomadtransfer\task\download_backup_task;

require_login();
require_sesskey();
$context = context_system::instance();
require_capability('local/iomadtransfer:consolidate_iomad', $context);

$id = required_param('id', PARAM_INT);
$logsurl = new moodle_url('/local/iomadtransfer/logs.php');

$req = request_manager::get($id);
if (!$req) {
    redirect($logsurl, 'Solicitud no encontrada.', null, \core\output\notification::NOTIFY_ERROR);
}

$req->origin_enrolusers = 1;
$req->error_code = '';
$req->error_message = '';

// If we already have the authenticated download fileurl from the origin callback, re-queue the download task directly.
if (!empty($req->fileurl)) {
    $req->status = request_manager::STATUS_DOWNLOAD;
    request_manager::save($req);

    $adminid = security_manager::resolve_execution_userid((int)$req->userid);
    $dltask = new download_backup_task();
    $dltask->set_custom_data([
        'requestid' => (int)$req->id,
        'fileurl' => (string)$req->fileurl,
    ]);
    $dltask->set_userid($adminid);
    manager::queue_adhoc_task($dltask);

    redirect($logsurl, 'Descarga y consolidación re-encolada para la solicitud #' . $id . '.', null, \core\output\notification::NOTIFY_SUCCESS);
}

// Otherwise, re-request the backup from the origin platform.
$originsite = sites_manager::get_by_host('origin', $req->siteurl);
if (!$originsite) {
    redirect($logsurl, 'No se encontró la plataforma de origen registrada para ' . $req->siteurl, null, \core\output\notification::NOTIFY_ERROR);
}

$client = new request($originsite);
$wsres = $client->origin_backup_course((int)$req->id, (int)$req->origin_course_id, (int)$req->target_course_id, 1);
if ($wsres->success) {
    $req->status = request_manager::STATUS_BACKUP;
    request_manager::save($req);
    redirect($logsurl, 'Solicitud #' . $id . ' relanzada correctamente en el Moodle de origen.', null, \core\output\notification::NOTIFY_SUCCESS);
}

$errmsg = $wsres->errors[0]->msg ?? 'Error al relanzar en el origen';
request_manager::mark_error((int)$req->id, 'RETRY_ERR', $errmsg);
redirect($logsurl, 'Fallo al reintentar #' . $id . ': ' . $errmsg, null, \core\output\notification::NOTIFY_ERROR);
