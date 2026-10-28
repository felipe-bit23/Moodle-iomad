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
 * REST Web Service client for local_iomadtransfer.
 *
 * @package    local_iomadtransfer
 * @copyright  2026 IOMAD Multi-Tenant Consolidation Engine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_iomadtransfer\api;

use stdClass;
use Throwable;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/filelib.php');

/**
 * REST client for communicating between paired Origin and Target Moodle platforms.
 */
class request {
    /** @var string */
    public string $host;

    /** @var string */
    public string $token;

    /** @var int */
    public int $timeout;

    /**
     * Constructor.
     *
     * @param stdClass $site Record containing host and token.
     */
    public function __construct(stdClass $site) {
        $this->host = rtrim(trim((string)$site->host), '/');
        $this->token = trim((string)$site->token);
        $cfgtimeout = (int)get_config('local_iomadtransfer', 'request_timeout');
        $this->timeout = $cfgtimeout > 0 ? $cfgtimeout : 60;
    }

    /**
     * Ping remote platform to verify connectivity and token validity.
     *
     * @return response
     */
    public function ping(): response {
        global $CFG;
        return $this->call('local_iomadtransfer_ping', [
            'callerurl' => $CFG->wwwroot,
        ]);
    }

    /**
     * Fetch list of courses from an origin Moodle platform.
     *
     * @param string $search
     * @return response
     */
    public function origin_get_courses(string $search = ''): response {
        return $this->call('local_iomadtransfer_origin_get_courses', [
            'search' => $search,
        ]);
    }

