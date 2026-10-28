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
 * IOMAD Smart Course Consolidator Engine for local_iomadtransfer.
 *
 * Implements:
 * 1. Pre-restore sanitization & full XML harmonization of extracted .mbz:
 *    - Empties groups.xml so zero origin groups pollute IOMAD.
 *    - Parses users.xml using the proven v1.0.0 extraction logic (without filtering out students)
 *      and ensures every student exists in mdl_user and aligns with existing XML tags so Moodle
 *      restore_dbops::precheck_user() maps users 100% natively without conflict.
 *    - Snapshots grades from activities/[mod]/grades.xml and gradebook.xml.
 * 2. Post-precheck backup_ids_temp verification (ensure_precheck_user_mappings) so get_mappingid("user", $xmluid)
 *    always resolves to the target Moodle user ID during restore_controller::execute_plan().
 * 3. Post-restore atomic consolidation, gradebook backfill & native IOMAD synchronization:
 *    - Resolves (or creates if missing) all incoming students and enrolls them in the course with active
 *      manual enrolment (timestart <= time() - 3600) and student role.
 *    - Merges all quiz attempts, assignment submissions & files, SCORM tracks, H5P/Lesson/Forum/Choice/Feedback
 *      attempts, completions, and grades into the Master Course existing activities.
 *    - Backfills any missing activity or course grades from the pre-extracted XML grade snapshot and runs
 *      grade_regrade_final_grades() so the course gradebook is 100% complete.
 *    - Binds the course and ONLY the migrated students to the SINGLE target IOMAD Company (mdl_company_users &
 *      root mdl_department), invokes native company_user::enrol() with the official company course group
 *      (mdl_company_course_groups -> mdl_groups -> mdl_groups_members), and populates mdl_local_iomad_track
 *      so students appear immediately under "Enrolled users" in IOMAD User Enrolments.
 *
 * @package    local_iomadtransfer
 * @copyright  2026 IOMAD Multi-Tenant Consolidation Engine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_iomadtransfer;

use backup;
use context_course;
use context_system;
use context_module;
use context_user;
use DOMDocument;
use DOMElement;
use DOMXPath;
use ReflectionClass;
use restore_controller;
use restore_dbops;
use stdClass;
use Throwable;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/group/lib.php');
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->dirroot . '/user/lib.php');

/**
 * Consolidates multiple remote Moodle course backups into a single shared IOMAD Master Course.
 */
class iomad_consolidator {
    /**
     * Loads native IOMAD libraries if present on the server.
     */
    protected static function load_iomad_libraries(): void {
        global $CFG;
        $libs = [
            '/local/iomad/lib/iomad.php',
            '/local/iomad/lib/company.php',
            '/local/iomad/lib/user.php',
        ];
        foreach ($libs as $rel) {
            $full = $CFG->dirroot . $rel;
            if (file_exists($full)) {
                require_once($full);
            }
        }
    }

    /**
     * Safely updates the text content of an EXISTING child element inside a DOMElement
     * without ever creating new non-schema XML tags in users.xml.
     *
     * @param DOMDocument $dom
     * @param DOMXPath $xpath
     * @param DOMElement $parent
     * @param string $tagname
     * @param string $value
     * @return bool True if modified
     */
    protected static function update_existing_xml_child(
        DOMDocument $dom,
        DOMXPath $xpath,
        DOMElement $parent,
        string $tagname,
        string $value
    ): bool {
        $node = $xpath->query($tagname, $parent)->item(0);
        if (!$node) {
            return false;
        }
        if ((string)$node->nodeValue === $value) {
            return false;
        }
        while ($node->firstChild) {
            $node->removeChild($node->firstChild);
        }
        $node->appendChild($dom->createTextNode($value));
        return true;
    }

    /**
     * Sanitizes the extracted backup directory BEFORE restore_controller runs:
     * 1. Replaces groups.xml with an empty groups/groupings XML so zero unwanted groups from the origin Moodle are created.
     * 2. Snapshots student grades from activities/[mod]/grades.xml and gradebook.xml.
     * 3. Parses users.xml (proven v1.0.0 logic without filtering out students), ensures each student exists in local
     *    mdl_user (or creates them cleanly), and aligns existing XML tags so Moodle restore precheck never fails.
     *
     * @param string $extractdir Full path to the extracted backup folder.
     * @return array List of incoming student identity records with XML user ID, target user ID, and grade snapshots.
     */
    public static function prepare_extracted_backup(string $extractdir): array {
        global $DB, $CFG;

        $base = rtrim($extractdir, '/');
        $localhostid = !empty($CFG->mnet_localhost_id) ? (int)$CFG->mnet_localhost_id : 1;

        // 1. Wipe external groups in groups.xml so origin groups are never imported.
        $groupsxml = $base . '/groups.xml';
        if (file_exists($groupsxml)) {
            $emptygroups = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<groups>\n  <groupings>\n  </groupings>\n</groups>\n";
            @file_put_contents($groupsxml, $emptygroups);
        }

        // 2. Parse activities/*/grades.xml and gradebook.xml to capture student grades before restore deletes temp dir.
        $usergradesmap = [];
        $gradefiles = [];
        $actglob = @glob($base . '/activities/*/grades.xml');
        if (is_array($actglob)) {
            foreach ($actglob as $gf) {
                $gradefiles[] = $gf;
            }
        }
        if (file_exists($base . '/gradebook.xml')) {
            $gradefiles[] = $base . '/gradebook.xml';
        }

        foreach ($gradefiles as $gfile) {
            if (!is_readable($gfile)) {
                continue;
            }
            try {
                $gdom = new DOMDocument('1.0', 'UTF-8');
                if (@$gdom->load($gfile)) {
                    $gxpath = new DOMXPath($gdom);
                    $ginodes = $gxpath->query('//grade_items/grade_item');
                    if ($ginodes) {
                        foreach ($ginodes as $ginode) {
                            $itypeNode = $gxpath->query('itemtype', $ginode)->item(0);
                            $imodNode  = $gxpath->query('itemmodule', $ginode)->item(0);
                            $inameNode = $gxpath->query('itemname', $ginode)->item(0);
                            $inumNode  = $gxpath->query('itemnumber', $ginode)->item(0);
                            $gmaxNode  = $gxpath->query('grademax', $ginode)->item(0);
                            $gminNode  = $gxpath->query('grademin', $ginode)->item(0);

                            $itemtype = $itypeNode ? trim($itypeNode->nodeValue) : 'mod';
                            $itemmodule = $imodNode ? trim($imodNode->nodeValue) : '';
                            $itemname = $inameNode ? trim($inameNode->nodeValue) : '';
                            $itemnumber = $inumNode && trim($inumNode->nodeValue) !== '$@NULL@$' ? (int)trim($inumNode->nodeValue) : 0;
                            $grademax = $gmaxNode && is_numeric(trim($gmaxNode->nodeValue)) ? (float)trim($gmaxNode->nodeValue) : 100.0;
                            $grademin = $gminNode && is_numeric(trim($gminNode->nodeValue)) ? (float)trim($gminNode->nodeValue) : 0.0;

                            if ($itemmodule === '$@NULL@$') {
                                $itemmodule = '';
                            }
                            if ($itemname === '$@NULL@$') {
                                $itemname = '';
                            }

                            $ggnodes = $gxpath->query('grade_grades/grade_grade', $ginode);
                            if ($ggnodes) {
                                foreach ($ggnodes as $ggnode) {
                                    $uidNode = $gxpath->query('userid', $ggnode)->item(0);
                                    $rawNode = $gxpath->query('rawgrade', $ggnode)->item(0);
                                    $finNode = $gxpath->query('finalgrade', $ggnode)->item(0);
                                    $fbNode  = $gxpath->query('feedback', $ggnode)->item(0);
                                    $fbfNode = $gxpath->query('feedbackformat', $ggnode)->item(0);
                                    $tmNode  = $gxpath->query('timemodified', $ggnode)->item(0);

                                    $xmluid = $uidNode ? (int)trim($uidNode->nodeValue) : 0;
                                    if ($xmluid <= 0) {
                                        continue;
                                    }

                                    $rawstr = $rawNode ? trim($rawNode->nodeValue) : '$@NULL@$';
                                    $finstr = $finNode ? trim($finNode->nodeValue) : '$@NULL@$';
                                    $fbstr  = $fbNode ? trim($fbNode->nodeValue) : '';
                                    if ($fbstr === '$@NULL@$') {
                                        $fbstr = '';
                                    }

                                    $rawval = ($rawstr !== '$@NULL@$' && $rawstr !== '' && is_numeric($rawstr)) ? (float)$rawstr : null;
                                    $finval = ($finstr !== '$@NULL@$' && $finstr !== '' && is_numeric($finstr)) ? (float)$finstr : null;

                                    if ($finval === null && $rawval === null && $fbstr === '') {
                                        continue;
                                    }

                                    $usergradesmap[$xmluid][] = [
                                        'itemtype' => $itemtype,
                                        'itemmodule' => $itemmodule,
                                        'itemname' => $itemname,
                                        'normname' => self::normalize_name($itemname),
                                        'itemnumber' => $itemnumber,
                                        'grademax' => $grademax,
                                        'grademin' => $grademin,
                                        'rawgrade' => $rawval,
                                        'finalgrade' => $finval ?? $rawval,
                                        'feedback' => $fbstr,
                                        'feedbackformat' => $fbfNode && is_numeric(trim($fbfNode->nodeValue)) ? (int)trim($fbfNode->nodeValue) : FORMAT_HTML,
                                        'timemodified' => $tmNode && is_numeric(trim($tmNode->nodeValue)) ? (int)trim($tmNode->nodeValue) : time(),
                                    ];
                                }
                            }
                        }
                    }
                }
            } catch (Throwable $e) {
                // Non-fatal: continue.
            }
        }

        // 2b. Parse activities/[mod]/customcert.xml and coursecertificate.xml to snapshot issued certificates
        //     before restore_controller runs (protects cross-site Workplace tool_certificate_issues).
        $usercertsmap = [];
        $certxmlfiles = [];
        foreach (['customcert', 'coursecertificate'] as $cmod) {
            $cglob = @glob($base . '/activities/' . $cmod . '_*/' . $cmod . '.xml');
            if (is_array($cglob)) {
                foreach ($cglob as $cf) {
                    $certxmlfiles[] = ['mod' => $cmod, 'file' => $cf];
                }
            }
        }
        foreach ($certxmlfiles as $centry) {
            $cfile = $centry['file'];
            $cmod = $centry['mod'];
            if (!is_readable($cfile)) {
                continue;
            }
            try {
                $cdom = new DOMDocument('1.0', 'UTF-8');
                if (@$cdom->load($cfile)) {
                    $cxpath = new DOMXPath($cdom);
                    $nameNode = $cxpath->query('//activity/' . $cmod . '/name')->item(0);
                    $actname = $nameNode ? trim($nameNode->nodeValue) : '';
                    $inodes = $cxpath->query('//issues/issue');
                    if ($inodes) {
                        foreach ($inodes as $inode) {
                            $uidNode = $cxpath->query('userid', $inode)->item(0);
                            $codeNode = $cxpath->query('code', $inode)->item(0);
                            $tcNode = $cxpath->query('timecreated', $inode)->item(0);
                            $expNode = $cxpath->query('expires', $inode)->item(0);
                            $dataNode = $cxpath->query('data', $inode)->item(0);
                            $emNode = $cxpath->query('emailed', $inode)->item(0);
                            $tidNode = $cxpath->query('templateid', $inode)->item(0);

                            $xmluid = $uidNode ? (int)trim($uidNode->nodeValue) : 0;
                            $code = $codeNode ? trim($codeNode->nodeValue) : '';
                            if ($code === '$@NULL@$') {
                                $code = '';
                            }
                            if ($xmluid <= 0 || $code === '') {
                                continue;
                            }
                            $rawdata = $dataNode ? trim($dataNode->nodeValue) : '';
                            if ($rawdata === '$@NULL@$') {
                                $rawdata = '';
                            }
                            $usercertsmap[$xmluid][] = [
                                'mod' => $cmod,
                                'actname' => $actname,
                                'normname' => self::normalize_name($actname),
                                'code' => $code,
                                'timecreated' => $tcNode && is_numeric(trim($tcNode->nodeValue)) ? (int)trim($tcNode->nodeValue) : time(),
                                'expires' => $expNode && is_numeric(trim($expNode->nodeValue)) ? (int)trim($expNode->nodeValue) : 0,
                                'data' => $rawdata,
                                'emailed' => $emNode && is_numeric(trim($emNode->nodeValue)) ? (int)trim($emNode->nodeValue) : 0,
                                'templateid' => $tidNode && is_numeric(trim($tidNode->nodeValue)) ? (int)trim($tidNode->nodeValue) : 0,
                            ];
                        }
                    }
                }
            } catch (Throwable $e) {
                // Non-fatal: continue.
            }
        }

        // 3. Parse and harmonize users.xml against local mdl_user (exact proven v1.0.0 flow + pre-creation guarantee).
        $usersxml = $base . '/users.xml';
        $identities = [];
        if (!file_exists($usersxml) || !is_readable($usersxml)) {
            return $identities;
        }

        try {
            $dom = new DOMDocument('1.0', 'UTF-8');
            $dom->preserveWhiteSpace = true;
            $dom->formatOutput = false;
            if (@$dom->load($usersxml)) {
                $xpath = new DOMXPath($dom);
                $usernodes = $xpath->query('//users/user');
                $modified = false;

                if ($usernodes) {
                    foreach ($usernodes as $unode) {
                        if (!($unode instanceof DOMElement)) {
                            continue;
                        }
                        $xmluid = (int)$unode->getAttribute('id');
                        if ($xmluid <= 0) {
                            $idChild = $xpath->query('id', $unode)->item(0);
                            $xmluid = $idChild ? (int)trim($idChild->nodeValue) : 0;
                        }

                        $unameNode = $xpath->query('username', $unode)->item(0);
                        $emailNode = $xpath->query('email', $unode)->item(0);
                        $fnNode    = $xpath->query('firstname', $unode)->item(0);
                        $lnNode    = $xpath->query('lastname', $unode)->item(0);
                        $idnumNode = $xpath->query('idnumber', $unode)->item(0);
                        $cityNode  = $xpath->query('city', $unode)->item(0);
                        $ctryNode  = $xpath->query('country', $unode)->item(0);
                        $langNode  = $xpath->query('lang', $unode)->item(0);

                        $uname = $unameNode ? trim($unameNode->nodeValue) : '';
                        $email = $emailNode ? trim($emailNode->nodeValue) : '';
                        $firstname = $fnNode ? trim($fnNode->nodeValue) : '';
                        $lastname = $lnNode ? trim($lnNode->nodeValue) : '';
                        $idnumber = $idnumNode ? trim($idnumNode->nodeValue) : '';
                        if ($idnumber === '$@NULL@$') {
                            $idnumber = '';
                        }

                        if ($uname === '' && $email === '') {
                            continue;
                        }
                        if ($uname === 'guest') {
                            continue;
                        }

                        // If the XML node is admin, align its XML fields with local admin so precheck_user() never conflicts,
                        // then skip adding admin to student $identities.
                        if ($uname === 'admin') {
                            $localadmin = $DB->get_record('user', ['username' => 'admin', 'deleted' => 0]);
                            if ($localadmin) {
                                if (self::update_existing_xml_child($dom, $xpath, $unode, 'email', (string)$localadmin->email)) {
                                    $modified = true;
                                }
                                if (self::update_existing_xml_child($dom, $xpath, $unode, 'firstaccess', (string)(int)$localadmin->firstaccess)) {
                                    $modified = true;
                                }
                                if (self::update_existing_xml_child($dom, $xpath, $unode, 'timecreated', (string)(int)$localadmin->timecreated)) {
                                    $modified = true;
                                }
                                if (self::update_existing_xml_child($dom, $xpath, $unode, 'mnethostid', (string)$localhostid)) {
                                    $modified = true;
                                }
                            }
                            continue;
                        }

                        // Check if this user already exists in the target IOMAD Moodle (by email first, then username).
                        $existing = null;
                        if ($email !== '') {
                            $matches = $DB->get_records_select(
                                'user',
                                'LOWER(email) = LOWER(:email) AND deleted = 0',
                                ['email' => $email],
                                'id ASC',
                                '*',
                                0,
                                1
                            );
                            if (!empty($matches)) {
                                $existing = reset($matches);
                            }
                        }
                        if (!$existing && $uname !== '') {
                            $matches = $DB->get_records_select(
                                'user',
                                'LOWER(username) = LOWER(:uname) AND deleted = 0',
                                ['uname' => $uname],
                                'id ASC',
                                '*',
                                0,
                                1
                            );
                            if (!empty($matches)) {
                                $existing = reset($matches);
                            }
                        }

                        $identprofile = [
                            'xmluid' => $xmluid,
                            'targetuserid' => 0,
                            'username' => $uname,
                            'email' => $email,
                            'firstname' => $firstname !== '' ? $firstname : $uname,
                            'lastname' => $lastname !== '' ? $lastname : 'Estudiante',
                            'idnumber' => $idnumber,
                            'city' => $cityNode && trim($cityNode->nodeValue) !== '$@NULL@$' ? trim($cityNode->nodeValue) : '',
                            'country' => $ctryNode && trim($ctryNode->nodeValue) !== '$@NULL@$' ? trim($ctryNode->nodeValue) : '',
                            'lang' => $langNode && trim($langNode->nodeValue) !== '$@NULL@$' ? trim($langNode->nodeValue) : '',
                            'grades' => $usergradesmap[$xmluid] ?? [],
                            'certificates' => $usercertsmap[$xmluid] ?? [],
                        ];

                        if (!$existing) {
                            // Create the user in mdl_user right away so they 100% exist before restore_controller starts.
                            $newuid = self::create_missing_user_from_identity($identprofile);
                            if ($newuid > 0) {
                                $existing = $DB->get_record('user', ['id' => $newuid]);
                            }
                        }

                        if ($existing) {
                            if (is_siteadmin((int)$existing->id)) {
                                continue;
                            }
                            // Align ONLY existing XML child nodes with existing target user so Moodle maps them 1:1 with zero conflicts.
                            $uname = (string)$existing->username;
                            $email = (string)$existing->email;
                            $identprofile['targetuserid'] = (int)$existing->id;
                            $identprofile['username'] = $uname;
                            $identprofile['email'] = $email;

                            if (self::update_existing_xml_child($dom, $xpath, $unode, 'username', $uname)) {
                                $modified = true;
                            }
                            if (self::update_existing_xml_child($dom, $xpath, $unode, 'email', $email)) {
                                $modified = true;
                            }
                            if (self::update_existing_xml_child($dom, $xpath, $unode, 'idnumber', (string)$existing->idnumber)) {
                                $modified = true;
                            }
                            if (self::update_existing_xml_child($dom, $xpath, $unode, 'firstaccess', (string)(int)$existing->firstaccess)) {
                                $modified = true;
                            }
                            if (self::update_existing_xml_child($dom, $xpath, $unode, 'timecreated', (string)(int)$existing->timecreated)) {
                                $modified = true;
                            }
                        }

                        if (self::update_existing_xml_child($dom, $xpath, $unode, 'mnethostid', (string)$localhostid)) {
                            $modified = true;
                        }

                        $identities[] = $identprofile;
                    }
                }

                if ($modified) {
                    @$dom->save($usersxml);
                }
            }
        } catch (Throwable $e) {
            // Non-fatal: continue with standard restore.
        }

        return $identities;
    }

