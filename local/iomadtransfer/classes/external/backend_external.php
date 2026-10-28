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
 * External REST Web Service Functions for local_iomadtransfer.
 *
 * @package    local_iomadtransfer
 * @copyright  2026 IOMAD Multi-Tenant Consolidation Engine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_iomadtransfer\external;

use context_system;
use core\task\manager;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_iomadtransfer\backup_manager;
use local_iomadtransfer\request_manager;
use local_iomadtransfer\security_manager;
use local_iomadtransfer\sites_manager;
use local_iomadtransfer\task\download_backup_task;
use stdClass;
use Throwable;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/webservice/lib.php');

/**
 * Backend Web Service endpoints for Origin and Target communication.
 */
class backend_external extends external_api {
    /**
     * Parameters for ping().
     *
     * @return external_function_parameters
     */
    public static function ping_parameters(): external_function_parameters {
        return new external_function_parameters([
            'callerurl' => new external_value(PARAM_RAW, 'Caller Moodle URL', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Health check endpoint.
     *
     * @param string $callerurl
     * @return array
     */
    public static function ping(string $callerurl = ''): array {
        global $SITE, $CFG, $DB;
        self::validate_parameters(self::ping_parameters(), ['callerurl' => $callerurl]);
        self::validate_context(context_system::instance());

        $wsenabled = !empty($CFG->enablewebservices);
        $protocols = explode(',', (string)get_config('core', 'webserviceprotocols'));
        $restenabled = in_array('rest', array_map('trim', $protocols), true);
        $service = $DB->get_record('external_services', ['shortname' => security_manager::SERVICE_SHORTNAME]);
        $dlfiles = $service ? ((int)$service->downloadfiles === 1) : true;
        $totalcourses = (int)$DB->count_records_select('course', 'id > 1');

        return [
            'success' => true,
            'data' => [
                'sitename' => format_string($SITE->fullname ?? 'Moodle'),
                'wwwroot' => (string)$CFG->wwwroot,
                'release' => (string)($CFG->release ?? ''),
                'has_iomad' => security_manager::has_iomad(),
                'webservices_enabled' => $wsenabled,
                'rest_enabled' => $restenabled,
                'downloadfiles_enabled' => $dlfiles,
                'total_courses' => $totalcourses,
            ],
            'errors' => [],
        ];
    }

    /**
     * Return structure for ping().
     *
     * @return external_single_structure
     */
    public static function ping_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Success'),
            'data' => new external_single_structure([
                'sitename' => new external_value(PARAM_TEXT, 'Site fullname'),
                'wwwroot' => new external_value(PARAM_RAW, 'Site wwwroot'),
                'release' => new external_value(PARAM_TEXT, 'Moodle release'),
                'has_iomad' => new external_value(PARAM_BOOL, 'Whether IOMAD is installed'),
                'webservices_enabled' => new external_value(PARAM_BOOL, 'Web services enabled', VALUE_OPTIONAL),
                'rest_enabled' => new external_value(PARAM_BOOL, 'REST protocol enabled', VALUE_OPTIONAL),
                'downloadfiles_enabled' => new external_value(PARAM_BOOL, 'Download files enabled', VALUE_OPTIONAL),
                'total_courses' => new external_value(PARAM_INT, 'Total courses on site', VALUE_OPTIONAL),
            ], 'Site info', VALUE_OPTIONAL),
            'errors' => new external_multiple_structure(new external_single_structure([
                'code' => new external_value(PARAM_TEXT, 'Error code'),
                'msg' => new external_value(PARAM_RAW, 'Error message'),
            ]), 'Errors', VALUE_DEFAULT, []),
        ]);
    }

    /**
     * Parameters for origin_get_courses().
     *
     * @return external_function_parameters
     */
    public static function origin_get_courses_parameters(): external_function_parameters {
        return new external_function_parameters([
            'search' => new external_value(PARAM_RAW, 'Optional search filter', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Lists all courses on the Origin Moodle with enrolled user counts and estimated sizes.
     * Also supports a built-in zero-friction fallback query (`__CERT_TEMPLATES__:{courseid}:{templateid}`)
     * to export Workplace tool_certificate templates even before external_services_functions is refreshed.
     *
     * @param string $search
     * @return array
     */
    public static function origin_get_courses(string $search = ''): array {
        global $DB;
        $params = self::validate_parameters(self::origin_get_courses_parameters(), ['search' => $search]);
        self::validate_context(context_system::instance());

        $search = trim((string)$params['search']);

        // Built-in zero-friction transport for Workplace certificate templates.
        if (strpos($search, '__CERT_TEMPLATES__:') === 0) {
            $parts = explode(':', $search);
            $cid = isset($parts[1]) ? (int)$parts[1] : 0;
            $tid = isset($parts[2]) ? (int)$parts[2] : 0;
            $templates = backup_manager::export_course_certificate_templates($cid, $tid);
            $payload = base64_encode(json_encode($templates));
            return [
                'success' => true,
                'data' => [[
                    'id' => $cid,
                    'fullname' => '__CERT_TEMPLATES__',
                    'shortname' => '__CERT_TEMPLATES__',
                    'idnumber' => $payload,
                    'categoryname' => '',
                    'enrolledcount' => count($templates),
                    'estimatedbytes' => strlen($payload),
                ]],
                'errors' => [],
            ];
        }

        $sql = "SELECT c.id, c.fullname, c.shortname, c.idnumber, c.visible, cc.name AS categoryname
                  FROM {course} c
             LEFT JOIN {course_categories} cc ON cc.id = c.category
                 WHERE c.id > 1
              ORDER BY c.fullname ASC";
        $records = $DB->get_records_sql($sql);
        $courses = [];

        foreach ($records as $c) {
            if ($search !== '') {
                $haystack = strtolower($c->fullname . ' ' . $c->shortname . ' ' . $c->idnumber);
                if (strpos($haystack, strtolower($search)) === false) {
                    continue;
                }
            }
            $courses[] = [
                'id' => (int)$c->id,
                'fullname' => (string)$c->fullname,
                'shortname' => (string)$c->shortname,
                'idnumber' => (string)$c->idnumber,
                'categoryname' => (string)($c->categoryname ?? ''),
                'enrolledcount' => backup_manager::count_enrolled_users((int)$c->id),
                'estimatedbytes' => backup_manager::estimate_course_size((int)$c->id),
            ];
        }

        return [
            'success' => true,
            'data' => $courses,
            'errors' => [],
        ];
    }

    /**
     * Return structure for origin_get_courses().
     *
     * @return external_single_structure
     */
    public static function origin_get_courses_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Success'),
            'data' => new external_multiple_structure(new external_single_structure([
                'id' => new external_value(PARAM_INT, 'Course ID'),
                'fullname' => new external_value(PARAM_RAW, 'Course fullname'),
                'shortname' => new external_value(PARAM_RAW, 'Course shortname'),
                'idnumber' => new external_value(PARAM_RAW, 'Course idnumber'),
                'categoryname' => new external_value(PARAM_RAW, 'Category name'),
                'enrolledcount' => new external_value(PARAM_INT, 'Enrolled users count'),
                'estimatedbytes' => new external_value(PARAM_INT, 'Estimated size in bytes'),
            ]), 'Courses', VALUE_DEFAULT, []),
            'errors' => new external_multiple_structure(new external_single_structure([
                'code' => new external_value(PARAM_TEXT, 'Error code'),
                'msg' => new external_value(PARAM_RAW, 'Error message'),
            ]), 'Errors', VALUE_DEFAULT, []),
        ]);
    }

    /**
     * Parameters for origin_get_course_templates().
     *
     * @return external_function_parameters
     */
    public static function origin_get_course_templates_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Origin course ID', VALUE_DEFAULT, 0),
            'templateid' => new external_value(PARAM_INT, 'Optional specific template ID', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Exports all Moodle Workplace tool_certificate templates (pages, elements, and Base64 files)
     * associated with the given Origin course ID.
     *
     * @param int $courseid
     * @param int $templateid
     * @return array
     */
    public static function origin_get_course_templates(int $courseid = 0, int $templateid = 0): array {
        $params = self::validate_parameters(self::origin_get_course_templates_parameters(), [
            'courseid' => $courseid,
            'templateid' => $templateid,
        ]);
        self::validate_context(context_system::instance());

        try {
            $templates = backup_manager::export_course_certificate_templates(
                (int)$params['courseid'],
                (int)$params['templateid']
            );
            return [
                'success' => true,
                'data' => [
                    'templates_json' => json_encode($templates),
                    'count' => count($templates),
                ],
                'errors' => [],
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'data' => null,
                'errors' => [['code' => 'TPL_EXPORT_ERR', 'msg' => $e->getMessage()]],
            ];
        }
    }

    /**
     * Return structure for origin_get_course_templates().
     *
     * @return external_single_structure
     */
    public static function origin_get_course_templates_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Success'),
            'data' => new external_single_structure([
                'templates_json' => new external_value(PARAM_RAW, 'JSON encoded array of exported tool_certificate templates'),
                'count' => new external_value(PARAM_INT, 'Number of exported templates'),
            ], 'Exported templates payload', VALUE_OPTIONAL),
            'errors' => new external_multiple_structure(new external_single_structure([
                'code' => new external_value(PARAM_TEXT, 'Error code'),
                'msg' => new external_value(PARAM_RAW, 'Error message'),
            ]), 'Errors', VALUE_DEFAULT, []),
        ]);
    }