    /**
     * Fetch exported Moodle Workplace tool_certificate templates (pages, elements, and Base64 files)
     * for a course from the Origin Moodle platform. Automatically falls back to the built-in
     * `__CERT_TEMPLATES__` query in `local_iomadtransfer_origin_get_courses` if the Origin server
     * has not yet registered `local_iomadtransfer_origin_get_course_templates` in external_services_functions.
     *
     * @param int $origincourseid
     * @param int $templateid
     * @return array Decoded array of exported template records
     */
    public function origin_get_course_templates(int $origincourseid = 0, int $templateid = 0): array {
        // 1. Try dedicated endpoint first.
        $res = $this->call('local_iomadtransfer_origin_get_course_templates', [
            'courseid' => $origincourseid,
            'templateid' => $templateid,
        ]);
        if ($res->success && is_object($res->data) && !empty($res->data->templates_json)) {
            $decoded = json_decode((string)$res->data->templates_json, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        // 2. Automatic fallback via origin_get_courses('__CERT_TEMPLATES__:{courseid}:{templateid}').
        $fbres = $this->origin_get_courses('__CERT_TEMPLATES__:' . $origincourseid . ':' . $templateid);
        if ($fbres->success && is_array($fbres->data) && !empty($fbres->data[0])) {
            $first = $fbres->data[0];
            $b64 = is_object($first) ? (string)($first->idnumber ?? '') : (string)($first['idnumber'] ?? '');
            if ($b64 !== '') {
                $rawjson = base64_decode($b64, true);
                if (is_string($rawjson) && $rawjson !== '') {
                    $decoded = json_decode($rawjson, true);
                    if (is_array($decoded)) {
                        return $decoded;
                    }
                }
            }
        }

        return [];
    }

    /**
     * Request the origin Moodle platform to create an asynchronous MBZ backup of a course.
     *
     * @param int $requestid Target request ID
     * @param int $origincourseid Course ID on the origin Moodle
     * @param int $targetcourseid Target course ID on IOMAD
     * @param int $includeusers 1 to include all enrolled users, grades, completion & quiz attempts
     * @return response
     */
    public function origin_backup_course(
        int $requestid,
        int $origincourseid,
        int $targetcourseid,
        int $includeusers = 1
    ): response {
        global $CFG;
        return $this->call('local_iomadtransfer_origin_backup_course', [
            'requestid' => $requestid,
            'courseid' => $origincourseid,
            'targetcourseid' => $targetcourseid,
            'targetsite' => rtrim($CFG->wwwroot, '/'),
            'includeusers' => $includeusers ? 1 : 0,
        ]);
    }

    /**
     * Notify target IOMAD platform that the origin backup is ready for streaming download.
     *
     * @param int $requestid
     * @param string $fileurl
     * @param int $filesize
     * @return response
     */
    public function target_backup_completed(int $requestid, string $fileurl, int $filesize): response {
        return $this->call('local_iomadtransfer_target_backup_completed', [
            'requestid' => $requestid,
            'fileurl' => $fileurl,
            'backupsize' => $filesize,
        ]);
    }

    /**
     * Notify target IOMAD platform that an error occurred during origin backup creation.
     *
     * @param int $requestid
     * @param string $errorcode
     * @param string $errormsg
     * @return response
     */
    public function target_backup_error(int $requestid, string $errorcode, string $errormsg): response {
        return $this->call('local_iomadtransfer_target_backup_error', [
            'requestid' => $requestid,
            'errorcode' => $errorcode,
            'errormsg' => $errormsg,
        ]);
    }

    /**
     * Executes a POST request against the remote Moodle REST server.
     *
     * @param string $wsfunction
     * @param array $params
     * @return response
     */
    protected function call(string $wsfunction, array $params): response {
        $params['wstoken'] = $this->token;
        $params['wsfunction'] = $wsfunction;
        $params['moodlewsrestformat'] = 'json';

        try {
            $ignoresecurity = (bool)get_config('local_iomadtransfer', 'ignorecurlsecurity');
            $curl = new \curl(['ignoresecurity' => $ignoresecurity]);
            $raw = $curl->post($this->host . '/webservice/rest/server.php', $params, [
                'CURLOPT_TIMEOUT' => $this->timeout,
                'CURLOPT_CONNECTTIMEOUT' => $this->timeout,
            ]);
            $info = is_array($curl->info) ? $curl->info : [];
            $cerror = (string)$curl->error;
            $cerrno = (int)$curl->errno;
        } catch (Throwable $e) {
            return $this->fail('CURL_EX', $wsfunction . ': ' . $e->getMessage());
        }

        if ($cerrno !== 0) {
            return $this->fail('CURL_' . $cerrno, $wsfunction . ' cURL error: ' . $cerror);
        }

        $httpcode = (int)($info['http_code'] ?? 0);
        if ($httpcode >= 300 && $httpcode < 400) {
            return $this->fail('HTTP_' . $httpcode, $wsfunction . ': Redirect HTTP ' . $httpcode . ' (check http vs https in host URL)');
        }
        if ($httpcode >= 400) {
            return $this->fail('HTTP_' . $httpcode, $wsfunction . ': HTTP error ' . $httpcode);
        }
        if (trim((string)$raw) === '') {
            return $this->fail('EMPTY_BODY', $wsfunction . ': Empty response from remote server');
        }

        $decoded = json_decode((string)$raw);
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            $snippet = substr(trim((string)$raw), 0, 200);
            return $this->fail('JSON_ERR', $wsfunction . ': Invalid JSON response: ' . $snippet);
        }

        if (is_object($decoded) && isset($decoded->exception)) {
            $msg = ($decoded->errorcode ?? 'exception') . ': ' . ($decoded->message ?? '');
            if (!empty($decoded->debuginfo)) {
                $msg .= ' [' . $decoded->debuginfo . ']';
            }
            return $this->fail('WS_EXCEPTION', $msg);
        }

        if (is_object($decoded) && isset($decoded->success)) {
            $errors = isset($decoded->errors) && is_array($decoded->errors) ? $decoded->errors : [];
            return new response((bool)$decoded->success, $decoded->data ?? null, $errors);
        }

        return new response(true, $decoded, []);
    }

    /**
     * Helper to build an error response.
     *
     * @param string $code
     * @param string $msg
     * @return response
     */
    protected function fail(string $code, string $msg): response {
        $err = new stdClass();
        $err->code = $code;
        $err->msg = $msg;
        return new response(false, null, [$err]);
    }
}