    /**
     * Creates a missing student user in mdl_user from their backup XML identity record if they do not yet exist.
     *
     * @param array $ident
     * @return int Created or resolved user ID (0 on failure)
     */
    protected static function create_missing_user_from_identity(array $ident): int {
        global $DB, $CFG;

        $uname = trim((string)($ident['username'] ?? ''));
        $email = trim((string)($ident['email'] ?? ''));
        if ($uname === '' && $email === '') {
            return 0;
        }
        if ($uname === '') {
            $uname = strtolower($email);
        }
        if ($email === '') {
            $email = $uname . '@example.invalid';
        }

        // Check again if already present in mdl_user.
        $matches = $DB->get_records_select(
            'user',
            '(LOWER(email) = LOWER(:email) OR LOWER(username) = LOWER(:uname)) AND deleted = 0',
            ['email' => $email, 'uname' => $uname],
            'id ASC',
            'id',
            0,
            1
        );
        if (!empty($matches)) {
            $u = reset($matches);
            return (int)$u->id;
        }

        try {
            $localhostid = !empty($CFG->mnet_localhost_id) ? (int)$CFG->mnet_localhost_id : 1;
            $now = time();

            $newuser = new stdClass();
            $newuser->auth = 'manual';
            $newuser->confirmed = 1;
            $newuser->policyagreed = 0;
            $newuser->deleted = 0;
            $newuser->suspended = 0;
            $newuser->mnethostid = $localhostid;
            $newuser->username = \core_text::strtolower($uname);
            $newuser->password = 'not cached';
            $newuser->idnumber = (string)($ident['idnumber'] ?? '');
            $newuser->firstname = trim((string)($ident['firstname'] ?? '')) !== '' ? trim((string)$ident['firstname']) : $uname;
            $newuser->lastname = trim((string)($ident['lastname'] ?? '')) !== '' ? trim((string)$ident['lastname']) : 'Estudiante';
            $newuser->email = $email;
            $newuser->city = (string)($ident['city'] ?? '');
            $newuser->country = strlen((string)($ident['country'] ?? '')) === 2 ? (string)$ident['country'] : '';
            $newuser->lang = !empty($ident['lang']) && get_string_manager()->translation_exists((string)$ident['lang'])
                ? (string)$ident['lang']
                : (!empty($CFG->lang) ? (string)$CFG->lang : 'es');
            $newuser->firstaccess = 0;
            $newuser->timecreated = $now;
            $newuser->timemodified = $now;

            $newid = (int)$DB->insert_record('user', $newuser);
            if ($newid > 0) {
                context_user::instance($newid);
                return $newid;
            }
        } catch (Throwable $e) {
            // Ignore and return 0.
        }

        return 0;
    }

    /**
     * Guarantees that every user in $backupidentities is explicitly mapped in mdl_backup_ids_temp
     * (itemname = "user", itemid = $xmluid, newitemid = $targetuserid) right after $rc->execute_precheck().
     *
     * @param restore_controller $rc
     * @param array $backupidentities
     */
    public static function ensure_precheck_user_mappings(restore_controller $rc, array $backupidentities): void {
        global $DB;
        if (empty($backupidentities)) {
            return;
        }

        try {
            $restoreid = $rc->get_restoreid();
            foreach ($backupidentities as $ident) {
                $xmluid = (int)($ident['xmluid'] ?? 0);
                if ($xmluid <= 0) {
                    continue;
                }

                $targetuid = (int)($ident['targetuserid'] ?? 0);
                if ($targetuid <= 0 || !$DB->record_exists('user', ['id' => $targetuid, 'deleted' => 0])) {
                    $targetuid = self::create_missing_user_from_identity($ident);
                }

                if ($targetuid > 0) {
                    $rec = restore_dbops::get_backup_ids_record($restoreid, 'user', $xmluid);
                    if (!$rec || (int)$rec->newitemid !== $targetuid) {
                        restore_dbops::set_backup_ids_record($restoreid, 'user', $xmluid, $targetuid);
                    }
                }
            }
        } catch (Throwable $e) {
            // Non-fatal: continue.
        }
    }

    /**
     * Transitions restore_controller status to backup::STATUS_AWAITING if precheck reported a recoverable warning/error
     * so $rc->execute_plan() can proceed without throwing restore_not_executable_awaiting_required.
     *
     * @param restore_controller $rc
     */
    public static function force_controller_awaiting(restore_controller $rc): void {
        if ($rc->get_status() === backup::STATUS_AWAITING) {
            return;
        }
        try {
            $ref = new ReflectionClass($rc);
            while ($ref && !$ref->hasProperty('status')) {
                $ref = $ref->getParentClass();
            }
            if ($ref && $ref->hasProperty('status')) {
                $prop = $ref->getProperty('status');
                $prop->setAccessible(true);
                $prop->setValue($rc, backup::STATUS_AWAITING);
            }
        } catch (Throwable $e) {
            // Ignore reflection fallback errors.
        }
    }

    /**
     * Captures a complete snapshot of the target Master Course BEFORE restore_controller runs.
     *
     * @param int $courseid
     * @return array
     */
    public static function snapshot_course_state(int $courseid): array {
        global $DB;

        $mastercms = [];
        $sql = "SELECT cm.id AS cmid, cm.module, cm.instance, cm.section, cm.idnumber,
                       cs.section AS sectionnum, m.name AS modname
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module
                  LEFT JOIN {course_sections} cs ON cs.id = cm.section
                 WHERE cm.course = :courseid AND cm.deletioninprogress = 0
              ORDER BY cs.section ASC, cm.id ASC";
        $cmrows = $DB->get_records_sql($sql, ['courseid' => $courseid]);

        foreach ($cmrows as $row) {
            $actname = '';
            try {
                $inst = $DB->get_record($row->modname, ['id' => $row->instance], 'id, name');
                if ($inst && isset($inst->name)) {
                    $actname = trim((string)$inst->name);
                }
            } catch (Throwable $e) {
                $actname = '';
            }
            $row->actname = $actname;
            $row->normname = self::normalize_name($actname);
            $mastercms[(int)$row->cmid] = $row;
        }

        // Snapshot existing course groups so we never delete IOMAD groups, and can remove any stray ones.
        $groups = $DB->get_records('groups', ['courseid' => $courseid], 'id ASC', 'id, name, idnumber');

        // Snapshot existing enrolled user IDs.
        $ctx = context_course::instance($courseid);
        $enrolled = get_enrolled_users($ctx, '', 0, 'u.id');
        $enrolledids = array_map('intval', array_keys($enrolled));

        return [
            'courseid' => $courseid,
            'master_cms' => $mastercms,
            'group_ids' => array_map('intval', array_keys($groups)),
            'enrolled_userids' => $enrolledids,
        ];
    }

    /**
     * Executes the post-restore IOMAD consolidation inside a delegated database transaction:
     * 1. Resolves (or creates if missing) all incoming student user IDs from the backup and enrolls them.
     * 2. If $consolidateactivities is true (Mode 5), transfers all quiz attempts, assignment submissions,
     *    SCORM tracks, completions, and grades from the newly restored duplicate modules into the
     *    Master Course existing modules, then deletes the temporary duplicate modules cleanly.
     * 3. Backfills any missing activity or course grades from the pre-extracted XML grade snapshot and runs
     *    grade_regrade_final_grades() so the Moodle Gradebook is 100% populated.
     * 4. Binds the course and ONLY the migrated students to the SINGLE target IOMAD company ($companyid)
     *    & root department, creates/verifies the official IOMAD company course group, invokes company_user::enrol(),
     *    assigns the migrated students to that company group, and populates mdl_local_iomad_track.
     * 5. Purges any stray non-IOMAD groups that may have been created during restore.
     *
     * @param int $courseid
     * @param int $companyid
     * @param array $snapshot Pre-restore snapshot from snapshot_course_state()
     * @param array $backupidentities Student identities parsed from users.xml
     * @param bool $consolidateactivities True when consolidating into an existing Master Course (Mode 5)
     * @return array Summary statistics of consolidated items
     */
    public static function consolidate_after_restore(
        int $courseid,
        int $companyid,
        array $snapshot,
        array $backupidentities = [],
        bool $consolidateactivities = true
    ): array {
        global $DB;

        $summary = [
            'users_processed' => 0,
            'users_assigned_to_company' => 0,
            'users_added_to_iomad_group' => 0,
            'iomad_groupid' => 0,
            'migrated_companyid' => (int)$companyid,
            'migrated_userids' => [],
            'activities_consolidated' => 0,
            'quiz_attempts_moved' => 0,
            'assign_submissions_moved' => 0,
            'scorm_tracks_moved' => 0,
            'completions_moved' => 0,
            'grades_moved' => 0,
            'duplicate_modules_removed' => 0,
        ];

        $transaction = $DB->start_delegated_transaction();

        try {
            // 1. Identify incoming migrated student user IDs from this backup (creating any missing user in mdl_user).
            $incominguserids = self::resolve_incoming_userids($courseid, $snapshot['enrolled_userids'] ?? [], $backupidentities);

            // 2. Ensure incoming migrated users are enrolled in the course with the student role before grade merging.
            self::ensure_users_enrolled_as_students($courseid, $incominguserids);

            // 3. Consolidate restored activities into Master Course activities (if Mode 5 and Master Course had activities).
            $mastercms = $snapshot['master_cms'] ?? [];
            if ($consolidateactivities && !empty($mastercms)) {
                self::consolidate_course_activities($courseid, $mastercms, $summary);
            } else {
                // For a fresh course restore (or empty master course), populate summary metrics from the restored course data.
                self::populate_fresh_restore_metrics($courseid, $incominguserids, $summary);
            }

            // 4. Verify and backfill any grades from the extracted backup XMLs that were not written during restore.
            self::ensure_extracted_grades_applied($courseid, $backupidentities, $summary);

            // 4b. Verify and backfill any CustomCert or Workplace tool_certificate issues captured from the backup XMLs.
            self::ensure_extracted_certificates_applied($courseid, $backupidentities);

            // 5. Force Moodle gradebook recalculation so course total (Total del curso) is immediately computed.
            try {
                grade_regrade_final_grades($courseid);
            } catch (Throwable $e) {
                // Non-fatal if gradebook recalculation emits warnings.
            }

            $summary['users_processed'] = count($incominguserids);
            $summary['migrated_userids'] = array_values(array_map('intval', $incominguserids));

            // 6. Bind the course and ONLY the migrated students to the SINGLE explicitly assigned IOMAD company.
            if (security_manager::has_iomad() && $companyid > 0) {
                $groupid = self::ensure_iomad_company_and_group_binding($courseid, $companyid, $incominguserids, $summary);
                $summary['iomad_groupid'] = $groupid;
            }

            // 7. Re-verify active student enrolment after IOMAD binding so get_enrolled_sql() in IOMAD selectors is 100% active.
            self::ensure_users_enrolled_as_students($courseid, $incominguserids);

            // 8. Clean up any stray groups created during restore that were not in the pre-restore snapshot
            // and are not registered in IOMAD company_course_groups.
            self::cleanup_stray_groups($courseid, $snapshot['group_ids'] ?? []);

            $transaction->allow_commit();
        } catch (Throwable $e) {
            $transaction->rollback($e);
            throw $e;
        }

        // Rebuild course cache outside transaction.
        try {
            rebuild_course_cache($courseid, true);
        } catch (Throwable $e) {
            // Ignore cache rebuild warnings.
        }

        return $summary;
    }

