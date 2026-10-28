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
 * Paired Origin and Target Sites Connection Manager for local_iomadtransfer.
 *
 * @package    local_iomadtransfer
 * @copyright  2026 IOMAD Multi-Tenant Consolidation Engine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_iomadtransfer;

use local_iomadtransfer\api\request;
use stdClass;

defined('MOODLE_INTERNAL') || die();

/**
 * Manages paired Origin and Target Moodle platforms and REST connection diagnostics.
 */
class sites_manager {
    /**
     * Normalizes a Moodle site URL (trims whitespace and trailing slashes).
     *
     * @param string $url
     * @return string
     */
    public static function normalize_host(string $url): string {
        return rtrim(trim($url), '/');
    }

    /**
     * Lists all registered Origin or Target platforms.
     *
     * @param string $type 'origin' or 'target'
     * @return array
     */
    public static function list_sites(string $type = 'origin'): array {
        global $DB;
        $table = ($type === 'target') ? 'local_iomadtransfer_target' : 'local_iomadtransfer_origin';
        return $DB->get_records($table, null, 'id ASC');
    }

    /**
     * Gets a single site record by ID.
     *
     * @param string $type 'origin' or 'target'
     * @param int $id
     * @return stdClass|false
     */
    public static function get_site(string $type, int $id) {
        global $DB;
        $table = ($type === 'target') ? 'local_iomadtransfer_target' : 'local_iomadtransfer_origin';
        return $DB->get_record($table, ['id' => $id]);
    }

    /**
     * Finds a registered site by host URL.
     *
     * @param string $type 'origin' or 'target'
     * @param string $host
     * @return stdClass|null
     */
    public static function get_by_host(string $type, string $host): ?stdClass {
        $norm = strtolower(self::normalize_host($host));
        foreach (self::list_sites($type) as $rec) {
            if (strtolower(self::normalize_host($rec->host)) === $norm) {
                return $rec;
            }
        }
        return null;
    }

    /**
     * Inserts or updates a paired Origin or Target site.
     *
     * @param string $type 'origin' or 'target'
     * @param string $name
     * @param string $host
     * @param string $token
     * @param int $defaultcompanyid
     * @param int $id Optional ID to update
     * @return int Record ID
     */
    public static function save_site(
        string $type,
        string $name,
        string $host,
        string $token,
        int $defaultcompanyid = 0,
        int $id = 0
    ): int {
        global $DB, $USER;
        $table = ($type === 'target') ? 'local_iomadtransfer_target' : 'local_iomadtransfer_origin';
        $host = self::normalize_host($host);
        $token = trim($token);
        $now = time();

        if ($id <= 0) {
            $existing = self::get_by_host($type, $host);
            if ($existing) {
                $id = (int)$existing->id;
            }
        }

        $rec = new stdClass();
        $rec->name = trim($name) !== '' ? trim($name) : $host;
        $rec->host = $host;
        $rec->token = $token;
        $rec->userid = (int)($USER->id ?? 2);
        $rec->timemodified = $now;
        if ($type === 'origin') {
            $rec->default_companyid = $defaultcompanyid;
        }

        if ($id > 0) {
            $rec->id = $id;
            $DB->update_record($table, $rec);
            return $id;
        }

        $rec->timecreated = $now;
        return (int)$DB->insert_record($table, $rec);
    }

    /**
     * Deletes a paired site.
     *
     * @param string $type
     * @param int $id
     */
    public static function delete_site(string $type, int $id): void {
        global $DB;
        $table = ($type === 'target') ? 'local_iomadtransfer_target' : 'local_iomadtransfer_origin';
        $DB->delete_records($table, ['id' => $id]);
    }

    /**
     * Tests connectivity with a paired platform (like coursetransfer's connection check)
     * and updates its lastteststatus and diagnostic details.
     *
     * @param string $type 'origin' or 'target'
     * @param int $id
     * @return array Detailed connection test results
     */
    public static function test_site_connection(string $type, int $id): array {
        global $DB;
        $table = ($type === 'target') ? 'local_iomadtransfer_target' : 'local_iomadtransfer_origin';
        $site = self::get_site($type, $id);
        if (!$site) {
            return [
                'success' => false,
                'site_id' => $id,
                'site_name' => 'Desconocido',
                'site_host' => '',
                'message' => 'Plataforma no encontrada en la base de datos.',
            ];
        }

        $t0 = microtime(true);
        $client = new request($site);
        $res = $client->ping();
        $latency = (int)round((microtime(true) - $t0) * 1000);
        $now = time();

        $site->lasttest = $now;
        $site->timemodified = $now;
        if ($res->success) {
            $data = is_object($res->data) ? $res->data : (object)[];
            $sitename = (string)($data->sitename ?? $site->name);
            $release = (string)($data->release ?? 'Moodle');
            $hasiomad = !empty($data->has_iomad);
            $wsenabled = isset($data->webservices_enabled) ? (bool)$data->webservices_enabled : true;
            $restenabled = isset($data->rest_enabled) ? (bool)$data->rest_enabled : true;
            $dlfiles = isset($data->downloadfiles_enabled) ? (bool)$data->downloadfiles_enabled : true;
            $totalcourses = (int)($data->total_courses ?? 0);

            $site->lastteststatus = 1;
            $site->lasttesterror = '';
            $DB->update_record($table, $site);

            $summarymsg = 'Conexión exitosa con "' . $sitename . '" (Moodle ' . $release . ', ' . $latency . ' ms)';
            return [
                'success' => true,
                'site_id' => (int)$site->id,
                'site_type' => $type,
                'site_name' => (string)$site->name,
                'site_host' => (string)$site->host,
                'remote_sitename' => $sitename,
                'remote_wwwroot' => (string)($data->wwwroot ?? $site->host),
                'remote_release' => $release,
                'remote_has_iomad' => $hasiomad,
                'webservices_enabled' => $wsenabled,
                'rest_enabled' => $restenabled,
                'downloadfiles_enabled' => $dlfiles,
                'total_courses' => $totalcourses,
                'latency_ms' => $latency,
                'message' => $summarymsg,
            ];
        }

        $err = !empty($res->errors[0]->msg) ? (string)$res->errors[0]->msg : 'Fallo de conexión con el servidor remoto';
        $site->lastteststatus = 0;
        $site->lasttesterror = $err;
        $DB->update_record($table, $site);

        return [
            'success' => false,
            'site_id' => (int)$site->id,
            'site_type' => $type,
            'site_name' => (string)$site->name,
            'site_host' => (string)$site->host,
            'latency_ms' => $latency,
            'message' => $err,
        ];
    }

    /**
     * Tests connectivity across all registered sites of a given type ('origin' or 'target').
     *
     * @param string $type
     * @return array List of test result arrays
     */
    public static function test_all_connections(string $type = 'origin'): array {
        $results = [];
        foreach (self::list_sites($type) as $s) {
            $results[] = self::test_site_connection($type, (int)$s->id);
        }
        return $results;
    }
}
