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
 * CLI Script to provision tokens or launch IOMAD course consolidations from the terminal.
 *
 * @package    local_iomadtransfer
 * @copyright  2026 IOMAD Multi-Tenant Consolidation Engine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_iomadtransfer\api\request;
use local_iomadtransfer\request_manager;
use local_iomadtransfer\security_manager;
use local_iomadtransfer\sites_manager;

[$options, $unrecognised] = cli_get_params([
    'help' => false,
    'provision_token' => false,
    'site_url' => '',
    'origin_course_id' => 0,
    'target_course_id' => 0,
    'companyid' => 0,
    'mode' => 5,
    'includeusers' => 1,
], [
    'h' => 'help',
]);

$usage = "IOMAD Course Transfer & Consolidator CLI

Usage:
  1) Provision Web Service, Sensitive Capabilities & Token on this Moodle:
     php local/iomadtransfer/cli/consolidate_course.php --provision_token=1

  2) Launch Course Consolidation from an Origin Moodle into an IOMAD Master Course:
     php local/iomadtransfer/cli/consolidate_course.php \\
         --site_url=https://cliente1.example.com \\
         --origin_course_id=12 \\
         --target_course_id=5 \\
         --companyid=3 \\
         --mode=5 \\
         --includeusers=1
";

if ($options['help']) {
    cli_writeln($usage);
    exit(0);
}

if (!empty($options['provision_token'])) {
    $token = security_manager::provision_service_and_token(false);
    cli_writeln("HOST=" . rtrim($CFG->wwwroot, '/'));
    cli_writeln("TOKEN=" . $token);
    exit(0);
}

$siteurl = trim((string)$options['site_url']);
$origincourseid = (int)$options['origin_course_id'];
$targetcourseid = (int)$options['target_course_id'];
$companyid = (int)$options['companyid'];
$mode = (int)$options['mode'];
$includeusers = (int)$options['includeusers'] ? 1 : 0;

if ($siteurl === '' || $origincourseid <= 0 || $targetcourseid <= 1) {
    cli_error("Missing required parameters. Run with --help for usage.", 2);
}

$originsite = sites_manager::get_by_host('origin', $siteurl);
if (!$originsite) {
    cli_error("Origin site not registered: {$siteurl}", 1);
}

if ($companyid <= 0 && !empty($originsite->default_companyid)) {
    $companyid = (int)$originsite->default_companyid;
}

$adminid = security_manager::resolve_execution_userid();

$req = new stdClass();
$req->type = ($mode === request_manager::TARGET_CONSOLIDATE_IOMAD) ? 5 : 0;
$req->siteurl = $originsite->host;
$req->direction = request_manager::DIRECTION_TARGET;
$req->origin_course_id = $origincourseid;
$req->origin_enrolusers = $includeusers;
$req->target_course_id = $targetcourseid;
$req->target_category_id = 1;
$req->target_target = $mode;
$req->companyid = $companyid;
$req->userid = $adminid;
$req->status = request_manager::STATUS_IN_PROGRESS;
$requestid = request_manager::save($req);

$client = new request($originsite);
$wsres = $client->origin_backup_course($requestid, $origincourseid, $targetcourseid, $includeusers);

if ($wsres->success && is_object($wsres->data)) {
    $req->origin_course_fullname = (string)($wsres->data->course_fullname ?? '');
    $req->origin_course_shortname = (string)($wsres->data->course_shortname ?? '');
    $req->origin_course_idnumber = (string)($wsres->data->course_idnumber ?? '');
    $req->origin_backup_size_estimated = (int)($wsres->data->estimated_bytes ?? 0);
    $req->status = request_manager::STATUS_BACKUP;
    request_manager::save($req);
    cli_writeln("OK: Consolidation Request #{$requestid} started for {$originsite->name} -> Target Course #{$targetcourseid} (Company #{$companyid}).");
    exit(0);
}

$errcode = $wsres->errors[0]->code ?? 'CLI_ERR';
$errmsg = $wsres->errors[0]->msg ?? 'Failed to start backup on origin';
request_manager::mark_error($requestid, $errcode, $errmsg);
cli_error("ERROR (#{$requestid}): {$errcode} - {$errmsg}", 1);