    /**
     * Populates summary metrics when restoring into a brand-new course (or empty Master Course)
     * so logs.php accurately displays the restored activities, quiz attempts, submissions, grades, and completions.
     *
     * @param int $courseid
     * @param int[] $userids
     * @param array $summary
     */
    protected static function populate_fresh_restore_metrics(int $courseid, array $userids, array &$summary): void {
        global $DB;
        try {
            $summary['activities_consolidated'] = (int)$DB->count_records_select(
                'course_modules',
                'course = :cid AND deletioninprogress = 0',
                ['cid' => $courseid]
            );
            if (!empty($userids)) {
                list($usql, $uparams) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'uid');
                $uparams['cid'] = $courseid;

                $summary['quiz_attempts_moved'] = (int)$DB->count_records_sql(
                    "SELECT COUNT(qa.id)
                       FROM {quiz_attempts} qa
                       JOIN {quiz} q ON q.id = qa.quiz
                      WHERE q.course = :cid AND qa.userid {$usql}",
                    $uparams
                );
                $summary['assign_submissions_moved'] = (int)$DB->count_records_sql(
                    "SELECT COUNT(asub.id)
                       FROM {assign_submission} asub
                       JOIN {assign} a ON a.id = asub.assignment
                      WHERE a.course = :cid AND asub.userid {$usql}",
                    $uparams
                );
                $summary['completions_moved'] = (int)$DB->count_records_sql(
                    "SELECT COUNT(cmc.id)
                       FROM {course_modules_completion} cmc
                       JOIN {course_modules} cm ON cm.id = cmc.coursemoduleid
                      WHERE cm.course = :cid AND cmc.userid {$usql}",
                    $uparams
                );
                $summary['grades_moved'] = (int)$DB->count_records_sql(
                    "SELECT COUNT(gg.id)
                       FROM {grade_grades} gg
                       JOIN {grade_items} gi ON gi.id = gg.itemid
                      WHERE gi.courseid = :cid AND gi.itemtype = 'mod' AND gg.finalgrade IS NOT NULL AND gg.userid {$usql}",
                    $uparams
                );
            }
        } catch (Throwable $e) {
            // Non-fatal: continue.
        }
    }

    /**
     * Verifies that every student grade captured from the extracted backup XMLs (activities/[mod]/grades.xml
     * and gradebook.xml) is present in mdl_grade_grades for the target course, inserting or updating any missing grade.
     *
     * @param int $courseid
     * @param array $backupidentities
     * @param array $summary
     */
    protected static function ensure_extracted_grades_applied(int $courseid, array $backupidentities, array &$summary): void {
        global $DB;
        if (empty($backupidentities)) {
            return;
        }

        try {
            $coursegitems = $DB->get_records('grade_items', ['courseid' => $courseid]);
            if (empty($coursegitems)) {
                return;
            }

            foreach ($backupidentities as $ident) {
                if (empty($ident['grades']) || !is_array($ident['grades'])) {
                    continue;
                }

                $uid = (int)($ident['targetuserid'] ?? 0);
                if ($uid <= 0 && !empty($ident['email'])) {
                    $matches = $DB->get_records_select(
                        'user',
                        'LOWER(email) = LOWER(:email) AND deleted = 0',
                        ['email' => $ident['email']],
                        'id ASC',
                        'id',
                        0,
                        1
                    );
                    if (!empty($matches)) {
                        $u = reset($matches);
                        $uid = (int)$u->id;
                    }
                }
                if ($uid <= 0 && !empty($ident['username'])) {
                    $matches = $DB->get_records_select(
                        'user',
                        'LOWER(username) = LOWER(:uname) AND deleted = 0',
                        ['uname' => $ident['username']],
                        'id ASC',
                        'id',
                        0,
                        1
                    );
                    if (!empty($matches)) {
                        $u = reset($matches);
                        $uid = (int)$u->id;
                    }
                }
                if ($uid <= 2 || is_siteadmin($uid)) {
                    continue;
                }

                foreach ($ident['grades'] as $gentry) {
                    $itype = (string)($gentry['itemtype'] ?? 'mod');
                    $imod  = (string)($gentry['itemmodule'] ?? '');
                    $norm  = (string)($gentry['normname'] ?? '');
                    $inum  = (int)($gentry['itemnumber'] ?? 0);
                    $fin   = $gentry['finalgrade'];
                    $raw   = $gentry['rawgrade'];

                    $matchedgi = null;
                    if ($itype === 'course') {
                        foreach ($coursegitems as $gi) {
                            if ($gi->itemtype === 'course') {
                                $matchedgi = $gi;
                                break;
                            }
                        }
                    } else if ($itype === 'mod' && $imod !== '') {
                        foreach ($coursegitems as $gi) {
                            if ($gi->itemtype === 'mod' &&
                                $gi->itemmodule === $imod &&
                                (int)$gi->itemnumber === $inum &&
                                $norm !== '' &&
                                self::normalize_name((string)$gi->itemname) === $norm) {
                                $matchedgi = $gi;
                                break;
                            }
                        }
                        if (!$matchedgi) {
                            $candidates = [];
                            foreach ($coursegitems as $gi) {
                                if ($gi->itemtype === 'mod' && $gi->itemmodule === $imod && (int)$gi->itemnumber === $inum) {
                                    $candidates[] = $gi;
                                }
                            }
                            if (count($candidates) === 1) {
                                $matchedgi = $candidates[0];
                            }
                        }
                    } else if ($itype === 'manual' && $norm !== '') {
                        foreach ($coursegitems as $gi) {
                            if ($gi->itemtype === 'manual' && self::normalize_name((string)$gi->itemname) === $norm) {
                                $matchedgi = $gi;
                                break;
                            }
                        }
                    }

                    if (!$matchedgi) {
                        continue;
                    }

                    $existinggg = $DB->get_record('grade_grades', [
                        'itemid' => $matchedgi->id,
                        'userid' => $uid,
                    ]);

                    if (!$existinggg) {
                        $newgg = new stdClass();
                        $newgg->itemid = (int)$matchedgi->id;
                        $newgg->userid = $uid;
                        $newgg->rawgrade = $raw;
                        $newgg->rawgrademax = (float)($gentry['grademax'] ?? $matchedgi->grademax);
                        $newgg->rawgrademin = (float)($gentry['grademin'] ?? $matchedgi->grademin);
                        $newgg->finalgrade = $fin;
                        $newgg->hidden = 0;
                        $newgg->locked = 0;
                        $newgg->overridden = 0;
                        $newgg->excluded = 0;
                        $newgg->feedback = (string)($gentry['feedback'] ?? '');
                        $newgg->feedbackformat = (int)($gentry['feedbackformat'] ?? FORMAT_HTML);
                        $newgg->timecreated = (int)($gentry['timemodified'] ?? time());
                        $newgg->timemodified = $newgg->timecreated;
                        $DB->insert_record('grade_grades', $newgg);
                        if ($itype === 'mod') {
                            $summary['grades_moved']++;
                        }
                    } else if ($fin !== null && ($existinggg->finalgrade === null || (float)$fin > (float)$existinggg->finalgrade)) {
                        $wasnull = ($existinggg->finalgrade === null);
                        $existinggg->rawgrade = $raw ?? $existinggg->rawgrade;
                        $existinggg->finalgrade = $fin;
                        if (!empty($gentry['feedback'])) {
                            $existinggg->feedback = (string)$gentry['feedback'];
                            $existinggg->feedbackformat = (int)($gentry['feedbackformat'] ?? FORMAT_HTML);
                        }
                        $existinggg->timemodified = max((int)$existinggg->timemodified, (int)($gentry['timemodified'] ?? time()));
                        $DB->update_record('grade_grades', $existinggg);
                        if ($wasnull && $itype === 'mod') {
                            $summary['grades_moved']++;
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            // Non-fatal: continue.
        }
    }

    /**
     * Surgically synchronizes ONLY the completed migration requests in local_iomadtransfer_request:
     * - Operates exclusively on courses that were migrated by local_iomadtransfer.
     * - Operates exclusively on the single IOMAD company ($req->companyid) assigned to each migration request.
     * - Operates exclusively on the specific student(s) migrated in that request (and recovers any student from
     *   sibling requests of the same origin course if an earlier run had users_processed == 0).
     *
     * @param int $specificcourseid Optional course ID filter
     * @param int $specificcompanyid Optional company ID filter
     * @param int $specificrequestid Optional single request ID filter
     * @return array Summary metrics
     */
    /**
     * Checks whether a user is an IOMAD Company Manager, Department Manager, or Educator
     * in mdl_company_users (either globally or in a specific company) so they are never
     * mistaken for a regular migrated student or demoted during student synchronization.
     *
     * @param int $userid
     * @param int $companyid Optional company filter (0 = check across all companies)
     * @return bool
     */
    public static function is_iomad_manager_or_educator(int $userid, int $companyid = 0): bool {
        global $DB;
        if ($userid <= 2) {
            return false;
        }
        $tblcompusers = security_manager::resolve_iomad_table('company_users');
        if (!$tblcompusers) {
            return false;
        }
        try {
            $cucols = $DB->get_columns($tblcompusers);
            $compcol = isset($cucols['companyid']) ? 'companyid' : 'company';
            $where = 'userid = :uid AND (managertype > 0 OR educator > 0)';
            $params = ['uid' => $userid];
            if ($companyid > 0) {
                $where .= " AND {$compcol} = :cid";
                $params['cid'] = $companyid;
            }
            return $DB->record_exists_select($tblcompusers, $where, $params);
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Additively and idempotently synchronizes completed migration requests in local_iomadtransfer_request:
     * - Strictly respects the requested scope ($specificrequestid, $specificcourseid, $specificcompanyid).
     * - Never deletes groups, never removes group members, and never unassigns other companies or managers.
     * - Syncs migrated students first and then synchronizes Company Managers (across all company subgroups)
     *   and CustomCert certificates.
     *
     * @param int $specificcourseid Optional course ID filter
     * @param int $specificcompanyid Optional company ID filter
     * @param int $specificrequestid Optional single request ID filter
     * @return array Summary metrics
     */
    public static function repair_and_sync_all_iomad_courses(
        int $specificcourseid = 0,
        int $specificcompanyid = 0,
        int $specificrequestid = 0
    ): array {
        global $DB;

        $stats = [
            'courses_synced' => 0,
            'groups_verified' => 0,
            'users_synced' => 0,
            'managers_synced' => 0,
            'certificates_verified' => 0,
            'templates_imported' => 0,
            'cleaned_bindings' => 0,
        ];

        if (!security_manager::has_iomad() || !$DB->get_manager()->table_exists('local_iomadtransfer_request')) {
            return $stats;
        }

        $select = 'direction = 0 AND status = 100 AND target_course_id > 1 AND companyid > 0';
        $params = [];
        if ($specificrequestid > 0) {
            $select .= ' AND id = :reqid';
            $params['reqid'] = $specificrequestid;
        }
        if ($specificcourseid > 1) {
            $select .= ' AND target_course_id = :cid';
            $params['cid'] = $specificcourseid;
        }
        if ($specificcompanyid > 0) {
            $select .= ' AND companyid = :compid';
            $params['compid'] = $specificcompanyid;
        }

        $reqs = $DB->get_records_select('local_iomadtransfer_request', $select, $params, 'id ASC');
        if (empty($reqs)) {
            return $stats;
        }

        $syncedcourses = [];
        foreach ($reqs as $req) {
            $cid = (int)$req->target_course_id;
            $compid = (int)$req->companyid;
            if ($cid <= 1 || $compid <= 0 || !$DB->record_exists('course', ['id' => $cid])) {
                continue;
            }

            try {
                // 1. Resolve and sync only the migrated student user IDs for this request.
                $uids = self::resolve_request_migrated_userids($req);
                self::ensure_users_enrolled_as_students($cid, $uids);

                $summary = [
                    'users_processed' => count($uids),
                    'users_assigned_to_company' => 0,
                    'users_added_to_iomad_group' => 0,
                    'iomad_groupid' => 0,
                ];
                $gid = self::ensure_iomad_company_and_group_binding($cid, $compid, $uids, $summary);
                self::ensure_users_enrolled_as_students($cid, $uids);

                // Persist company_groupid and updated summary metrics on the request record.
                $reqchanged = false;
                if ($gid > 0 && (int)$req->company_groupid !== $gid) {
                    $req->company_groupid = $gid;
                    $reqchanged = true;
                }
                $sumdata = !empty($req->consolidate_summary) ? json_decode($req->consolidate_summary, true) : [];
                if (!is_array($sumdata)) {
                    $sumdata = [];
                }
                if (!isset($sumdata['migrated_userids']) || $sumdata['migrated_userids'] !== $uids || (int)($sumdata['users_processed'] ?? 0) !== count($uids)) {
                    $sumdata['migrated_userids'] = $uids;
                    $sumdata['migrated_companyid'] = $compid;
                    $sumdata['users_processed'] = count($uids);
                    if ((int)($sumdata['users_assigned_to_company'] ?? 0) < count($uids)) {
                        $sumdata['users_assigned_to_company'] = count($uids);
                    }
                    if ((int)($sumdata['users_added_to_iomad_group'] ?? 0) < count($uids) && $gid > 0) {
                        $sumdata['users_added_to_iomad_group'] = count($uids);
                    }
                    if ($gid > 0) {
                        $sumdata['iomad_groupid'] = $gid;
                    }
                    $req->consolidate_summary = json_encode($sumdata);
                    $reqchanged = true;
                }
                if ($reqchanged) {
                    $DB->update_record('local_iomadtransfer_request', $req);
                }

                // 2. Automatically import and re-link any missing Workplace tool_certificate templates from Origin
                //    so mod_coursecertificate activities never point to a non-existent template ID.
                $tplsync = self::sync_and_import_origin_certificate_templates($req);
                $stats['templates_imported'] += (int)($tplsync['templates_imported'] ?? 0);

                // 3. Always synchronize Company Managers (across all subgroups of this company) and CustomCert
                //    AFTER student sync so managers retain all their company groups untouched.
                if (method_exists(request_manager::class, 'sync_company_managers_and_certificates')) {
                    $ext = request_manager::sync_company_managers_and_certificates($req);
                    $stats['managers_synced'] += (int)($ext['company_managers_synced'] ?? 0);
                    $stats['certificates_verified'] += (int)($ext['customcert_issued_count'] ?? 0);
                }

                $syncedcourses[$cid] = true;
                if ($gid > 0) {
                    $stats['groups_verified']++;
                }
                $stats['users_synced'] += count($uids);
            } catch (Throwable $e) {
                // Continue syncing remaining requests.
            }
        }

        $stats['courses_synced'] = count($syncedcourses);
        return $stats;
    }

    /**
     * Resolves the exact list of Moodle student user IDs migrated in a specific local_iomadtransfer_request record.
     * Excludes any IOMAD Company Manager / Department Manager / Educator so managers are never treated as students.
     *
     * @param stdClass $req Record from local_iomadtransfer_request
     * @return int[]
     */
    protected static function resolve_request_migrated_userids(stdClass $req): array {
        global $DB;
        $cid = (int)$req->target_course_id;
        $sum = !empty($req->consolidate_summary) ? json_decode($req->consolidate_summary, true) : [];
        if (!is_array($sum)) {
            $sum = [];
        }

        // 1. If migrated_userids was explicitly stored in consolidate_summary and is non-empty, use it strictly.
        if (!empty($sum['migrated_userids']) && is_array($sum['migrated_userids'])) {
            $valid = [];
            foreach ($sum['migrated_userids'] as $rawid) {
                $uid = (int)$rawid;
                if ($uid > 2 &&
                    !is_siteadmin($uid) &&
                    !self::is_iomad_manager_or_educator($uid) &&
                    $DB->record_exists('user', ['id' => $uid, 'deleted' => 0])) {
                    $valid[$uid] = $uid;
                }
            }
            if (!empty($valid)) {
                return array_values($valid);
            }
        }

        // 2. Check students enrolled in $cid (excluding managers/educators and non-student roles).
        $expectedcount = isset($sum['users_processed']) && (int)$sum['users_processed'] > 0
            ? (int)$sum['users_processed']
            : 50;

        $tstart = max(0, (int)$req->timecreated - 300);
        $tend = (int)$req->timemodified + 600;

        $sql = "SELECT ue.userid,
                       MIN(ue.timecreated) AS uecreated,
                       (CASE WHEN MIN(ue.timecreated) BETWEEN :tstart AND :tend THEN 0 ELSE 1 END) AS timepriority
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON e.id = ue.enrolid
                  JOIN {user} u ON u.id = ue.userid
                 WHERE e.courseid = :courseid
                   AND u.deleted = 0
                   AND ue.userid > 2
              GROUP BY ue.userid
              ORDER BY timepriority ASC, ABS(MIN(ue.timecreated) - :reqtime) ASC";

        $rows = $DB->get_records_sql($sql, [
            'courseid' => $cid,
            'tstart' => $tstart,
            'tend' => $tend,
            'reqtime' => (int)$req->timecreated,
        ]);

        $resolved = [];
        foreach ($rows as $row) {
            $uid = (int)$row->userid;
            if ($uid <= 2 || is_siteadmin($uid) || self::is_iomad_manager_or_educator($uid)) {
                continue;
            }
            $resolved[$uid] = $uid;
            if (count($resolved) >= $expectedcount) {
                break;
            }
        }
        if (!empty($resolved)) {
            return array_values($resolved);
        }

        // 3. Self-healing fallback: check sibling requests for the exact same (siteurl, origin_course_id).
        if (!empty($req->siteurl) && (int)$req->origin_course_id > 0) {
            $siblings = $DB->get_records_select(
                'local_iomadtransfer_request',
                'direction = 0 AND origin_course_id = :ocid AND siteurl = :surl AND id <> :id AND target_course_id > 1',
                [
                    'ocid' => (int)$req->origin_course_id,
                    'surl' => (string)$req->siteurl,
                    'id' => (int)$req->id,
                ],
                'id DESC'
            );
            foreach ($siblings as $sib) {
                $sibsum = !empty($sib->consolidate_summary) ? json_decode($sib->consolidate_summary, true) : [];
                if (is_array($sibsum) && !empty($sibsum['migrated_userids']) && is_array($sibsum['migrated_userids'])) {
                    foreach ($sibsum['migrated_userids'] as $rawid) {
                        $uid = (int)$rawid;
                        if ($uid > 2 &&
                            !is_siteadmin($uid) &&
                            !self::is_iomad_manager_or_educator($uid) &&
                            $DB->record_exists('user', ['id' => $uid, 'deleted' => 0])) {
                            $resolved[$uid] = $uid;
                        }
                    }
                }
                if (!empty($resolved)) {
                    break;
                }
            }
        }

        return array_values($resolved);
    }

    /**
     * Kept as a safe no-op for backward compatibility. Synchronization is strictly additive
     * and never purges external companies, groups, or Company Managers from shared Master Courses.
     *
     * @param array $courseallowedcompanies
     * @param array $userallowedcompanies
     * @param array $courseallowedusers
     * @return int Always 0
     */
    protected static function cleanup_accidental_multicompany_bindings(
        array $courseallowedcompanies,
        array $userallowedcompanies,
        array $courseallowedusers
    ): int {
        return 0;
    }

    /**
     * Resolves all Moodle student user IDs belonging to the restored backup (creating any missing user in mdl_user).
     *
     * @param int $courseid
     * @param array $preenrolledids User IDs already enrolled in $courseid BEFORE restore
     * @param array $backupidentities Student identities from users.xml
     * @return int[]
     */
    protected static function resolve_incoming_userids(int $courseid, array $preenrolledids, array $backupidentities): array {
        global $DB;
        $uids = [];

        // 1. Primary resolution from backupidentities (users.xml), creating any user not yet present in mdl_user.
        foreach ($backupidentities as $ident) {
            $uid = (int)($ident['targetuserid'] ?? 0);
            if ($uid > 0 && !$DB->record_exists('user', ['id' => $uid, 'deleted' => 0])) {
                $uid = 0;
            }
            if ($uid <= 0 && !empty($ident['email'])) {
                $matches = $DB->get_records_select(
                    'user',
                    'LOWER(email) = LOWER(:email) AND deleted = 0',
                    ['email' => $ident['email']],
                    'id ASC',
                    'id',
                    0,
                    1
                );
                if (!empty($matches)) {
                    $u = reset($matches);
                    $uid = (int)$u->id;
                }
            }
            if ($uid <= 0 && !empty($ident['username'])) {
                $matches = $DB->get_records_select(
                    'user',
                    'LOWER(username) = LOWER(:uname) AND deleted = 0',
                    ['uname' => $ident['username']],
                    'id ASC',
                    'id',
                    0,
                    1
                );
                if (!empty($matches)) {
                    $u = reset($matches);
                    $uid = (int)$u->id;
                }
            }
            if ($uid <= 0) {
                $uid = self::create_missing_user_from_identity($ident);
            }
            if ($uid > 2 && !is_siteadmin($uid)) {
                $uids[$uid] = $uid;
            }
        }

        // 2. Also include any newly enrolled users in the course that were not enrolled before restore (proven v1.0.0 logic).
        $premap = array_flip(array_map('intval', $preenrolledids));
        try {
            $ctx = context_course::instance($courseid);
            $nowenrolled = get_enrolled_users($ctx, '', 0, 'u.id');
            foreach (array_keys($nowenrolled) as $eid) {
                $eid = (int)$eid;
                if ($eid > 2 && !isset($premap[$eid]) && !is_siteadmin($eid) && !self::is_iomad_manager_or_educator($eid)) {
                    $uids[$eid] = $eid;
                }
            }
        } catch (Throwable $e) {
            // Ignore context errors.
        }

        return array_values($uids);
    }

    /**
     * Ensures every incoming user is enrolled in the target course with an active manual enrolment
     * (timestart <= time() - 3600 so Moodle get_enrolled_sql() round(time(), -2) matches immediately)
     * and has the student role in mdl_role_assignments.
     *
     * @param int $courseid
     * @param int[] $userids
     */
    protected static function ensure_users_enrolled_as_students(int $courseid, array $userids): void {
        global $DB, $CFG;
        if (empty($userids)) {
            return;
        }

        $ctx = context_course::instance($courseid);
        $studentrole = $DB->get_record('role', ['shortname' => 'student']);
        $studentroleid = $studentrole ? (int)$studentrole->id : 5;

        // Ensure manual enrolment instance exists and is enabled (status = 0) in the course.
        $enrol = $DB->get_record('enrol', ['courseid' => $courseid, 'enrol' => 'manual']);
        if (!$enrol) {
            $plugin = enrol_get_plugin('manual');
            if ($plugin) {
                $course = $DB->get_record('course', ['id' => $courseid]);
                if ($course) {
                    $plugin->add_default_instance($course);
                    $enrol = $DB->get_record('enrol', ['courseid' => $courseid, 'enrol' => 'manual']);
                }
            }
        } else if ((int)$enrol->status !== ENROL_INSTANCE_ENABLED) {
            $enrol->status = ENROL_INSTANCE_ENABLED;
            $DB->update_record('enrol', $enrol);
        }

        $localhostid = !empty($CFG->mnet_localhost_id) ? (int)$CFG->mnet_localhost_id : 1;
        $now = time();
        $safetimestart = $now - 3600;
        $adminid = !empty($GLOBALS['USER']->id) ? (int)$GLOBALS['USER']->id : 2;

        // Filter valid non-admin user IDs in memory first.
        $validuids = [];
        foreach ($userids as $uid) {
            $uid = (int)$uid;
            if ($uid > 2 && !is_siteadmin($uid)) {
                $validuids[$uid] = $uid;
            }
        }
        if (empty($validuids)) {
            return;
        }

        // 1. Preload and normalize user records in batches (eliminating 400 individual user queries).
        $existingusers = [];
        foreach (array_chunk($validuids, 500) as $uchunk) {
            $urows = $DB->get_records_list('user', 'id', $uchunk, '', 'id, confirmed, suspended, mnethostid, password');
            foreach ($urows as $u) {
                $uid = (int)$u->id;
                $existingusers[$uid] = true;
                $unorm = false;
                if ((int)$u->confirmed !== 1) {
                    $u->confirmed = 1;
                    $unorm = true;
                }
                if ((int)$u->suspended !== 0) {
                    $u->suspended = 0;
                    $unorm = true;
                }
                if ((int)$u->mnethostid !== $localhostid) {
                    $u->mnethostid = $localhostid;
                    $unorm = true;
                }
                if (trim((string)$u->password) === '') {
                    $u->password = 'not cached';
                    $unorm = true;
                }
                if ($unorm) {
                    $DB->update_record('user', $u);
                }
            }
        }

        $validuids = array_keys($existingusers);
        if (empty($validuids)) {
            return;
        }

        // 2. Preload all existing user_enrolments in this course in a single query.
        $sqlue = "SELECT ue.*, e.status AS enrolstatus
                    FROM {user_enrolments} ue
                    JOIN {enrol} e ON e.id = ue.enrolid
                   WHERE e.courseid = :courseid";
        $allcourseues = $DB->get_records_sql($sqlue, ['courseid' => $courseid]);
        $uesbyuser = [];
        $disabledinstances = [];
        foreach ($allcourseues as $ue) {
            $uid = (int)$ue->userid;
            $uesbyuser[$uid][] = $ue;
            if (isset($existingusers[$uid]) && (int)$ue->enrolstatus !== ENROL_INSTANCE_ENABLED) {
                $disabledinstances[(int)$ue->enrolid] = (int)$ue->enrolid;
            }
        }
        foreach ($disabledinstances as $disenrolid) {
            $DB->set_field('enrol', 'status', ENROL_INSTANCE_ENABLED, ['id' => $disenrolid]);
        }

        $newuerows = [];
        foreach ($validuids as $uid) {
            if (empty($uesbyuser[$uid])) {
                if ($enrol) {
                    $nue = new stdClass();
                    $nue->status = ENROL_USER_ACTIVE;
                    $nue->enrolid = (int)$enrol->id;
                    $nue->userid = $uid;
                    $nue->timestart = $safetimestart;
                    $nue->timeend = 0;
                    $nue->modifierid = $adminid;
                    $nue->timecreated = $now;
                    $nue->timemodified = $now;
                    $newuerows[] = $nue;
                }
            } else {
                foreach ($uesbyuser[$uid] as $ue) {
                    $uechanged = false;
                    if ((int)$ue->status !== ENROL_USER_ACTIVE) {
                        $ue->status = ENROL_USER_ACTIVE;
                        $uechanged = true;
                    }
                    if ((int)$ue->timeend !== 0) {
                        $ue->timeend = 0;
                        $uechanged = true;
                    }
                    if ((int)$ue->timestart > $safetimestart) {
                        $ue->timestart = $safetimestart;
                        $uechanged = true;
                    }
                    if ($uechanged) {
                        unset($ue->enrolstatus);
                        $DB->update_record('user_enrolments', $ue);
                    }
                }
            }
        }

        if (!empty($newuerows)) {
            foreach (array_chunk($newuerows, 250) as $uechunk) {
                $DB->insert_records('user_enrolments', $uechunk);
            }
        }

        // 3. Preload existing student role_assignments in this course context and batch-insert missing ones.
        $existingras = $DB->get_records_menu(
            'role_assignments',
            ['contextid' => $ctx->id, 'roleid' => $studentroleid],
            '',
            'userid, id'
        );
        $newrarows = [];
        foreach ($validuids as $uid) {
            if (!isset($existingras[$uid])) {
                $ra = new stdClass();
                $ra->roleid = $studentroleid;
                $ra->contextid = (int)$ctx->id;
                $ra->userid = $uid;
                $ra->timemodified = $now;
                $ra->modifierid = $adminid;
                $ra->component = '';
                $ra->itemid = 0;
                $ra->sortorder = 0;
                $newrarows[] = $ra;
            }
        }
        if (!empty($newrarows)) {
            foreach (array_chunk($newrarows, 250) as $rachunk) {
                $DB->insert_records('role_assignments', $rachunk);
            }
            $ctx->mark_dirty();
        }
    }

    /**
     * Surgically binds the target course and ONLY the migrated users ($userids) to the SINGLE
     * target IOMAD company ($companyid), its root department, its official IOMAD company course group
     * (mdl_company_course_groups -> mdl_groups -> mdl_groups_members), and mdl_local_iomad_track
     * so students appear immediately under "Enrolled users" (left box) in IOMAD User Enrolments.
     *
     * @param int $courseid
     * @param int $companyid Explicit target company ID selected for this migration
     * @param int[] $userids Strictly the user IDs migrated in this request
     * @param array $summary
     * @return int The official IOMAD group ID in mdl_groups for ($companyid, $courseid)
     */
    protected static function ensure_iomad_company_and_group_binding(
        int $courseid,
        int $companyid,
        array $userids,
        array &$summary
    ): int {
        global $DB;

        if ($companyid <= 0 || $courseid <= 1) {
            return 0;
        }

        self::load_iomad_libraries();

        $tblcompany = security_manager::resolve_iomad_table('company');
        $tbldept = security_manager::resolve_iomad_table('department');
        $tblcompcourse = security_manager::resolve_iomad_table('company_course');
        $tblsharedcourses = security_manager::resolve_iomad_table('company_shared_courses');
        $tbliomadcourses = security_manager::resolve_iomad_table('iomad_courses');
        $tblccg = security_manager::resolve_iomad_table('company_course_groups');
        $tblcompusers = security_manager::resolve_iomad_table('company_users');

        if (!$tblcompany) {
            return 0;
        }

        $comp = $DB->get_record($tblcompany, ['id' => $companyid]);
        $course = $DB->get_record('course', ['id' => $courseid]);
        if (!$comp || !$course) {
            return 0;
        }

        // Ensure course groupmode is SEPARATEGROUPS (1) as required by IOMAD for company courses.
        if ((int)$course->groupmode !== SEPARATEGROUPS) {
            $course->groupmode = SEPARATEGROUPS;
            $DB->set_field('course', 'groupmode', SEPARATEGROUPS, ['id' => $courseid]);
        }

        // Ensure course is registered in iomad_courses with licensed = 0 and shared = 1 (so User Enrolments manages it).
        if ($tbliomadcourses) {
            $ic = $DB->get_record($tbliomadcourses, ['courseid' => $courseid]);
            if (!$ic) {
                $ic = new stdClass();
                $ic->courseid = $courseid;
                $ic->licensed = 0;
                $ic->shared = 1;
                $ic->validlength = 0;
                $ic->warnexpire = 0;
                $ic->warncompletion = 0;
                $ic->notifyperiod = 0;
                $ic->expireafter = 0;
                $ic->warnnotstarted = 0;
                $ic->hasgrade = 1;
                $DB->insert_record($tbliomadcourses, $ic);
            } else if ((int)$ic->licensed !== 0 || (int)$ic->shared === 0) {
                $ic->licensed = 0;
                $ic->shared = 1;
                $DB->update_record($tbliomadcourses, $ic);
            }
        }

        $cccols = $tblcompcourse ? $DB->get_columns($tblcompcourse) : [];
        $compcolcc = isset($cccols['companyid']) ? 'companyid' : 'company';
        $deptcolcc = isset($cccols['departmentid']) ? 'departmentid' : 'department';

        $cucols = $tblcompusers ? $DB->get_columns($tblcompusers) : [];
        $compcolcu = isset($cucols['companyid']) ? 'companyid' : 'company';
        $deptcolcu = isset($cucols['departmentid']) ? 'departmentid' : 'department';

        $ccgcols = $tblccg ? $DB->get_columns($tblccg) : [];
        $compcolccg = isset($ccgcols['companyid']) ? 'companyid' : 'company';

        // A. Resolve or create root department for the SINGLE target company ($companyid).
        $deptid = self::resolve_or_create_company_root_department($companyid, $comp, $tbldept);

        // B. Ensure course is registered in company_course ONLY for $companyid.
        if ($tblcompcourse) {
            $ccrec = $DB->get_record($tblcompcourse, [$compcolcc => $companyid, 'courseid' => $courseid]);
            if (!$ccrec) {
                $cc = new stdClass();
                $cc->{$compcolcc} = $companyid;
                $cc->courseid = $courseid;
                if (isset($cccols[$deptcolcc])) {
                    $cc->{$deptcolcc} = $deptid;
                }
                if (isset($cccols['autoenrol'])) {
                    $cc->autoenrol = 0;
                }
                $DB->insert_record($tblcompcourse, $cc);
            } else if ($deptid > 0 && isset($cccols[$deptcolcc]) && (int)($ccrec->{$deptcolcc} ?? 0) === 0) {
                $ccrec->{$deptcolcc} = $deptid;
                $DB->update_record($tblcompcourse, $ccrec);
            }
        }

        // Also register in company_shared_courses for $companyid if table exists.
        if ($tblsharedcourses) {
            $sccols = $DB->get_columns($tblsharedcourses);
            $compcolsc = isset($sccols['companyid']) ? 'companyid' : 'company';
            if (!$DB->record_exists($tblsharedcourses, [$compcolsc => $companyid, 'courseid' => $courseid])) {
                $sc = new stdClass();
                $sc->{$compcolsc} = $companyid;
                $sc->courseid = $courseid;
                $DB->insert_record($tblsharedcourses, $sc);
            }
        }

        // C. Ensure official IOMAD company course group exists in mdl_groups and mdl_company_course_groups ONLY for $companyid.
        $groupid = self::ensure_company_course_group($companyid, $courseid, $comp, $tblccg, $compcolccg);

        // Collect all valid group IDs registered for ($companyid, $courseid) in company_course_groups.
        $allcompanygroupids = [];
        if ($groupid > 0) {
            $allcompanygroupids[$groupid] = $groupid;
        }
        if ($tblccg) {
            $ccgrows = $DB->get_records($tblccg, [$compcolccg => $companyid, 'courseid' => $courseid]);
            foreach ($ccgrows as $ccg) {
                $gid = (int)($ccg->groupid ?? 0);
                if ($gid > 0 && $DB->record_exists('groups', ['id' => $gid, 'courseid' => $courseid])) {
                    $allcompanygroupids[$gid] = $gid;
                }
            }
        }

        // D. High-Performance Batch Binding of migrated student users ($userids) to the target company ($companyid),
        //    its group ($groupid), and mdl_local_iomad_track inside a single delegated transaction.
        $candidateuids = [];
        foreach ($userids as $uid) {
            $uid = (int)$uid;
            if ($uid > 2 && !is_siteadmin($uid)) {
                $candidateuids[$uid] = $uid;
            }
        }
        if (empty($candidateuids)) {
            return (int)$groupid;
        }

        // Preload any IOMAD managers or educators in a single query so they are never overwritten.
        $manageruids = [];
        if ($tblcompusers) {
            try {
                $mgrrows = $DB->get_fieldset_select(
                    $tblcompusers,
                    'userid',
                    "{$compcolcu} = :cid AND (managertype > 0 OR educator > 0)",
                    ['cid' => $companyid]
                );
                foreach ($mgrrows as $muid) {
                    $manageruids[(int)$muid] = true;
                }
            } catch (Throwable $e) {
                $manageruids = [];
            }
        }

        $studentuids = [];
        foreach ($candidateuids as $uid) {
            if (!isset($manageruids[$uid])) {
                $studentuids[$uid] = $uid;
            }
        }
        if (empty($studentuids)) {
            return (int)$groupid;
        }

        $now = time();
        $transaction = $DB->start_delegated_transaction();

        // 1. Batch-sync mdl_company_users for ($companyid, $studentuids).
        if ($tblcompusers) {
            $validdepts = [];
            if ($tbldept) {
                $deptcols = $DB->get_columns($tbldept);
                $compcoldept = isset($deptcols['companyid']) ? 'companyid' : 'company';
                $validdepts = $DB->get_records_menu($tbldept, [$compcoldept => $companyid], '', 'id, id');
            }

            $existingcumap = [];
            foreach (array_chunk($studentuids, 500) as $uchunk) {
                list($insql, $inparams) = $DB->get_in_or_equal($uchunk, SQL_PARAMS_NAMED, 'cu');
                $inparams['cid'] = $companyid;
                $curows = $DB->get_records_select($tblcompusers, "{$compcolcu} = :cid AND userid {$insql}", $inparams);
                foreach ($curows as $curow) {
                    $existingcumap[(int)$curow->userid] = $curow;
                }
            }

            $newcurows = [];
            foreach ($studentuids as $uid) {
                if (!isset($existingcumap[$uid])) {
                    $cu = new stdClass();
                    $cu->{$compcolcu} = $companyid;
                    $cu->userid = $uid;
                    if (isset($cucols['managertype'])) {
                        $cu->managertype = 0;
                    }
                    if (isset($cucols[$deptcolcu])) {
                        $cu->{$deptcolcu} = $deptid;
                    }
                    if (isset($cucols['suspended'])) {
                        $cu->suspended = 0;
                    }
                    if (isset($cucols['educator'])) {
                        $cu->educator = 0;
                    }
                    if (isset($cucols['timecreated'])) {
                        $cu->timecreated = $now;
                    }
                    if (isset($cucols['timemodified'])) {
                        $cu->timemodified = $now;
                    }
                    $newcurows[] = $cu;
                    $summary['users_assigned_to_company']++;
                } else {
                    $existingcu = $existingcumap[$uid];
                    $needsupdate = false;
                    $curdept = (int)($existingcu->{$deptcolcu} ?? 0);
                    if ($deptid > 0 && ($curdept <= 0 || (!empty($validdepts) && !isset($validdepts[$curdept])))) {
                        $existingcu->{$deptcolcu} = $deptid;
                        $needsupdate = true;
                    }
                    if (isset($existingcu->suspended) && (int)$existingcu->suspended !== 0) {
                        $existingcu->suspended = 0;
                        $needsupdate = true;
                    }
                    if ($needsupdate) {
                        $DB->update_record($tblcompusers, $existingcu);
                    }
                    $summary['users_assigned_to_company']++;
                }
            }

            if (!empty($newcurows)) {
                foreach (array_chunk($newcurows, 250) as $cuchunk) {
                    $DB->insert_records($tblcompusers, $cuchunk);
                }
            }
        }

        // 2. Batch-sync mdl_groups_members for the official IOMAD company group ($groupid),
        //    preserving any custom subgroup of the company that a student already belongs to.
        if (!empty($allcompanygroupids) && $groupid > 0) {
            list($gsql, $gparams) = $DB->get_in_or_equal(array_values($allcompanygroupids), SQL_PARAMS_NAMED, 'cg');
            $existinggmrows = $DB->get_records_select('groups_members', "groupid {$gsql}", $gparams, '', 'id, groupid, userid');
            $usersingroup = [];
            foreach ($existinggmrows as $gm) {
                $usersingroup[(int)$gm->userid] = true;
            }

            $newgmrows = [];
            foreach ($studentuids as $uid) {
                if (!isset($usersingroup[$uid])) {
                    $gm = new stdClass();
                    $gm->groupid = (int)$groupid;
                    $gm->userid = $uid;
                    $gm->timeadded = $now;
                    $gm->component = '';
                    $gm->itemid = 0;
                    $newgmrows[] = $gm;
                }
                $summary['users_added_to_iomad_group']++;
            }

            if (!empty($newgmrows)) {
                foreach (array_chunk($newgmrows, 250) as $gmchunk) {
                    $DB->insert_records('groups_members', $gmchunk);
                }
                \cache_helper::invalidate_by_event('changesingroups', [$courseid]);
            }
        }

        // 3. Batch-sync mdl_local_iomad_track for all migrated students in a single pass.
        self::sync_users_iomad_track_batch($courseid, $companyid, array_values($studentuids), (string)$course->fullname);

        $transaction->allow_commit();

        return (int)$groupid;
    }

    /**
     * Resolves (or creates if missing) the root department for an IOMAD company.
     *
     * @param int $companyid
     * @param stdClass $company
     * @param string|null $tbldept
     * @return int
     */
    protected static function resolve_or_create_company_root_department(
        int $companyid,
        stdClass $company,
        ?string $tbldept
    ): int {
        global $DB;
        if (!$tbldept) {
            return 0;
        }
        $deptcols = $DB->get_columns($tbldept);
        $compcol = isset($deptcols['companyid']) ? 'companyid' : 'company';
        $parentcol = isset($deptcols['parentid']) ? 'parentid' : 'parent';

        $rootdept = $DB->get_record($tbldept, [$compcol => $companyid, $parentcol => 0]);
        if (!$rootdept) {
            $depts = $DB->get_records($tbldept, [$compcol => $companyid], 'id ASC', '*', 0, 1);
            $rootdept = !empty($depts) ? reset($depts) : null;
        }
        if ($rootdept) {
            return (int)$rootdept->id;
        }

        // Create root department if the company had none.
        $newdept = new stdClass();
        $newdept->name = (string)($company->name ?? ('Company ' . $companyid));
        $newdept->shortname = (string)($company->shortname ?? ('COMP' . $companyid));
        $newdept->{$compcol} = $companyid;
        $newdept->{$parentcol} = 0;
        return (int)$DB->insert_record($tbldept, $newdept);
    }

    /**
     * Ensures a valid IOMAD company course group exists in both mdl_groups and mdl_company_course_groups,
     * preferring the group returned by IOMAD native company::get_company_group($companyid, $courseid).
     *
     * @param int $companyid
     * @param int $courseid
     * @param stdClass $company
     * @param string|null $tblccg
     * @param string $compcolccg
     * @return int The group ID in mdl_groups
     */
    protected static function ensure_company_course_group(
        int $companyid,
        int $courseid,
        stdClass $company,
        ?string $tblccg,
        string $compcolccg
    ): int {
        global $DB;
        $groupid = 0;

        // 1. Purge any orphaned references in company_course_groups first.
        if ($tblccg) {
            $ccgrows = $DB->get_records($tblccg, [$compcolccg => $companyid, 'courseid' => $courseid], 'id ASC');
            foreach ($ccgrows as $ccg) {
                $gid = (int)($ccg->groupid ?? 0);
                if ($gid > 0 && $DB->record_exists('groups', ['id' => $gid, 'courseid' => $courseid])) {
                    if ($groupid <= 0) {
                        $groupid = $gid;
                    }
                } else {
                    $DB->delete_records($tblccg, ['id' => $ccg->id]);
                }
            }
        }

        // 2. Check native IOMAD company::get_company_group() / create_company_course_group() if available.
        try {
            if (class_exists('\\local_iomad\\company')) {
                if ($groupid <= 0 && method_exists('\\local_iomad\\company', 'create_company_course_group')) {
                    \local_iomad\company::create_company_course_group($companyid, $courseid);
                }
                if (method_exists('\\local_iomad\\company', 'get_company_group')) {
                    $ng = \local_iomad\company::get_company_group($companyid, $courseid);
                    if (is_object($ng) && !empty($ng->id) && $DB->record_exists('groups', ['id' => (int)$ng->id, 'courseid' => $courseid])) {
                        $groupid = (int)$ng->id;
                    }
                }
            } else if (class_exists('\\company')) {
                if ($groupid <= 0 && method_exists('\\company', 'create_company_course_group')) {
                    \company::create_company_course_group($companyid, $courseid);
                }
                if (method_exists('\\company', 'get_company_group')) {
                    $ng = \company::get_company_group($companyid, $courseid);
                    if (is_object($ng) && !empty($ng->id) && $DB->record_exists('groups', ['id' => (int)$ng->id, 'courseid' => $courseid])) {
                        $groupid = (int)$ng->id;
                    }
                }
            }
        } catch (Throwable $e) {
            // Fall through to direct check below.
        }

        if ($groupid > 0) {
            if ($tblccg && !$DB->record_exists($tblccg, [$compcolccg => $companyid, 'courseid' => $courseid, 'groupid' => $groupid])) {
                $ccg = new stdClass();
                $ccg->{$compcolccg} = $companyid;
                $ccg->courseid = $courseid;
                $ccg->groupid = $groupid;
                $DB->insert_record($tblccg, $ccg);
            }
            return $groupid;
        }

        // 3. Direct creation/binding in mdl_groups and mdl_company_course_groups.
        $grpname = !empty($company->shortname) ? trim((string)$company->shortname) : trim((string)$company->name);
        if ($grpname === '') {
            $grpname = 'Company-' . $companyid;
        }

        $existinggrp = $DB->get_record('groups', ['courseid' => $courseid, 'name' => $grpname]);
        if (!$existinggrp && !empty($company->name)) {
            $existinggrp = $DB->get_record('groups', ['courseid' => $courseid, 'name' => trim((string)$company->name)]);
        }

        if ($existinggrp && $tblccg) {
            $claimed = $DB->get_record_select(
                $tblccg,
                "courseid = :courseid AND groupid = :groupid AND {$compcolccg} <> :companyid",
                ['courseid' => $courseid, 'groupid' => $existinggrp->id, 'companyid' => $companyid]
            );
            if ($claimed) {
                $existinggrp = null;
                $grpname .= ' (' . $companyid . ')';
            }
        }

        if ($existinggrp) {
            $groupid = (int)$existinggrp->id;
        } else {
            $newgrp = new stdClass();
            $newgrp->courseid = $courseid;
            $newgrp->name = $grpname;
            $newgrp->description = 'Default group';
            $newgrp->descriptionformat = FORMAT_HTML;
            $newgrp->timecreated = time();
            $newgrp->timemodified = $newgrp->timecreated;
            $groupid = (int)groups_create_group($newgrp);
        }

        if ($groupid > 0 && $tblccg) {
            if (!$DB->record_exists($tblccg, [$compcolccg => $companyid, 'courseid' => $courseid, 'groupid' => $groupid])) {
                $ccg = new stdClass();
                $ccg->{$compcolccg} = $companyid;
                $ccg->courseid = $courseid;
                $ccg->groupid = $groupid;
                $DB->insert_record($tblccg, $ccg);
            }
        }

        return $groupid;
    }

    /**
     * Synchronizes a student course enrollment, completion, and grade into mdl_local_iomad_track
     * so IOMAD User Enrolments, My Courses block, and Completion Reports recognize the student natively.
     *
     * @param int $courseid
     * @param int $companyid
     * @param int $userid
     * @param string $coursename
     */
    protected static function sync_user_iomad_track(
        int $courseid,
        int $companyid,
        int $userid,
        string $coursename
    ): void {
        self::sync_users_iomad_track_batch($courseid, $companyid, [$userid], $coursename);
    }

    /**
     * Batch-synchronizes student course enrollments, completions, and final grades into mdl_local_iomad_track
     * using bulk SQL preloading and batch inserts instead of N+1 per-user queries.
     *
     * @param int $courseid
     * @param int $companyid
     * @param int[] $userids
     * @param string $coursename
     */
    protected static function sync_users_iomad_track_batch(
        int $courseid,
        int $companyid,
        array $userids,
        string $coursename
    ): void {
        global $DB;
        if (empty($userids)) {
            return;
        }
        $tbltrack = security_manager::resolve_iomad_table('local_iomad_track');
        if (!$tbltrack) {
            return;
        }

        try {
            $cols = $DB->get_columns($tbltrack);
            $compcol = isset($cols['companyid']) ? 'companyid' : 'company';
            $now = time();

            // 1. Preload earliest enrolment timestamps per user for this course in 1 query.
            $sqlue = "SELECT ue.userid,
                             MIN(CASE WHEN ue.timestart > 0 THEN ue.timestart ELSE ue.timecreated END) AS mintime
                        FROM {user_enrolments} ue
                        JOIN {enrol} e ON e.id = ue.enrolid
                       WHERE e.courseid = :courseid
                    GROUP BY ue.userid";
            $uerows = $DB->get_records_sql($sqlue, ['courseid' => $courseid]);

            // 2. Preload course_completions for this course in 1 query.
            $ccrows = [];
            if ($DB->get_manager()->table_exists('course_completions')) {
                $ccrows = $DB->get_records('course_completions', ['course' => $courseid], '', 'userid, timestarted, timecompleted');
            }

            // 3. Preload course final grades in 1 query.
            $ggrows = [];
            $coursegi = $DB->get_record('grade_items', ['courseid' => $courseid, 'itemtype' => 'course']);
            if ($coursegi) {
                $ggrows = $DB->get_records('grade_grades', ['itemid' => $coursegi->id], '', 'userid, finalgrade');
            }

            // 4. Preload existing local_iomad_track records for ($courseid, $companyid) in 1 query.
            $existingtracks = [];
            $trackrows = $DB->get_records($tbltrack, ['courseid' => $courseid, $compcol => $companyid], 'id ASC');
            foreach ($trackrows as $tr) {
                $existingtracks[(int)$tr->userid] = $tr;
            }

            $newtracks = [];
            foreach ($userids as $userid) {
                $userid = (int)$userid;
                if ($userid <= 0) {
                    continue;
                }

                $timeenrolled = $now;
                if (isset($uerows[$userid]) && (int)$uerows[$userid]->mintime > 0) {
                    $timeenrolled = (int)$uerows[$userid]->mintime;
                }

                $timestarted = $timeenrolled;
                $timecompleted = null;
                if (isset($ccrows[$userid])) {
                    $cc = $ccrows[$userid];
                    if (!empty($cc->timestarted) && (int)$cc->timestarted > 0) {
                        $timestarted = (int)$cc->timestarted;
                    }
                    if (!empty($cc->timecompleted) && (int)$cc->timecompleted > 0) {
                        $timecompleted = (int)$cc->timecompleted;
                    }
                }

                $finalscore = 0.0;
                if (isset($ggrows[$userid]) && $ggrows[$userid]->finalgrade !== null) {
                    $finalscore = (float)$ggrows[$userid]->finalgrade;
                }

                if (!isset($existingtracks[$userid])) {
                    $trk = new stdClass();
                    $trk->userid = $userid;
                    $trk->courseid = $courseid;
                    if (isset($cols['coursename'])) {
                        $trk->coursename = $coursename;
                    }
                    $trk->{$compcol} = $companyid;
                    if (isset($cols['licenseid'])) {
                        $trk->licenseid = 0;
                    }
                    if (isset($cols['licensename'])) {
                        $trk->licensename = '';
                    }
                    if (isset($cols['licenseallocated'])) {
                        $trk->licenseallocated = 0;
                    }
                    if (isset($cols['timeenrolled'])) {
                        $trk->timeenrolled = $timeenrolled;
                    }
                    if (isset($cols['timestarted'])) {
                        $trk->timestarted = $timestarted;
                    }
                    if (isset($cols['timecompleted'])) {
                        $trk->timecompleted = $timecompleted;
                    }
                    if (isset($cols['finalscore'])) {
                        $trk->finalscore = $finalscore;
                    }
                    if (isset($cols['modifiedtime'])) {
                        $trk->modifiedtime = $now;
                    }
                    $newtracks[] = $trk;
                } else {
                    $trk = $existingtracks[$userid];
                    $updated = false;
                    if (isset($cols['timeenrolled']) && empty($trk->timeenrolled)) {
                        $trk->timeenrolled = $timeenrolled;
                        $updated = true;
                    }
                    if (isset($cols['timestarted']) && empty($trk->timestarted)) {
                        $trk->timestarted = $timestarted;
                        $updated = true;
                    }
                    if ($timecompleted !== null && isset($cols['timecompleted']) && empty($trk->timecompleted)) {
                        $trk->timecompleted = $timecompleted;
                        $updated = true;
                    }
                    if ($finalscore > 0 && isset($cols['finalscore']) && (float)($trk->finalscore ?? 0) < $finalscore) {
                        $trk->finalscore = $finalscore;
                        $updated = true;
                    }
                    if ($updated) {
                        if (isset($cols['modifiedtime'])) {
                            $trk->modifiedtime = $now;
                        }
                        $DB->update_record($tbltrack, $trk);
                    }
                }
            }

            if (!empty($newtracks)) {
                foreach (array_chunk($newtracks, 250) as $trkchunk) {
                    $DB->insert_records($tbltrack, $trkchunk);
                }
            }
        } catch (Throwable $e) {
            // Non-fatal if local_iomad_track schema differs.
        }
    }

    /**
     * Deletes any non-IOMAD stray groups that were not present in the pre-restore snapshot.
     * Never touches groups that existed before the restore or groups registered in company_course_groups.
     *
     * @param int $courseid
     * @param int[] $protectedgroupids
     */
    protected static function cleanup_stray_groups(int $courseid, array $protectedgroupids): void {
        global $DB;
        $protected = array_flip($protectedgroupids);

        $tblccg = security_manager::resolve_iomad_table('company_course_groups');
        if ($tblccg) {
            $iomadgroups = $DB->get_records($tblccg, ['courseid' => $courseid]);
            foreach ($iomadgroups as $ig) {
                if (!empty($ig->groupid)) {
                    $protected[(int)$ig->groupid] = true;
                }
            }
        }

        $currentgroups = $DB->get_records('groups', ['courseid' => $courseid], '', 'id, name');
        foreach ($currentgroups as $grp) {
            $gid = (int)$grp->id;
            if (!isset($protected[$gid])) {
                groups_delete_group($gid);
            }
        }
    }

    /**
     * Matches newly restored course_modules against the Master Course's existing course_modules,
     * moves all user attempts, submissions, files, tracks, completions, and grades into the Master
     * activities, and deletes the temporary duplicate modules cleanly.
     *
     * @param int $courseid
     * @param array $mastercms Indexed by cmid
     * @param array $summary
     */
    protected static function consolidate_course_activities(int $courseid, array $mastercms, array &$summary): void {
        global $DB;

        $sql = "SELECT cm.id AS cmid, cm.module, cm.instance, cm.section, cm.idnumber,
                       cs.section AS sectionnum, m.name AS modname
                  FROM {course_modules} cm
                  JOIN {modules} m ON m.id = cm.module
                  LEFT JOIN {course_sections} cs ON cs.id = cm.section
                 WHERE cm.course = :courseid AND cm.deletioninprogress = 0
              ORDER BY cs.section ASC, cm.id ASC";
        $allcms = $DB->get_records_sql($sql, ['courseid' => $courseid]);

        $newcms = [];
        foreach ($allcms as $cmid => $row) {
            $cmid = (int)$cmid;
            if (isset($mastercms[$cmid])) {
                continue;
            }
            $actname = '';
            try {
                $inst = $DB->get_record($row->modname, ['id' => $row->instance], 'id, name');
                if ($inst && isset($inst->name)) {
                    $actname = trim((string)$inst->name);
                }
            } catch (Throwable $e) {
                $actname = '';
            }
            $row->actname = $actname;
            $row->normname = self::normalize_name($actname);
            $newcms[$cmid] = $row;
        }

        if (empty($newcms)) {
            return;
        }

        // Disable tool_recyclebin temporarily so deleting temporary duplicate modules never clutters the recycle bin.
        $origcoursebin = get_config('tool_recyclebin', 'coursebinenable');
        set_config('coursebinenable', 0, 'tool_recyclebin');

        try {
            foreach ($newcms as $restoredcm) {
                $mastercm = self::find_matching_master_cm($restoredcm, $mastercms);
                if (!$mastercm) {
                    continue;
                }

                // 1. Consolidate module-specific user attempts / submissions / tracks.
                switch ($restoredcm->modname) {
                    case 'quiz':
                        $summary['quiz_attempts_moved'] += self::merge_quiz_data(
                            (int)$restoredcm->instance,
                            (int)$mastercm->instance,
                            (int)$restoredcm->cmid,
                            (int)$mastercm->cmid
                        );
                        break;
                    case 'assign':
                        $summary['assign_submissions_moved'] += self::merge_assign_data(
                            (int)$restoredcm->instance,
                            (int)$mastercm->instance,
                            (int)$restoredcm->cmid,
                            (int)$mastercm->cmid
                        );
                        break;
                    case 'scorm':
                        $summary['scorm_tracks_moved'] += self::merge_scorm_data(
                            (int)$restoredcm->instance,
                            (int)$mastercm->instance
                        );
                        break;
                    case 'forum':
                        self::merge_forum_data(
                            (int)$restoredcm->instance,
                            (int)$mastercm->instance,
                            (int)$restoredcm->cmid,
                            (int)$mastercm->cmid
                        );
                        break;
                    case 'lesson':
                        self::merge_lesson_data((int)$restoredcm->instance, (int)$mastercm->instance);
                        break;
                    case 'choice':
                        self::merge_choice_data((int)$restoredcm->instance, (int)$mastercm->instance);
                        break;
                    case 'feedback':
                        self::merge_feedback_data((int)$restoredcm->instance, (int)$mastercm->instance);
                        break;
                    case 'h5pactivity':
                        self::merge_h5pactivity_data((int)$restoredcm->instance, (int)$mastercm->instance);
                        break;
                    case 'customcert':
                        self::merge_customcert_data((int)$restoredcm->instance, (int)$mastercm->instance);
                        break;
                    case 'coursecertificate':
                        self::merge_coursecertificate_data((int)$restoredcm->instance, (int)$mastercm->instance, $courseid);
                        break;
                    case 'glossary':
                        self::merge_glossary_data((int)$restoredcm->instance, (int)$mastercm->instance);
                        break;
                    case 'attendance':
                        self::merge_attendance_data((int)$restoredcm->instance, (int)$mastercm->instance);
                        break;
                }

                // 2. Consolidate activity completion (mdl_course_modules_completion).
                $summary['completions_moved'] += self::merge_cm_completions(
                    (int)$restoredcm->cmid,
                    (int)$mastercm->cmid
                );

                // 3. Consolidate gradebook grades (mdl_grade_items & mdl_grade_grades).
                $summary['grades_moved'] += self::merge_module_grades(
                    $courseid,
                    $restoredcm->modname,
                    (int)$restoredcm->instance,
                    (int)$mastercm->instance
                );

                // 4. Delete the temporary duplicate course module cleanly.
                self::delete_temporary_duplicate_cm($courseid, $restoredcm);
                $summary['activities_consolidated']++;
                $summary['duplicate_modules_removed']++;
            }
        } finally {
            if ($origcoursebin !== false) {
                set_config('coursebinenable', $origcoursebin, 'tool_recyclebin');
            }
        }
    }

    /**
     * Finds the best matching Master Course activity for a restored activity:
     * Priority 1: Same modname + same normalized name + same section number.
     * Priority 2: Same modname + same normalized name (any section).
     * Priority 3: Same modname + same non-empty idnumber.
     *
     * @param stdClass $restoredcm
     * @param array $mastercms
     * @return stdClass|null
     */
    protected static function find_matching_master_cm(stdClass $restoredcm, array $mastercms): ?stdClass {
        // Priority 1: Same modname + same normalized name + same sectionnum.
        foreach ($mastercms as $mcm) {
            if ($mcm->modname === $restoredcm->modname &&
                $mcm->normname !== '' &&
                $mcm->normname === $restoredcm->normname &&
                (int)$mcm->sectionnum === (int)$restoredcm->sectionnum) {
                return $mcm;
            }
        }
        // Priority 2: Same modname + same normalized name.
        foreach ($mastercms as $mcm) {
            if ($mcm->modname === $restoredcm->modname &&
                $mcm->normname !== '' &&
                $mcm->normname === $restoredcm->normname) {
                return $mcm;
            }
        }
        // Priority 3: Same modname + same idnumber.
        if (!empty($restoredcm->idnumber)) {
            foreach ($mastercms as $mcm) {
                if ($mcm->modname === $restoredcm->modname &&
                    (string)$mcm->idnumber === (string)$restoredcm->idnumber) {
                    return $mcm;
                }
            }
        }
        // Priority 4: For certificate activities (coursecertificate / customcert), or when the Master Course
        // has exactly 1 activity of this modname and they share a common prefix (e.g. "certificado curso basico..."
        // with a different cooperative suffix at the end), match the Master Course activity so no duplicate is created.
        $samemod = [];
        foreach ($mastercms as $mcm) {
            if ($mcm->modname === $restoredcm->modname) {
                $samemod[] = $mcm;
            }
        }
        if (count($samemod) === 1) {
            $candidate = $samemod[0];
            if (in_array($restoredcm->modname, ['coursecertificate', 'customcert'], true)) {
                return $candidate;
            }
            $rprefix = substr($restoredcm->normname, 0, 20);
            $mprefix = substr($candidate->normname, 0, 20);
            if ($rprefix !== '' && $rprefix === $mprefix) {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * Merges quiz attempts (mdl_quiz_attempts), question usages (mdl_question_usages context),
     * and quiz grades (mdl_quiz_grades) from the restored quiz into the Master quiz.
     *
     * @param int $restoredquizid
     * @param int $masterquizid
     * @param int $restoredcmid
     * @param int $mastercmid
     * @return int Number of quiz attempts moved
     */
    protected static function merge_quiz_data(
        int $restoredquizid,
        int $masterquizid,
        int $restoredcmid,
        int $mastercmid
    ): int {
        global $DB;
        $moved = 0;

        $masterctx = context_module::instance($mastercmid);

        // 1. Move mdl_quiz_attempts.
        $attempts = $DB->get_records('quiz_attempts', ['quiz' => $restoredquizid], 'userid ASC, attempt ASC');
        foreach ($attempts as $att) {
            // Check if this user already has this exact attempt number with the same timefinish in master quiz.
            $existing = $DB->get_record('quiz_attempts', [
                'quiz' => $masterquizid,
                'userid' => $att->userid,
                'attempt' => $att->attempt,
            ]);
            if ($existing) {
                if ((int)$existing->timefinish === (int)$att->timefinish && (float)$existing->sumgrades === (float)$att->sumgrades) {
                    // Already imported in a previous run; skip duplicate.
                    continue;
                }
                // Find the next available attempt number for this user on the master quiz.
                $maxatt = (int)$DB->get_field_sql(
                    "SELECT COALESCE(MAX(attempt), 0) FROM {quiz_attempts} WHERE quiz = :q AND userid = :u",
                    ['q' => $masterquizid, 'u' => $att->userid]
                );
                $att->attempt = $maxatt + 1;
            }

            // Re-point question_usages contextid to the Master Quiz's module context so review.php passes capability checks!
            if (!empty($att->uniqueid)) {
                $DB->set_field('question_usages', 'contextid', $masterctx->id, ['id' => $att->uniqueid]);
            }

            $att->quiz = $masterquizid;
            $DB->update_record('quiz_attempts', $att);
            $moved++;
        }

        // 2. Merge mdl_quiz_grades.
        $grades = $DB->get_records('quiz_grades', ['quiz' => $restoredquizid]);
        foreach ($grades as $g) {
            $existingg = $DB->get_record('quiz_grades', ['quiz' => $masterquizid, 'userid' => $g->userid]);
            if (!$existingg) {
                $g->quiz = $masterquizid;
                $DB->update_record('quiz_grades', $g);
            } else if ((float)$g->grade > (float)$existingg->grade) {
                $existingg->grade = $g->grade;
                $existingg->timemodified = max((int)$existingg->timemodified, (int)$g->timemodified);
                $DB->update_record('quiz_grades', $existingg);
            }
        }

        // 3. Merge mdl_quiz_overrides (user overrides only, never group overrides).
        $overrides = $DB->get_records_select('quiz_overrides', 'quiz = :q AND userid IS NOT NULL', ['q' => $restoredquizid]);
        foreach ($overrides as $ov) {
            if (!$DB->record_exists('quiz_overrides', ['quiz' => $masterquizid, 'userid' => $ov->userid])) {
                $ov->quiz = $masterquizid;
                $ov->groupid = null;
                $DB->update_record('quiz_overrides', $ov);
            }
        }

        return $moved;
    }

    /**
     * Merges assignment submissions, grades, subplugin records, and submitted files
     * from the restored assignment into the Master assignment.
     *
     * @param int $restoredassignid
     * @param int $masterassignid
     * @param int $restoredcmid
     * @param int $mastercmid
     * @return int Number of submissions moved
     */
    protected static function merge_assign_data(
        int $restoredassignid,
        int $masterassignid,
        int $restoredcmid,
        int $mastercmid
    ): int {
        global $DB;
        $moved = 0;
        $dbman = $DB->get_manager();

        $restoredctx = context_module::instance($restoredcmid);
        $masterctx = context_module::instance($mastercmid);

        // 1. Move mdl_assign_submission.
        $submissions = $DB->get_records('assign_submission', ['assignment' => $restoredassignid], 'id ASC');
        foreach ($submissions as $sub) {
            if ((int)$sub->userid <= 0) {
                continue;
            }
            $existingsub = $DB->get_record('assign_submission', [
                'assignment' => $masterassignid,
                'userid' => $sub->userid,
                'attemptnumber' => $sub->attemptnumber,
            ]);
            if ($existingsub) {
                continue;
            }
            $oldsubid = (int)$sub->id;
            $sub->assignment = $masterassignid;
            $sub->groupid = 0;
            $DB->update_record('assign_submission', $sub);

            // Update subplugin tables referencing assignment.
            foreach (['assignsubmission_onlinetext', 'assignsubmission_file'] as $subtable) {
                if ($dbman->table_exists($subtable)) {
                    $DB->set_field($subtable, 'assignment', $masterassignid, ['submission' => $oldsubid]);
                }
            }

            // Move submitted files in mdl_files from restored context to master context.
            $DB->execute(
                "UPDATE {files} SET contextid = :masterctx WHERE contextid = :restoredctx AND itemid = :subid",
                ['masterctx' => $masterctx->id, 'restoredctx' => $restoredctx->id, 'subid' => $oldsubid]
            );
            $moved++;
        }

        // 2. Move mdl_assign_grades.
        $grades = $DB->get_records('assign_grades', ['assignment' => $restoredassignid], 'id ASC');
        foreach ($grades as $ag) {
            if ((int)$ag->userid <= 0) {
                continue;
            }
            $existingag = $DB->get_record('assign_grades', [
                'assignment' => $masterassignid,
                'userid' => $ag->userid,
                'attemptnumber' => $ag->attemptnumber,
            ]);
            if ($existingag) {
                continue;
            }
            $oldgradeid = (int)$ag->id;
            $ag->assignment = $masterassignid;
            $DB->update_record('assign_grades', $ag);

            foreach (['assignfeedback_comments', 'assignfeedback_file', 'assignfeedback_editpdf_cmnt', 'assignfeedback_editpdf_annot'] as $fbtable) {
                if ($dbman->table_exists($fbtable) && $dbman->field_exists($fbtable, 'assignment')) {
                    $DB->set_field($fbtable, 'assignment', $masterassignid, ['grade' => $oldgradeid]);
                }
            }

            $DB->execute(
                "UPDATE {files} SET contextid = :masterctx WHERE contextid = :restoredctx AND itemid = :gradeid",
                ['masterctx' => $masterctx->id, 'restoredctx' => $restoredctx->id, 'gradeid' => $oldgradeid]
            );
        }

        // 3. Move mdl_assign_user_flags & mdl_assign_user_mapping.
        foreach (['assign_user_flags', 'assign_user_mapping'] as $utable) {
            if ($dbman->table_exists($utable)) {
                $urows = $DB->get_records($utable, ['assignment' => $restoredassignid]);
                foreach ($urows as $urow) {
                    if (!$DB->record_exists($utable, ['assignment' => $masterassignid, 'userid' => $urow->userid])) {
                        $urow->assignment = $masterassignid;
                        $DB->update_record($utable, $urow);
                    }
                }
            }
        }

        return $moved;
    }

    /**
     * Merges SCORM tracking data from the restored SCORM into the Master SCORM.
     * Supports both legacy mdl_scorm_scoes_track and Moodle 4.3+ mdl_scorm_attempt tables.
     *
     * @param int $restoredscormid
     * @param int $masterscormid
     * @return int Number of SCORM records moved
     */
    protected static function merge_scorm_data(int $restoredscormid, int $masterscormid): int {
        global $DB;
        $moved = 0;
        $dbman = $DB->get_manager();

        // Map restored scoid -> master scoid by identifier.
        $restoredscoes = $DB->get_records('scorm_scoes', ['scorm' => $restoredscormid], 'id ASC', 'id, identifier');
        $masterscoes = $DB->get_records('scorm_scoes', ['scorm' => $masterscormid], 'id ASC', 'id, identifier');
        $masterbyident = [];
        foreach ($masterscoes as $msco) {
            $masterbyident[$msco->identifier] = (int)$msco->id;
        }
        $scoidmap = [];
        foreach ($restoredscoes as $rsco) {
            if (isset($masterbyident[$rsco->identifier])) {
                $scoidmap[(int)$rsco->id] = $masterbyident[$rsco->identifier];
            }
        }

        if ($dbman->table_exists('scorm_scoes_track')) {
            $tracks = $DB->get_records('scorm_scoes_track', ['scormid' => $restoredscormid]);
            foreach ($tracks as $tr) {
                $targetscoid = $scoidmap[(int)$tr->scoid] ?? (int)$tr->scoid;
                if (!$DB->record_exists('scorm_scoes_track', [
                    'userid' => $tr->userid,
                    'scormid' => $masterscormid,
                    'scoid' => $targetscoid,
                    'attempt' => $tr->attempt,
                    'element' => $tr->element,
                ])) {
                    $tr->scormid = $masterscormid;
                    $tr->scoid = $targetscoid;
                    $DB->update_record('scorm_scoes_track', $tr);
                    $moved++;
                }
            }
        }

        if ($dbman->table_exists('scorm_attempt')) {
            $attempts = $DB->get_records('scorm_attempt', ['scormid' => $restoredscormid]);
            foreach ($attempts as $satt) {
                $existing = $DB->get_record('scorm_attempt', [
                    'scormid' => $masterscormid,
                    'userid' => $satt->userid,
                    'attempt' => $satt->attempt,
                ]);
                if (!$existing) {
                    $satt->scormid = $masterscormid;
                    $DB->update_record('scorm_attempt', $satt);
                    if ($dbman->table_exists('scorm_scoes_value')) {
                        $vals = $DB->get_records('scorm_scoes_value', ['attemptid' => $satt->id]);
                        foreach ($vals as $v) {
                            if (isset($scoidmap[(int)$v->scoid])) {
                                $v->scoid = $scoidmap[(int)$v->scoid];
                                $DB->update_record('scorm_scoes_value', $v);
                            }
                        }
                    }
                    $moved++;
                }
            }
        }

        return $moved;
    }

    /**
     * Merges forum discussions, posts, and grades into the Master forum.
     *
     * @param int $restoredforumid
     * @param int $masterforumid
     * @param int $restoredcmid
     * @param int $mastercmid
     */
    protected static function merge_forum_data(
        int $restoredforumid,
        int $masterforumid,
        int $restoredcmid,
        int $mastercmid
    ): void {
        global $DB;
        $restoredctx = context_module::instance($restoredcmid);
        $masterctx = context_module::instance($mastercmid);

        $discussions = $DB->get_records('forum_discussions', ['forum' => $restoredforumid]);
        foreach ($discussions as $disc) {
            if ((int)$disc->userid <= 2) {
                continue;
            }
            $disc->forum = $masterforumid;
            $disc->groupid = -1;
            $DB->update_record('forum_discussions', $disc);
        }

        if ($DB->get_manager()->table_exists('forum_grades')) {
            $fgrades = $DB->get_records('forum_grades', ['forum' => $restoredforumid]);
            foreach ($fgrades as $fg) {
                if (!$DB->record_exists('forum_grades', ['forum' => $masterforumid, 'userid' => $fg->userid, 'itemnumber' => $fg->itemnumber])) {
                    $fg->forum = $masterforumid;
                    $DB->update_record('forum_grades', $fg);
                }
            }
        }

        $DB->execute(
            "UPDATE {files} SET contextid = :masterctx WHERE contextid = :restoredctx AND component = 'mod_forum'",
            ['masterctx' => $masterctx->id, 'restoredctx' => $restoredctx->id]
        );
    }

    /**
     * Merges lesson attempts and grades into the Master lesson.
     *
     * @param int $restoredlessonid
     * @param int $masterlessonid
     */
    protected static function merge_lesson_data(int $restoredlessonid, int $masterlessonid): void {
        global $DB;
        foreach (['lesson_grades', 'lesson_timer'] as $ltable) {
            if ($DB->get_manager()->table_exists($ltable)) {
                $rows = $DB->get_records($ltable, ['lessonid' => $restoredlessonid]);
                foreach ($rows as $r) {
                    $r->lessonid = $masterlessonid;
                    $DB->update_record($ltable, $r);
                }
            }
        }
    }

    /**
     * Merges choice answers into the Master choice.
     *
     * @param int $restoredchoiceid
     * @param int $masterchoiceid
     */
    protected static function merge_choice_data(int $restoredchoiceid, int $masterchoiceid): void {
        global $DB;
        $ropts = $DB->get_records('choice_options', ['choiceid' => $restoredchoiceid]);
        $mopts = $DB->get_records('choice_options', ['choiceid' => $masterchoiceid]);
        $mbytext = [];
        foreach ($mopts as $mo) {
            $mbytext[trim((string)$mo->text)] = (int)$mo->id;
        }
        $optmap = [];
        foreach ($ropts as $ro) {
            $t = trim((string)$ro->text);
            if (isset($mbytext[$t])) {
                $optmap[(int)$ro->id] = $mbytext[$t];
            }
        }

        $answers = $DB->get_records('choice_answers', ['choiceid' => $restoredchoiceid]);
        foreach ($answers as $ans) {
            if (!$DB->record_exists('choice_answers', ['choiceid' => $masterchoiceid, 'userid' => $ans->userid])) {
                $ans->choiceid = $masterchoiceid;
                if (isset($optmap[(int)$ans->optionid])) {
                    $ans->optionid = $optmap[(int)$ans->optionid];
                }
                $DB->update_record('choice_answers', $ans);
            }
        }
    }

    /**
     * Merges feedback completions into the Master feedback.
     *
     * @param int $restoredfbid
     * @param int $masterfbid
     */
    protected static function merge_feedback_data(int $restoredfbid, int $masterfbid): void {
        global $DB;
        $completeds = $DB->get_records('feedback_completed', ['feedback' => $restoredfbid]);
        foreach ($completeds as $comp) {
            if (!$DB->record_exists('feedback_completed', ['feedback' => $masterfbid, 'userid' => $comp->userid])) {
                $comp->feedback = $masterfbid;
                $DB->update_record('feedback_completed', $comp);
            }
        }
    }

    /**
     * Merges H5P activity attempts into the Master H5P activity.
     *
     * @param int $restoredh5pid
     * @param int $masterh5pid
     */
    protected static function merge_h5pactivity_data(int $restoredh5pid, int $masterh5pid): void {
        global $DB;
        if (!$DB->get_manager()->table_exists('h5pactivity_attempts')) {
            return;
        }
        $attempts = $DB->get_records('h5pactivity_attempts', ['h5pactivityid' => $restoredh5pid]);
        foreach ($attempts as $att) {
            if (!$DB->record_exists('h5pactivity_attempts', [
                'h5pactivityid' => $masterh5pid,
                'userid' => $att->userid,
                'attempt' => $att->attempt,
            ])) {
                $att->h5pactivityid = $masterh5pid;
                $DB->update_record('h5pactivity_attempts', $att);
            }
        }
    }

    /**
     * Moves issued custom certificates (mod_customcert) from the temporary duplicate into the Master customcert.
     *
     * @param int $restoredcertid
     * @param int $mastercertid
     */
    protected static function merge_customcert_data(int $restoredcertid, int $mastercertid): void {
        global $DB;
        if (!$DB->get_manager()->table_exists('customcert_issues')) {
            return;
        }
        $issues = $DB->get_records('customcert_issues', ['customcertid' => $restoredcertid]);
        foreach ($issues as $iss) {
            if (!$DB->record_exists('customcert_issues', ['customcertid' => $mastercertid, 'userid' => $iss->userid])) {
                $iss->customcertid = $mastercertid;
                $DB->update_record('customcert_issues', $iss);
            }
        }
    }

    /**
     * Moves or aligns issued Workplace Course Certificates (mod_coursecertificate + tool_certificate_issues)
     * from the temporary restored instance into the Master coursecertificate instance and template.
     *
     * @param int $restoredcertid
     * @param int $mastercertid
     * @param int $courseid
     */
    /**
     * Resolves a company-specific or group-specific Workplace tool_certificate_templates ID for a course,
     * falling back to the Master coursecertificate's default template ID.
     *
     * @param int $courseid
     * @param int $userid
     * @param int $defaulttemplateid
     * @return int
     */
    protected static function resolve_workplace_template_for_user(int $courseid, int $userid, int $defaulttemplateid, int $fallbackcompanyid = 0): int {
        global $DB;
        if (!$DB->get_manager()->table_exists('tool_certificate_templates')) {
            return $defaulttemplateid;
        }
        try {
            // 1. Check user's groups in $courseid (supporting "Certificado - {Name}", "Certificado {Name}", and "{Name}")
            $sqlgrp = "SELECT g.id, g.name, g.idnumber
                         FROM {groups} g
                         JOIN {groups_members} gm ON gm.groupid = g.id
                        WHERE g.courseid = :courseid AND gm.userid = :userid
                     ORDER BY g.id DESC";
            $grps = $DB->get_records_sql($sqlgrp, ['courseid' => $courseid, 'userid' => $userid]);
            foreach ($grps as $g) {
                foreach ([$g->name, $g->idnumber ?? ''] as $rawcand) {
                    $rawcand = trim((string)$rawcand);
                    if ($rawcand === '') {
                        continue;
                    }
                    foreach (['Certificado - ' . $rawcand, 'Certificado ' . $rawcand, $rawcand] as $cand) {
                        $m = $DB->get_records_select(
                            'tool_certificate_templates',
                            'LOWER(TRIM(name)) = LOWER(:tname)',
                            ['tname' => $cand],
                            'id DESC',
                            'id',
                            0,
                            1
                        );
                        if (!empty($m)) {
                            $tpl = reset($m);
                            return (int)$tpl->id;
                        }
                    }
                }
            }

            // 2. Check user's IOMAD company in mdl_company_users (supporting "Certificado - {Company}", "Certificado {Company}", and "{Company}")
            $tblcu = security_manager::resolve_iomad_table('company_users');
            $tblcomp = security_manager::resolve_iomad_table('company');
            if ($tblcu && $tblcomp && $userid > 0) {
                $cucols = $DB->get_columns($tblcu);
                $compcol = isset($cucols['companyid']) ? 'companyid' : 'company';
                $sqlc = "SELECT c.id, c.name, c.shortname
                           FROM {{$tblcu}} cu
                           JOIN {{$tblcomp}} c ON c.id = cu.{$compcol}
                          WHERE cu.userid = :userid
                       ORDER BY cu.id DESC";
                $comps = $DB->get_records_sql($sqlc, ['userid' => $userid]);
                foreach ($comps as $comp) {
                    foreach ([$comp->name ?? '', $comp->shortname ?? ''] as $rawcand) {
                        $rawcand = trim((string)$rawcand);
                        if ($rawcand === '') {
                            continue;
                        }
                        foreach (['Certificado - ' . $rawcand, 'Certificado ' . $rawcand, $rawcand] as $cand) {
                            $m = $DB->get_records_select(
                                'tool_certificate_templates',
                                'LOWER(TRIM(name)) = LOWER(:tname)',
                                ['tname' => $cand],
                                'id DESC',
                                'id',
                                0,
                                1
                            );
                            if (!empty($m)) {
                                $tpl = reset($m);
                                return (int)$tpl->id;
                            }
                        }
                    }
                }
            }
            // 3. Check explicit fallback company ID (e.g. from the migration request's companyid).
            if ($fallbackcompanyid > 0) {
                $comptplname = self::resolve_company_certificate_template_name($fallbackcompanyid);
                if ($comptplname !== '') {
                    $m = $DB->get_records_select(
                        'tool_certificate_templates',
                        'LOWER(TRIM(name)) = LOWER(:tname)',
                        ['tname' => $comptplname],
                        'id DESC',
                        'id',
                        0,
                        1
                    );
                    if (!empty($m)) {
                        $tpl = reset($m);
                        return (int)$tpl->id;
                    }
                }
            }

            if ($defaulttemplateid > 0 && $DB->record_exists('tool_certificate_templates', ['id' => $defaulttemplateid])) {
                return $defaulttemplateid;
            }

            // Check if the course's coursecertificate activity already has a valid template in tool_certificate_templates.
            if ($courseid > 0 && $DB->get_manager()->table_exists('coursecertificate')) {
                $wpcs = $DB->get_records('coursecertificate', ['course' => $courseid], 'id ASC');
                foreach ($wpcs as $wpc) {
                    $wtid = (int)($wpc->template ?? 0);
                    if ($wtid > 0 && $DB->record_exists('tool_certificate_templates', ['id' => $wtid])) {
                        return $wtid;
                    }
                }
            }

            // Prefer any non-demo template over the default demo template if $defaulttemplateid was not found yet.
            $nondemotpls = $DB->get_records_select(
                'tool_certificate_templates',
                "LOWER(name) NOT LIKE :demopat",
                ['demopat' => '%demo%'],
                'id DESC',
                'id',
                0,
                1
            );
            if (!empty($nondemotpls)) {
                $nd = reset($nondemotpls);
                return (int)$nd->id;
            }
        } catch (Throwable $e) {
            // Fall through to defaulttemplateid.
        }
        return $defaulttemplateid;
    }

    /**
     * Aligns all mdl_tool_certificate_issues belonging to $courseid (and/or $migrateduserids)
     * so their templateid, courseid, and component match the course's active mod_coursecertificate template
     * (or the user's IOMAD company/group override), moving any issue that was mistakenly assigned to the
     * default "Plantilla demo de certificado" into the course's real template and regenerating its PDF file.
     *
     * @param int $courseid Target Master Course ID
     * @param int[] $migrateduserids Optional list of migrated student user IDs for this request
     * @param array $originidmap Optional map of [origin_template_id => target_template_id]
     * @return int Number of certificate issues re-linked to the correct template
     */
    /**
     * Builds the standardized company certificate template name ("Certificado - {Company Name}")
     * for a given IOMAD company ID.
     *
     * @param int $companyid
     * @return string Standardized template name or empty string if company not found
     */
    public static function resolve_company_certificate_template_name(int $companyid): string {
        global $DB;
        if ($companyid <= 0) {
            return '';
        }
        $tblcomp = security_manager::resolve_iomad_table('company');
        if (!$tblcomp) {
            return '';
        }
        try {
            $comp = $DB->get_record($tblcomp, ['id' => $companyid]);
            if ($comp && !empty($comp->name)) {
                $clean = trim((string)$comp->name);
                if ($clean !== '') {
                    return 'Certificado - ' . $clean;
                }
            }
        } catch (Throwable $e) {
            return '';
        }
        return '';
    }

    public static function align_course_workplace_certificate_issues(
        int $courseid,
        array $migrateduserids = [],
        array $originidmap = [],
        int $companyid = 0
    ): int {
        global $DB;
        $relinked = 0;
        if ($courseid <= 1) {
            return 0;
        }
        $dbman = $DB->get_manager();
        if (!$dbman->table_exists('coursecertificate') ||
            !$dbman->table_exists('tool_certificate_templates') ||
            !$dbman->table_exists('tool_certificate_issues')) {
            return 0;
        }

        try {
            $wpcerts = $DB->get_records('coursecertificate', ['course' => $courseid], 'id ASC');
            if (empty($wpcerts)) {
                return 0;
            }

            $desiredcompanytplname = self::resolve_company_certificate_template_name($companyid);

            // Determine the active course template ID from mdl_coursecertificate.
            $coursetemplateid = 0;
            foreach ($wpcerts as $wpc) {
                $tid = (int)($wpc->template ?? 0);
                if ($tid > 0 && $DB->record_exists('tool_certificate_templates', ['id' => $tid])) {
                    $coursetemplateid = $tid;
                    break;
                }
            }

            // If the course's template is still pointing to the default demo template ("Plantilla demo de certificado")
            // while a real non-demo template exists in tool_certificate_templates, upgrade mdl_coursecertificate to the real template!
            if ($coursetemplateid > 0) {
                $curtpl = $DB->get_record('tool_certificate_templates', ['id' => $coursetemplateid]);
                if ($curtpl && stripos((string)$curtpl->name, 'demo') !== false) {
                    $nondemos = $DB->get_records_select(
                        'tool_certificate_templates',
                        "LOWER(name) NOT LIKE :demopat",
                        ['demopat' => '%demo%'],
                        'id DESC',
                        '*',
                        0,
                        1
                    );
                    if (!empty($nondemos)) {
                        $real = reset($nondemos);
                        $coursetemplateid = (int)$real->id;
                        $curtpl = $real;
                        foreach ($wpcerts as $wpc) {
                            if ((int)$wpc->template !== $coursetemplateid) {
                                $wpc->template = $coursetemplateid;
                                $wpc->timemodified = time();
                                $DB->update_record('coursecertificate', $wpc);
                            }
                        }
                    }
                }

                // Ensure the course's imported template is renamed to "Certificado - {Company Name}" if $desiredcompanytplname is set.
                if ($curtpl && $desiredcompanytplname !== '' &&
                    strtolower(trim((string)$curtpl->name)) !== strtolower($desiredcompanytplname)) {
                    $existingdesired = $DB->get_records_select(
                        'tool_certificate_templates',
                        'LOWER(TRIM(name)) = LOWER(:tname)',
                        ['tname' => $desiredcompanytplname],
                        'id DESC',
                        '*',
                        0,
                        1
                    );
                    if (!empty($existingdesired)) {
                        $desrec = reset($existingdesired);
                        $coursetemplateid = (int)$desrec->id;
                    } else if (stripos(trim((string)$curtpl->name), 'certificado - ') !== 0 &&
                               !((int)$curtpl->id === 1 && stripos((string)$curtpl->name, 'demo') !== false)) {
                        $curtpl->name = $desiredcompanytplname;
                        $curtpl->timemodified = time();
                        $DB->update_record('tool_certificate_templates', $curtpl);
                    }
                }
            }

            if ($coursetemplateid <= 0) {
                return 0;
            }

            $icols = $DB->get_columns('tool_certificate_issues');
            $hascourseid = isset($icols['courseid']);
            $hascomponent = isset($icols['component']);
            $hasarchived = isset($icols['archived']);

            $candidateissues = [];
            if ($hascourseid) {
                $bycourse = $DB->get_records('tool_certificate_issues', ['courseid' => $courseid]);
                foreach ($bycourse as $iss) {
                    $candidateissues[(int)$iss->id] = $iss;
                }
            }

            // Also check any issues belonging to $migrateduserids that were stuck on the demo template or missing courseid.
            if (!empty($migrateduserids)) {
                list($usql, $uparams) = $DB->get_in_or_equal($migrateduserids, SQL_PARAMS_NAMED, 'uid');
                $byusers = $DB->get_records_select('tool_certificate_issues', "userid {$usql}", $uparams);
                foreach ($byusers as $iss) {
                    $icourse = $hascourseid ? (int)($iss->courseid ?? 0) : 0;
                    if ($icourse === $courseid || $icourse <= 0) {
                        $candidateissues[(int)$iss->id] = $iss;
                    }
                }
            }

            if (empty($candidateissues)) {
                return 0;
            }

            $fs = get_file_storage();
            $syscontextid = (int)context_system::instance()->id;

            foreach ($candidateissues as $iss) {
                $iuid = (int)($iss->userid ?? 0);
                $curtid = (int)($iss->templateid ?? 0);
                $expectedtid = self::resolve_workplace_template_for_user($courseid, $iuid, $coursetemplateid, $companyid);
                if (isset($originidmap[$curtid]) && $expectedtid === $coursetemplateid) {
                    $expectedtid = (int)$originidmap[$curtid];
                }
                if ($expectedtid <= 0) {
                    $expectedtid = $coursetemplateid;
                }

                $needsupdate = false;
                if ($curtid !== $expectedtid) {
                    $iss->templateid = $expectedtid;
                    $needsupdate = true;
                }
                if ($hascourseid && (int)($iss->courseid ?? 0) !== $courseid) {
                    $iss->courseid = $courseid;
                    $needsupdate = true;
                }
                if ($hascomponent && (string)($iss->component ?? '') !== 'mod_coursecertificate') {
                    $iss->component = 'mod_coursecertificate';
                    $needsupdate = true;
                }
                if ($hasarchived && (int)($iss->archived ?? 0) !== 0) {
                    $iss->archived = 0;
                    $needsupdate = true;
                }

                if ($needsupdate) {
                    $DB->update_record('tool_certificate_issues', $iss);
                    $relinked++;

                    // Delete any stale cached PDF generated under the demo template and regenerate with the real template.
                    try {
                        $fs->delete_area_files($syscontextid, 'tool_certificate', 'issues', (int)$iss->id);
                        if (class_exists('\tool_certificate\template')) {
                            $tplinst = \tool_certificate\template::instance($expectedtid);
                            $tplinst->create_issue_file($iss, true);
                        }
                    } catch (Throwable $pdfex) {
                        // Non-fatal: get_issue_file() will regenerate the PDF on demand when viewed.
                    }
                }
            }
        } catch (Throwable $e) {
            // Non-fatal.
        }

        return $relinked;
    }

    /**
     * Fetches Moodle Workplace tool_certificate templates from the Origin Moodle for a migration request,
     * reconstructs them in mdl_tool_certificate_templates / pages / elements / mdl_files on this Target IOMAD server,
     * and re-links mdl_coursecertificate.template and mdl_tool_certificate_issues.templateid in the target course.
     *
     * @param stdClass $req Record from local_iomadtransfer_request
     * @param bool $force If true, queries Origin even if local template already exists
     * @return array {templates_imported: int, templates_linked: int, issues_relinked: int, template_names: string[], error: string}
     */
    public static function sync_and_import_origin_certificate_templates(stdClass $req, bool $force = false): array {
        global $DB;
        $res = [
            'templates_imported' => 0,
            'templates_linked' => 0,
            'issues_relinked' => 0,
            'template_names' => [],
            'error' => '',
        ];

        $courseid = (int)($req->target_course_id ?? 0);
        $origincourseid = (int)($req->origin_course_id ?? 0);
        if ($courseid <= 1 || !$DB->record_exists('course', ['id' => $courseid])) {
            return $res;
        }

        $dbman = $DB->get_manager();
        if (!$dbman->table_exists('coursecertificate') ||
            !$dbman->table_exists('tool_certificate_templates') ||
            !$dbman->table_exists('tool_certificate_pages') ||
            !$dbman->table_exists('tool_certificate_elements')) {
            return $res;
        }

        try {
            $companyid = (int)($req->companyid ?? 0);
            $companytplname = self::resolve_company_certificate_template_name($companyid);
            $uids = self::resolve_request_migrated_userids($req);
            $wpcerts = $DB->get_records('coursecertificate', ['course' => $courseid], 'id ASC');
            if (empty($wpcerts) && !$force) {
                return $res;
            }

            // Check if any coursecertificate in $courseid points to a missing template, a demo template,
            // or if the company's "Certificado - {Company Name}" template does not exist yet.
            $missingtplids = [];
            $needsfetch = $force;
            if ($companytplname !== '') {
                $hascomptpl = $DB->record_exists_select(
                    'tool_certificate_templates',
                    'LOWER(TRIM(name)) = LOWER(:tname)',
                    ['tname' => $companytplname]
                );
                if (!$hascomptpl) {
                    $needsfetch = true;
                }
            }
            foreach ($wpcerts as $wpc) {
                $tid = (int)($wpc->template ?? 0);
                $tplrec = $tid > 0 ? $DB->get_record('tool_certificate_templates', ['id' => $tid]) : null;
                if (!$tplrec) {
                    $missingtplids[$tid] = $tid;
                    $needsfetch = true;
                } else if (stripos((string)$tplrec->name, 'demo') !== false) {
                    $needsfetch = true;
                }
            }

            $originidmap = [];
            if ($needsfetch) {
                $originsite = !empty($req->siteurl) ? sites_manager::get_by_host('origin', (string)$req->siteurl) : null;
                if (!$originsite) {
                    $allorigins = sites_manager::list_sites('origin');
                    if (!empty($allorigins)) {
                        $originsite = reset($allorigins);
                    }
                }
                if (!$originsite) {
                    $res['error'] = 'No se encontró la plataforma de origen registrada para importar la plantilla.';
                } else {
                    $client = new \local_iomadtransfer\api\request($originsite);
                    $firstmissing = !empty($missingtplids) ? (int)reset($missingtplids) : 0;
                    $exported = $client->origin_get_course_templates($origincourseid, 0);
                    if (empty($exported) && $firstmissing > 0) {
                        $exported = $client->origin_get_course_templates($origincourseid, $firstmissing);
                    }
                    if (empty($exported)) {
                        $exported = $client->origin_get_course_templates(0, 0);
                    }

                    if (empty($exported)) {
                        $res['error'] = 'El servidor de origen no devolvió plantillas de tool_certificate (verifica actualizar local_iomadtransfer en el servidor de origen).';
                    } else {
                        // Filter out demo templates if $exported also includes a non-demo course template.
                        $hasnondemo = false;
                        foreach ($exported as $etpl) {
                            if (is_array($etpl) && !empty($etpl['name']) &&
                                (!empty($etpl['is_course_template']) || stripos((string)$etpl['name'], 'demo') === false)) {
                                $hasnondemo = true;
                                break;
                            }
                        }

                        $firstnewid = 0;
                        $preferrednewid = 0;
                        foreach ($exported as $tpldata) {
                            if (!is_array($tpldata) || empty($tpldata['name'])) {
                                continue;
                            }
                            $iscoursetpl = !empty($tpldata['is_course_template']) || stripos((string)$tpldata['name'], 'demo') === false;
                            if ($hasnondemo && !$iscoursetpl && count($exported) > 1) {
                                continue;
                            }
                            $origid = (int)($tpldata['origin_template_id'] ?? 0);
                            $renameas = ($iscoursetpl || count($exported) === 1) ? $companytplname : '';
                            $imported = self::import_workplace_certificate_template($tpldata, $renameas);
                            $newtid = (int)($imported['templateid'] ?? 0);
                            $finalname = (string)($imported['name'] ?? $tpldata['name']);
                            if ($newtid > 0) {
                                if ($origid > 0) {
                                    $originidmap[$origid] = $newtid;
                                }
                                if ($firstnewid === 0) {
                                    $firstnewid = $newtid;
                                }
                                if ($iscoursetpl || $preferrednewid === 0) {
                                    $preferrednewid = $newtid;
                                }
                                if (!empty($imported['created'])) {
                                    $res['templates_imported']++;
                                }
                                $res['template_names'][] = $finalname . ' (ID #' . $newtid . ')';
                            }
                        }

                        $bestnewid = $preferrednewid > 0 ? $preferrednewid : $firstnewid;
                        if ($bestnewid > 0) {
                            foreach ($wpcerts as $wpc) {
                                $curtid = (int)($wpc->template ?? 0);
                                $curtpl = $curtid > 0 ? $DB->get_record('tool_certificate_templates', ['id' => $curtid]) : null;
                                $isdemo = $curtpl && stripos((string)$curtpl->name, 'demo') !== false;
                                $targettid = 0;
                                if (isset($originidmap[$curtid])) {
                                    $targettid = $originidmap[$curtid];
                                } else if (!$curtpl || ($isdemo && $bestnewid !== $curtid)) {
                                    $targettid = $bestnewid;
                                }
                                if ($targettid > 0 && $targettid !== $curtid) {
                                    $wpc->template = $targettid;
                                    $wpc->timemodified = time();
                                    $DB->update_record('coursecertificate', $wpc);
                                    $res['templates_linked']++;
                                }
                            }
                        }
                    }
                }
            }

            // Always align all mdl_tool_certificate_issues for this course (and rename any already-imported course template
            // to "Certificado - {Company Name}") so any certificate that ended up in "Plantilla demo de certificado"
            // is moved to the company/course template and its PDF regenerated!
            $res['issues_relinked'] = self::align_course_workplace_certificate_issues($courseid, $uids, $originidmap, $companyid);
        } catch (Throwable $e) {
            $res['error'] = $e->getMessage();
        }

        return $res;
    }

    /**
     * Reconstructs a single Workplace tool_certificate template (mdl_tool_certificate_templates,
     * mdl_tool_certificate_pages, mdl_tool_certificate_elements, and element/shared images in mdl_files)
     * idempotently on the Target IOMAD server.
     *
     * @param array $tpldata Exported template payload from backup_manager::export_course_certificate_templates()
     * @return array {templateid: int, created: bool}
     */
    public static function import_workplace_certificate_template(array $tpldata, string $targetname = ''): array {
        global $DB, $USER;

        $originname = trim((string)($tpldata['name'] ?? ''));
        $tname = trim($targetname) !== '' ? trim($targetname) : $originname;
        if ($tname === '') {
            return ['templateid' => 0, 'created' => false, 'name' => ''];
        }

        $syscontext = context_system::instance();
        $syscontextid = (int)$syscontext->id;
        $now = time();
        $adminid = !empty($USER->id) ? (int)$USER->id : 2;

        // 1. Check if a template with the target name ("Certificado - {Company Name}") already exists and has pages.
        $existingmatches = $DB->get_records_select(
            'tool_certificate_templates',
            'LOWER(TRIM(name)) = LOWER(:tname)',
            ['tname' => $tname],
            'id ASC',
            '*',
            0,
            1
        );

        // 1b. If not found under $tname, check if it was already imported earlier under its $originname
        //     (excluding the untouched id=1 system demo template) and rename it to $tname!
        if (empty($existingmatches) && $originname !== '' && strtolower($originname) !== strtolower($tname)) {
            $origmatches = $DB->get_records_select(
                'tool_certificate_templates',
                'LOWER(TRIM(name)) = LOWER(:oname)',
                ['oname' => $originname],
                'id DESC',
                '*',
                0,
                1
            );
            if (!empty($origmatches)) {
                $cand = reset($origmatches);
                $isbuiltin1 = ((int)$cand->id === 1 && stripos((string)$cand->name, 'demo') !== false);
                $isothercomp = (stripos(trim((string)$cand->name), 'certificado - ') === 0);
                if (!$isbuiltin1 && !$isothercomp) {
                    $cand->name = $tname;
                    $cand->shared = 1;
                    $cand->timemodified = $now;
                    $DB->update_record('tool_certificate_templates', $cand);
                    $existingmatches = [$cand];
                }
            }
        }

        $created = false;
        if (!empty($existingmatches)) {
            $tplrec = reset($existingmatches);
            $templateid = (int)$tplrec->id;
            $haspages = $DB->record_exists('tool_certificate_pages', ['templateid' => $templateid]);
            if ($haspages) {
                // Ensure shared = 1 and name = $tname so it is accessible across all IOMAD categories/courses.
                if ((int)($tplrec->shared ?? 0) !== 1 || trim((string)$tplrec->name) !== $tname) {
                    $tplrec->name = $tname;
                    $tplrec->shared = 1;
                    $tplrec->timemodified = $now;
                    $DB->update_record('tool_certificate_templates', $tplrec);
                }
                return ['templateid' => $templateid, 'created' => false, 'name' => $tname];
            }
        } else {
            $tplrec = new stdClass();
            $tplrec->name = $tname;
            $tplrec->contextid = $syscontextid;
            $tplrec->shared = 1;
            $tplrec->timecreated = $now;
            $tplrec->timemodified = $now;
            $tplrec->usermodified = $adminid;
            $templateid = (int)$DB->insert_record('tool_certificate_templates', $tplrec);
            $created = true;
        }

        $tctxid = (int)($tplrec->contextid ?: $syscontextid);
        $fs = get_file_storage();

        // 2. Reconstruct pages and elements.
        $pages = !empty($tpldata['pages']) && is_array($tpldata['pages']) ? $tpldata['pages'] : [];
        $pcols = $DB->get_columns('tool_certificate_pages');
        $ecols = $DB->get_columns('tool_certificate_elements');

        foreach ($pages as $pgdata) {
            if (!is_array($pgdata)) {
                continue;
            }
            $pgrec = new stdClass();
            $pgrec->templateid = $templateid;
            $pgrec->width = (int)($pgdata['width'] ?? 297);
            $pgrec->height = (int)($pgdata['height'] ?? 210);
            $pgrec->leftmargin = (int)($pgdata['leftmargin'] ?? 0);
            $pgrec->rightmargin = (int)($pgdata['rightmargin'] ?? 0);
            $pgrec->sequence = (int)($pgdata['sequence'] ?? 1);
            $pgrec->timecreated = $now;
            $pgrec->timemodified = $now;
            if (isset($pcols['usermodified'])) {
                $pgrec->usermodified = $adminid;
            }
            $pageid = (int)$DB->insert_record('tool_certificate_pages', $pgrec);

            $elements = !empty($pgdata['elements']) && is_array($pgdata['elements']) ? $pgdata['elements'] : [];
            foreach ($elements as $eldata) {
                if (!is_array($eldata)) {
                    continue;
                }
                $rawdata = (string)($eldata['data'] ?? '');
                // If element data is JSON with a contextid (like certificateelement_image shared images),
                // align contextid to $syscontextid on this Target server.
                if ($rawdata !== '') {
                    $decoded = json_decode($rawdata, true);
                    if (is_array($decoded) && isset($decoded['contextid'])) {
                        $decoded['contextid'] = $syscontextid;
                        $rawdata = json_encode($decoded);
                    }
                }

                $elrec = new stdClass();
                $elrec->pageid = $pageid;
                $elrec->name = (string)($eldata['name'] ?? '');
                $elrec->element = (string)($eldata['element'] ?? 'text');
                $elrec->data = $rawdata;
                $elrec->font = $eldata['font'] ?? null;
                $elrec->fontsize = isset($eldata['fontsize']) ? (int)$eldata['fontsize'] : null;
                $elrec->colour = $eldata['colour'] ?? null;
                $elrec->posx = isset($eldata['posx']) ? (int)$eldata['posx'] : null;
                $elrec->posy = isset($eldata['posy']) ? (int)$eldata['posy'] : null;
                $elrec->width = isset($eldata['width']) ? (int)$eldata['width'] : null;
                $elrec->refpoint = isset($eldata['refpoint']) ? (int)$eldata['refpoint'] : null;
                $elrec->sequence = (int)($eldata['sequence'] ?? 1);
                $elrec->timecreated = $now;
                $elrec->timemodified = $now;
                if (isset($ecols['usermodified'])) {
                    $elrec->usermodified = $adminid;
                }
                $newelementid = (int)$DB->insert_record('tool_certificate_elements', $elrec);

                // 3. Reconstruct element files and shared images in mdl_files.
                $files = !empty($eldata['files']) && is_array($eldata['files']) ? $eldata['files'] : [];
                foreach ($files as $fdata) {
                    if (!is_array($fdata) || empty($fdata['filename']) || empty($fdata['content_b64'])) {
                        continue;
                    }
                    $bytes = base64_decode((string)$fdata['content_b64'], true);
                    if ($bytes === false || $bytes === '') {
                        continue;
                    }
                    $farea = (string)($fdata['filearea'] ?? 'element');
                    $fpath = (string)($fdata['filepath'] ?? '/');
                    if ($fpath === '' || $fpath[0] !== '/') {
                        $fpath = '/' . $fpath;
                    }
                    if (substr($fpath, -1) !== '/') {
                        $fpath .= '/';
                    }
                    $fname = (string)$fdata['filename'];
                    $isshared = !empty($fdata['is_shared']) || in_array($farea, ['image', 'signature'], true);

                    if ($isshared) {
                        $sharedarea = in_array($farea, ['image', 'signature'], true) ? $farea : 'image';
                        if (!$fs->file_exists($syscontextid, 'tool_certificate', $sharedarea, 0, $fpath, $fname)) {
                            $fs->create_file_from_string([
                                'contextid' => $syscontextid,
                                'component' => 'tool_certificate',
                                'filearea' => $sharedarea,
                                'itemid' => 0,
                                'filepath' => $fpath,
                                'filename' => $fname,
                                'timecreated' => $now,
                                'timemodified' => $now,
                            ], $bytes);
                        }
                    }

                    $elarea = ($farea === 'elementaux') ? 'elementaux' : 'element';
                    if (!$fs->file_exists($tctxid, 'tool_certificate', $elarea, $newelementid, $fpath, $fname)) {
                        $fs->create_file_from_string([
                            'contextid' => $tctxid,
                            'component' => 'tool_certificate',
                            'filearea' => $elarea,
                            'itemid' => $newelementid,
                            'filepath' => $fpath,
                            'filename' => $fname,
                            'timecreated' => $now,
                            'timemodified' => $now,
                        ], $bytes);
                    }
                }
            }
        }

        return ['templateid' => $templateid, 'created' => $created, 'name' => $tname];
    }

    protected static function merge_coursecertificate_data(int $restoredcertid, int $mastercertid, int $courseid): void {
        global $DB;
        $dbman = $DB->get_manager();
        if (!$dbman->table_exists('coursecertificate')) {
            return;
        }

        try {
            $restoredcert = $DB->get_record('coursecertificate', ['id' => $restoredcertid]);
            $mastercert = $DB->get_record('coursecertificate', ['id' => $mastercertid]);
            if (!$mastercert) {
                return;
            }

            $mastertemplateid = (int)($mastercert->template ?? 0);
            $restoredtemplateid = $restoredcert ? (int)($restoredcert->template ?? 0) : 0;

            if ($dbman->table_exists('tool_certificate_issues')) {
                $tcols = $DB->get_columns('tool_certificate_issues');
                $hascourseid = isset($tcols['courseid']);

                if ($hascourseid) {
                    $issues = $DB->get_records('tool_certificate_issues', ['courseid' => $courseid]);
                    foreach ($issues as $iss) {
                        if ($mastertemplateid > 0 && (int)$iss->templateid !== $mastertemplateid) {
                            $duplicate = $DB->record_exists('tool_certificate_issues', [
                                'courseid' => $courseid,
                                'userid' => $iss->userid,
                                'templateid' => $mastertemplateid,
                            ]);
                            if (!$duplicate) {
                                $iss->templateid = $mastertemplateid;
                                $DB->update_record('tool_certificate_issues', $iss);
                            }
                        }
                    }
                }

                if ($restoredtemplateid > 0 && $mastertemplateid > 0 && $restoredtemplateid !== $mastertemplateid) {
                    $rissues = $DB->get_records('tool_certificate_issues', ['templateid' => $restoredtemplateid]);
                    foreach ($rissues as $riss) {
                        if ($hascourseid && !empty($riss->courseid) && (int)$riss->courseid !== $courseid) {
                            continue;
                        }
                        $params = ['userid' => $riss->userid, 'templateid' => $mastertemplateid];
                        if ($hascourseid) {
                            $params['courseid'] = $courseid;
                        }
                        if (!$DB->record_exists('tool_certificate_issues', $params)) {
                            $riss->templateid = $mastertemplateid;
                            if ($hascourseid) {
                                $riss->courseid = $courseid;
                            }
                            $DB->update_record('tool_certificate_issues', $riss);
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            // Non-fatal: continue.
        }
    }

    /**
     * Ensures that any CustomCert (mdl_customcert_issues) or Workplace Course Certificate (mdl_tool_certificate_issues)
     * captured from the backup XMLs is present in the target Master Course, even when cross-server restore
     * skips tool_certificate_issues due to !is_samesite().
     *
     * @param int $courseid
     * @param array $backupidentities
     */
    protected static function ensure_extracted_certificates_applied(int $courseid, array $backupidentities): void {
        global $DB;
        if (empty($backupidentities)) {
            return;
        }

        try {
            $dbman = $DB->get_manager();
            $hascustomcert = $dbman->table_exists('customcert') && $dbman->table_exists('customcert_issues');
            $hasworkplacecert = $dbman->table_exists('coursecertificate') && $dbman->table_exists('tool_certificate_issues');
            if (!$hascustomcert && !$hasworkplacecert) {
                return;
            }

            $course = $DB->get_record('course', ['id' => $courseid], 'id, fullname, shortname');
            $customcerts = $hascustomcert ? array_values($DB->get_records('customcert', ['course' => $courseid], 'id ASC')) : [];
            $wpcerts = $hasworkplacecert ? array_values($DB->get_records('coursecertificate', ['course' => $courseid], 'id ASC')) : [];
            $tcols = $hasworkplacecert ? $DB->get_columns('tool_certificate_issues') : [];

            foreach ($backupidentities as $ident) {
                if (empty($ident['certificates']) || !is_array($ident['certificates'])) {
                    continue;
                }
                $uid = (int)($ident['targetuserid'] ?? 0);
                if ($uid <= 2 || is_siteadmin($uid)) {
                    continue;
                }

                foreach ($ident['certificates'] as $centry) {
                    $cmod = (string)($centry['mod'] ?? '');
                    $code = trim((string)($centry['code'] ?? ''));
                    $norm = (string)($centry['normname'] ?? '');
                    if ($code === '') {
                        continue;
                    }

                    if ($cmod === 'customcert' && $hascustomcert && !empty($customcerts)) {
                        $targetcert = $customcerts[0];
                        foreach ($customcerts as $cc) {
                            if ($norm !== '' && self::normalize_name((string)$cc->name) === $norm) {
                                $targetcert = $cc;
                                break;
                            }
                        }
                        if (!$DB->record_exists('customcert_issues', ['customcertid' => $targetcert->id, 'userid' => $uid]) &&
                            !$DB->record_exists('customcert_issues', ['code' => $code])) {
                            $iss = new stdClass();
                            $iss->userid = $uid;
                            $iss->customcertid = (int)$targetcert->id;
                            $iss->code = $code;
                            $iss->emailed = (int)($centry['emailed'] ?? 0);
                            $iss->timecreated = (int)($centry['timecreated'] ?? time());
                            $DB->insert_record('customcert_issues', $iss);
                        }
                    } else if ($cmod === 'coursecertificate' && $hasworkplacecert) {
                        $targetwp = !empty($wpcerts) ? $wpcerts[0] : null;
                        foreach ($wpcerts as $wpc) {
                            if ($norm !== '' && self::normalize_name((string)$wpc->name) === $norm) {
                                $targetwp = $wpc;
                                break;
                            }
                        }
                        $defaulttplid = $targetwp && !empty($targetwp->template)
                            ? (int)$targetwp->template
                            : (int)($centry['templateid'] ?? 0);
                        $templateid = self::resolve_workplace_template_for_user($courseid, $uid, $defaulttplid);

                        $existingiss = $DB->get_record('tool_certificate_issues', ['code' => $code]);
                        if (!$existingiss && isset($tcols['courseid'])) {
                            $existingiss = $DB->get_record('tool_certificate_issues', [
                                'userid' => $uid,
                                'courseid' => $courseid,
                            ]);
                        }
                        if ($existingiss) {
                            $changed = false;
                            if ($templateid > 0 && (int)$existingiss->templateid !== $templateid) {
                                $existingiss->templateid = $templateid;
                                $changed = true;
                            }
                            if (isset($tcols['courseid']) && (int)($existingiss->courseid ?? 0) !== $courseid) {
                                $existingiss->courseid = $courseid;
                                $changed = true;
                            }
                            if (isset($tcols['component']) && (string)($existingiss->component ?? '') !== 'mod_coursecertificate') {
                                $existingiss->component = 'mod_coursecertificate';
                                $changed = true;
                            }
                            if ($changed) {
                                $DB->update_record('tool_certificate_issues', $existingiss);
                            }
                        } else {
                            $iss = new stdClass();
                            $iss->userid = $uid;
                            $iss->templateid = $templateid;
                            $iss->code = $code;
                            if (isset($tcols['emailed'])) {
                                $iss->emailed = (int)($centry['emailed'] ?? 0);
                            }
                            if (isset($tcols['timecreated'])) {
                                $iss->timecreated = (int)($centry['timecreated'] ?? time());
                            }
                            if (isset($tcols['expires'])) {
                                $iss->expires = (int)($centry['expires'] ?? 0);
                            }
                            if (isset($tcols['data'])) {
                                $rawdata = (string)($centry['data'] ?? '');
                                $iss->data = $rawdata !== '' ? $rawdata : json_encode([
                                    'coursename' => $course ? (string)$course->fullname : '',
                                ]);
                            }
                            if (isset($tcols['component'])) {
                                $iss->component = 'mod_coursecertificate';
                            }
                            if (isset($tcols['courseid'])) {
                                $iss->courseid = $courseid;
                            }
                            if (isset($tcols['archived'])) {
                                $iss->archived = 0;
                            }
                            $DB->insert_record('tool_certificate_issues', $iss);
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            // Non-fatal: continue.
        }
    }

    /**
     * Moves student glossary entries into the Master glossary.
     *
     * @param int $restoredglossaryid
     * @param int $masterglossaryid
     */
    protected static function merge_glossary_data(int $restoredglossaryid, int $masterglossaryid): void {
        global $DB;
        if (!$DB->get_manager()->table_exists('glossary_entries')) {
            return;
        }
        $entries = $DB->get_records('glossary_entries', ['glossaryid' => $restoredglossaryid]);
        foreach ($entries as $ent) {
            if (!$DB->record_exists('glossary_entries', ['glossaryid' => $masterglossaryid, 'userid' => $ent->userid, 'concept' => $ent->concept])) {
                $ent->glossaryid = $masterglossaryid;
                $DB->update_record('glossary_entries', $ent);
            }
        }
    }

    /**
     * Moves student attendance sessions and logs (mod_attendance) into the Master attendance instance.
     *
     * @param int $restoredattid
     * @param int $masterattid
     */
    protected static function merge_attendance_data(int $restoredattid, int $masterattid): void {
        global $DB;
        if (!$DB->get_manager()->table_exists('attendance_sessions')) {
            return;
        }
        $sessions = $DB->get_records('attendance_sessions', ['attendanceid' => $restoredattid]);
        foreach ($sessions as $sess) {
            $sess->attendanceid = $masterattid;
            $DB->update_record('attendance_sessions', $sess);
        }
    }

    /**
     * Merges activity completion records (mdl_course_modules_completion) from the restored cmid into the Master cmid.
     *
     * @param int $restoredcmid
     * @param int $mastercmid
     * @return int Number of completion records moved
     */
    protected static function merge_cm_completions(int $restoredcmid, int $mastercmid): int {
        global $DB;
        $moved = 0;

        $completions = $DB->get_records('course_modules_completion', ['coursemoduleid' => $restoredcmid]);
        foreach ($completions as $comp) {
            $existing = $DB->get_record('course_modules_completion', [
                'coursemoduleid' => $mastercmid,
                'userid' => $comp->userid,
            ]);
            if (!$existing) {
                $comp->coursemoduleid = $mastercmid;
                $DB->update_record('course_modules_completion', $comp);
                $moved++;
            } else if ((int)$comp->completionstate > (int)$existing->completionstate) {
                $existing->completionstate = $comp->completionstate;
                $existing->viewed = max((int)$existing->viewed, (int)$comp->viewed);
                $existing->timemodified = max((int)$existing->timemodified, (int)$comp->timemodified);
                $DB->update_record('course_modules_completion', $existing);
                $moved++;
            }
        }

        return $moved;
    }

    /**
     * Merges student grades (mdl_grade_grades) from the restored module's grade_items into the Master module's grade_items.
     *
     * @param int $courseid
     * @param string $modname
     * @param int $restoredinstance
     * @param int $masterinstance
     * @return int Number of student grades merged
     */
    protected static function merge_module_grades(
        int $courseid,
        string $modname,
        int $restoredinstance,
        int $masterinstance
    ): int {
        global $DB;
        $moved = 0;

        $restoreditems = $DB->get_records('grade_items', [
            'courseid' => $courseid,
            'itemtype' => 'mod',
            'itemmodule' => $modname,
            'iteminstance' => $restoredinstance,
        ]);

        foreach ($restoreditems as $ritem) {
            $masteritem = $DB->get_record('grade_items', [
                'courseid' => $courseid,
                'itemtype' => 'mod',
                'itemmodule' => $modname,
                'iteminstance' => $masterinstance,
                'itemnumber' => $ritem->itemnumber,
            ]);
            if (!$masteritem) {
                continue;
            }

            $rgrades = $DB->get_records('grade_grades', ['itemid' => $ritem->id]);
            foreach ($rgrades as $rg) {
                if ((int)$rg->userid <= 0 || ($rg->finalgrade === null && $rg->rawgrade === null && empty($rg->feedback))) {
                    continue;
                }

                $mg = $DB->get_record('grade_grades', [
                    'itemid' => $masteritem->id,
                    'userid' => $rg->userid,
                ]);

                if (!$mg) {
                    $rg->itemid = $masteritem->id;
                    $DB->update_record('grade_grades', $rg);
                    $moved++;
                } else if ($mg->finalgrade === null || (float)$rg->finalgrade > (float)$mg->finalgrade) {
                    $mg->rawgrade = $rg->rawgrade;
                    $mg->finalgrade = $rg->finalgrade;
                    $mg->feedback = $rg->feedback;
                    $mg->feedbackformat = $rg->feedbackformat;
                    $mg->information = $rg->information;
                    $mg->usermodified = $rg->usermodified;
                    $mg->timemodified = $rg->timemodified;
                    $DB->update_record('grade_grades', $mg);
                    $moved++;
                }
            }
        }

        return $moved;
    }

    /**
     * Deletes a temporary duplicate course module, its section sequence reference, its grade_items,
     * its module instance record, and its context cleanly without triggering recycle bin backups.
     *
     * @param int $courseid
     * @param stdClass $restoredcm
     */
    protected static function delete_temporary_duplicate_cm(int $courseid, stdClass $restoredcm): void {
        global $DB;
        $cmid = (int)$restoredcm->cmid;
        $instanceid = (int)$restoredcm->instance;
        $modname = (string)$restoredcm->modname;

        try {
            // 1. Remove cmid from course_sections.sequence.
            $section = $DB->get_record('course_sections', ['id' => $restoredcm->section]);
            if ($section && !empty($section->sequence)) {
                $seq = array_filter(explode(',', $section->sequence), function ($id) use ($cmid) {
                    return (int)trim($id) !== $cmid;
                });
                $DB->set_field('course_sections', 'sequence', implode(',', $seq), ['id' => $section->id]);
            }

            // 2. Delete leftover grade_grades and grade_items for the temporary duplicate instance.
            $gitems = $DB->get_records('grade_items', [
                'courseid' => $courseid,
                'itemtype' => 'mod',
                'itemmodule' => $modname,
                'iteminstance' => $instanceid,
            ]);
            foreach ($gitems as $gi) {
                $DB->delete_records('grade_grades', ['itemid' => $gi->id]);
                $DB->delete_records('grade_items', ['id' => $gi->id]);
            }

            // 3. Delete leftover completions and availability records for the duplicate cmid.
            $DB->delete_records('course_modules_completion', ['coursemoduleid' => $cmid]);

            // 4. Delete the module instance row and course_modules row.
            if ($DB->get_manager()->table_exists($modname)) {
                $DB->delete_records($modname, ['id' => $instanceid]);
            }
            $DB->delete_records('course_modules', ['id' => $cmid]);

            // 5. Delete context.
            \context_helper::delete_instance(CONTEXT_MODULE, $cmid);
        } catch (Throwable $e) {
            // Fallback to core course_delete_module if needed.
            try {
                course_delete_module($cmid, false);
            } catch (Throwable $ignored) {
                // Ignore.
            }
        }
    }

    /**
     * Normalizes an activity name for deterministic matching across backups.
     *
     * @param string $name
     * @return string
     */
    public static function normalize_name(string $name): string {
        $name = strtolower(trim($name));
        // Strip Moodle's automatic "(copy)" or "(copia)" suffix added during merge restores!
        $name = preg_replace('/\s*\((copy|copia)(\s+\d+)?\)\s*$/i', '', $name);
        $name = preg_replace('/\s+/', ' ', $name);
        return trim($name);
    }
}