    /**
     * Parameters for origin_backup_course().
     *
     * @return external_function_parameters
     */
    public static function origin_backup_course_parameters(): external_function_parameters {
        return new external_function_parameters([
            'requestid' => new external_value(PARAM_INT, 'Target request ID'),
            'courseid' => new external_value(PARAM_INT, 'Origin course ID'),
            'targetcourseid' => new external_value(PARAM_INT, 'Target course ID'),
            'targetsite' => new external_value(PARAM_RAW, 'Target Moodle URL'),
            'includeusers' => new external_value(PARAM_INT, 'Include enrolled users, grades & attempts', VALUE_DEFAULT, 1),
        ]);
    }

    /**
     * Triggers an asynchronous course backup on the Origin Moodle.
     *
     * @param int $requestid
     * @param int $courseid
     * @param int $targetcourseid
     * @param string $targetsite
     * @param int $includeusers
     * @return array
     */
    public static function origin_backup_course(
        int $requestid,
        int $courseid,
        int $targetcourseid,
        string $targetsite,
        int $includeusers = 1
    ): array {
        $params = self::validate_parameters(self::origin_backup_course_parameters(), [
            'requestid' => $requestid,
            'courseid' => $courseid,
            'targetcourseid' => $targetcourseid,
            'targetsite' => $targetsite,
            'includeusers' => $includeusers,
        ]);
        self::validate_context(context_system::instance());

        try {
            $course = get_course((int)$params['courseid']);
            $targetrec = sites_manager::get_by_host('target', (string)$params['targetsite']);
            if (!$targetrec) {
                return [
                    'success' => false,
                    'data' => null,
                    'errors' => [[
                        'code' => 'TARGET_NOT_REGISTERED',
                        'msg' => 'Target site (' . $params['targetsite'] . ') is not registered in this Origin Moodle under Paired Target Sites.',
                    ]],
                ];
            }

            $adminid = security_manager::resolve_execution_userid();
            $estsize = backup_manager::estimate_course_size((int)$course->id);

            $originreq = new stdClass();
            $originreq->type = 0;
            $originreq->siteurl = $targetrec->host;
            $originreq->direction = request_manager::DIRECTION_ORIGIN;
            $originreq->target_request_id = (int)$params['requestid'];
            $originreq->origin_course_id = (int)$course->id;
            $originreq->origin_course_fullname = (string)$course->fullname;
            $originreq->origin_course_shortname = (string)$course->shortname;
            $originreq->origin_course_idnumber = (string)$course->idnumber;
            $originreq->origin_enrolusers = (int)$params['includeusers'] ? 1 : 0;
            $originreq->origin_backup_size_estimated = $estsize;
            $originreq->target_course_id = (int)$params['targetcourseid'];
            $originreq->userid = $adminid;
            $originreq->status = request_manager::STATUS_IN_PROGRESS;
            $originreqid = request_manager::save($originreq);

            $queued = backup_manager::enqueue_course_backup(
                (int)$course->id,
                (int)$targetrec->id,
                (int)$params['requestid'],
                $originreqid,
                (int)$params['includeusers']
            );

            if (!$queued) {
                request_manager::mark_error($originreqid, 'QUEUE_ERR', 'Could not queue create_backup_task');
                return [
                    'success' => false,
                    'data' => null,
                    'errors' => [['code' => 'QUEUE_ERR', 'msg' => 'Could not queue backup adhoc task']],
                ];
            }

            return [
                'success' => true,
                'data' => [
                    'origin_request_id' => $originreqid,
                    'course_fullname' => (string)$course->fullname,
                    'course_shortname' => (string)$course->shortname,
                    'course_idnumber' => (string)$course->idnumber,
                    'estimated_bytes' => $estsize,
                ],
                'errors' => [],
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'data' => null,
                'errors' => [['code' => 'BACKUP_REQ_EX', 'msg' => $e->getMessage()]],
            ];
        }
    }

