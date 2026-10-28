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
 * Adhoc Task: Streams the .mbz backup from the Origin Moodle to disk and queues restoration.
 *
 * @package    local_iomadtransfer
 * @copyright  2026 IOMAD Multi-Tenant Consolidation Engine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_iomadtransfer\task;

use context_course;
use core\task\adhoc_task;
use core_php_time_limit;
use curl;
use local_iomadtransfer\request_manager;
use local_iomadtransfer\restore_manager;
use Throwable;

defined('MOODLE_INTERNAL') || die();

/**
 * Streams the remote .mbz file directly to disk with live progress updates and gzip verification.
 */
class download_backup_task extends adhoc_task {
    use \core\task\logging_trait;

    /**
     * Executes the streaming download task.
     */
    public function execute(): void {
        global $DB;

        $data = $this->get_custom_data();
        $requestid = (int)($data->requestid ?? 0);
        $fileurl = (string)($data->fileurl ?? '');

        $req = request_manager::get($requestid);
        if (!$req) {
            return;
        }

        core_php_time_limit::raise();
        raise_memory_limit(MEMORY_HUGE);
        request_manager::register_fatal_shutdown($requestid, 'DOWNLOAD_FATAL');

        try {
            $this->log_start('local_iomadtransfer: Downloading MBZ for request #' . $requestid);
            $req->status = request_manager::STATUS_DOWNLOAD;
            $req->downloaded = 0;
            request_manager::save($req);

            $ignoresecurity = (bool)get_config('local_iomadtransfer', 'ignorecurlsecurity');
            $curl = new curl(['ignoresecurity' => $ignoresecurity]);
            $lastheartbeat = 0;

            $curl->setopt([
                'CURLOPT_NOPROGRESS' => 0,
                'CURLOPT_PROGRESSFUNCTION' => function ($res, $dltotal, $dlnow) use ($requestid, &$lastheartbeat) {
                    global $DB;
                    $now = time();
                    if ($dlnow > 0 && ($now - $lastheartbeat) >= 4) {
                        $lastheartbeat = $now;
                        $DB->set_field('local_iomadtransfer_request', 'downloaded', (int)$dlnow, ['id' => $requestid]);
                        $DB->set_field('local_iomadtransfer_request', 'timemodified', $now, ['id' => $requestid]);
                    }
                    return 0;
                },
            ]);

            $tmpfile = make_request_directory() . '/local_iomadtransfer_' . $requestid . '.mbz';
            $dlresult = $curl->download_one($fileurl, null, ['filepath' => $tmpfile, 'timeout' => 3600]);
            $info = is_array($curl->info) ? $curl->info : [];
            $httpcode = (int)($info['http_code'] ?? 0);

            if ($dlresult !== true || $curl->get_errno() || $httpcode !== 200) {
                $errmsg = is_string($dlresult) && $dlresult !== '' ? $dlresult : ($curl->error ?: 'HTTP ' . $httpcode);
                request_manager::mark_error($requestid, 'DL_HTTP_' . $httpcode, $errmsg);
                return;
            }

            // Verify gzip magic bytes (0x1f 0x8b) so WS JSON errors are caught cleanly.
            $fh = @fopen($tmpfile, 'rb');
            $magic = $fh ? @fread($fh, 2) : '';
            if ($fh) {
                @fclose($fh);
            }
            if ($magic !== "\x1f\x8b") {
                $body = (string)@file_get_contents($tmpfile, false, null, 0, 2048);
                $json = json_decode($body);
                if (is_object($json) && !empty($json->errorcode)) {
                    $msg = $json->errorcode . ': ' . ($json->error ?? ($json->message ?? ''));
                    request_manager::mark_error($requestid, 'DL_WS_ERR', $msg);
                } else {
                    request_manager::mark_error($requestid, 'DL_INVALID_MBZ', 'Downloaded file is not a valid gzipped MBZ: ' . substr($body, 0, 200));
                }
                return;
            }

            $fs = get_file_storage();
            $ctx = context_course::instance((int)$req->target_course_id);
            $filename = 'local_iomadtransfer_' . (int)$req->origin_course_id . '_' . time() . '.mbz';
            $fileinfo = [
                'contextid' => $ctx->id,
                'component' => 'backup',
                'filearea' => 'course',
                'itemid' => 0,
                'filepath' => '/',
                'filename' => $filename,
            ];
            $storedfile = $fs->create_file_from_pathname($fileinfo, $tmpfile);

            $req->status = request_manager::STATUS_DOWNLOADED;
            $req->downloaded = (int)@filesize($tmpfile);
            request_manager::save($req);
            @unlink($tmpfile);

            // Execute restore and consolidation immediately in the same CLI/cron worker thread
            // to eliminate Adhoc Task queue latency between download and restore.
            core_php_time_limit::raise();
            raise_memory_limit(MEMORY_HUGE);
            request_manager::register_fatal_shutdown($requestid, 'RESTORE_FATAL');
            $this->log('local_iomadtransfer: Starting immediate restore and IOMAD consolidation for request #' . $requestid);
            restore_manager::execute_restore_and_consolidate($req, $storedfile);
            $this->log_finish('local_iomadtransfer: MBZ downloaded, restored, and consolidated in single execution flow.');
        } catch (Throwable $e) {
            request_manager::mark_error($requestid, 'DL_EX', $e->getMessage());
        }
    }
}