    /**
     * Return structure for origin_backup_course().
     *
     * @return external_single_structure
     */
    public static function origin_backup_course_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Success'),
            'data' => new external_single_structure([
                'origin_request_id' => new external_value(PARAM_INT, 'Origin request ID'),
                'course_fullname' => new external_value(PARAM_RAW, 'Course fullname'),
                'course_shortname' => new external_value(PARAM_RAW, 'Course shortname'),
                'course_idnumber' => new external_value(PARAM_RAW, 'Course idnumber'),
                'estimated_bytes' => new external_value(PARAM_INT, 'Estimated size in bytes'),
            ], 'Backup metadata', VALUE_OPTIONAL),
            'errors' => new external_multiple_structure(new external_single_structure([
                'code' => new external_value(PARAM_TEXT, 'Error code'),
                'msg' => new external_value(PARAM_RAW, 'Error message'),
            ]), 'Errors', VALUE_DEFAULT, []),
        ]);
    }

    /**
     * Parameters for target_backup_completed().
     *
     * @return external_function_parameters
     */
    public static function target_backup_completed_parameters(): external_function_parameters {
        return new external_function_parameters([
            'requestid' => new external_value(PARAM_INT, 'Target request ID'),
            'fileurl' => new external_value(PARAM_RAW, 'Pluginfile download URL'),
            'backupsize' => new external_value(PARAM_INT, 'Backup size in bytes'),
        ]);
    }

    /**
     * Callback invoked by Origin when the .mbz is ready. Appends Origin's token and queues download_backup_task.
     *
     * @param int $requestid
     * @param string $fileurl
     * @param int $backupsize
     * @return array
     */
    public static function target_backup_completed(int $requestid, string $fileurl, int $backupsize): array {
        $params = self::validate_parameters(self::target_backup_completed_parameters(), [
            'requestid' => $requestid,
            'fileurl' => $fileurl,
            'backupsize' => $backupsize,
        ]);
        self::validate_context(context_system::instance());

        $req = request_manager::get((int)$params['requestid']);
        if (!$req) {
            return [
                'success' => false,
                'errors' => [['code' => 'REQ_NOT_FOUND', 'msg' => 'Target request not found: ' . $params['requestid']]],
            ];
        }

        $originsite = sites_manager::get_by_host('origin', $req->siteurl);
        if (!$originsite) {
            request_manager::mark_error((int)$req->id, 'ORIGIN_NOT_FOUND', 'Origin site not registered: ' . $req->siteurl);
            return [
                'success' => false,
                'errors' => [['code' => 'ORIGIN_NOT_FOUND', 'msg' => 'Origin site not registered']],
            ];
        }

        $sep = (strpos($params['fileurl'], '?') !== false) ? '&' : '?';
        $authenticatedurl = $params['fileurl'] . $sep . 'token=' . urlencode($originsite->token);

        $req->origin_backup_size = (int)$params['backupsize'];
        $req->origin_backup_url = $params['fileurl'];
        $req->fileurl = $authenticatedurl;
        $req->status = request_manager::STATUS_DOWNLOAD;
        request_manager::save($req);

        $adminid = security_manager::resolve_execution_userid((int)$req->userid);
        $dltask = new download_backup_task();
        $dltask->set_custom_data([
            'requestid' => (int)$req->id,
            'fileurl' => $authenticatedurl,
        ]);
        $dltask->set_userid($adminid);
        manager::queue_adhoc_task($dltask);

        return [
            'success' => true,
            'errors' => [],
        ];
    }

    /**
     * Return structure for target_backup_completed().
     *
     * @return external_single_structure
     */
    public static function target_backup_completed_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Success'),
            'errors' => new external_multiple_structure(new external_single_structure([
                'code' => new external_value(PARAM_TEXT, 'Error code'),
                'msg' => new external_value(PARAM_RAW, 'Error message'),
            ]), 'Errors', VALUE_DEFAULT, []),
        ]);
    }

    /**
     * Parameters for target_backup_error().
     *
     * @return external_function_parameters
     */
    public static function target_backup_error_parameters(): external_function_parameters {
        return new external_function_parameters([
            'requestid' => new external_value(PARAM_INT, 'Target request ID'),
            'errorcode' => new external_value(PARAM_TEXT, 'Error code'),
            'errormsg' => new external_value(PARAM_RAW, 'Error message'),
        ]);
    }

    /**
     * Callback invoked by Origin if backup creation fails.
     *
     * @param int $requestid
     * @param string $errorcode
     * @param string $errormsg
     * @return array
     */
    public static function target_backup_error(int $requestid, string $errorcode, string $errormsg): array {
        $params = self::validate_parameters(self::target_backup_error_parameters(), [
            'requestid' => $requestid,
            'errorcode' => $errorcode,
            'errormsg' => $errormsg,
        ]);
        self::validate_context(context_system::instance());

        request_manager::mark_error((int)$params['requestid'], (string)$params['errorcode'], (string)$params['errormsg']);
        return [
            'success' => true,
            'errors' => [],
        ];
    }

    /**
     * Return structure for target_backup_error().
     *
     * @return external_single_structure
     */
    public static function target_backup_error_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Success'),
            'errors' => new external_multiple_structure(new external_single_structure([
                'code' => new external_value(PARAM_TEXT, 'Error code'),
                'msg' => new external_value(PARAM_RAW, 'Error message'),
            ]), 'Errors', VALUE_DEFAULT, []),
        ]);
    }
}
