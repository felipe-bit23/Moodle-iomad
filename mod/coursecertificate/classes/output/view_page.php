<?php
// This file is part of the mod_coursecertificate plugin for Moodle - http://moodle.org/
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

namespace mod_coursecertificate\output;

use cm_info;
use completion_info;
use context_module;
use core_reportbuilder\system_report;
use mod_coursecertificate\helper;
use mod_coursecertificate\permission;
use templatable;
use renderable;
use core_reportbuilder\system_report_factory;
use tool_certificate\reportbuilder\local\systemreports\issues;

/**
 * Certificate issues report renderable class with Trema-Style Multi-Tenant IOMAD Company Selection.
 *
 * @package     mod_coursecertificate
 * @copyright   2020 Mikel Martín <mikel@moodle.com>
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class view_page implements templatable, renderable {

    /** @var \stdClass $certificate */
    protected $certificate;

    /** @var int $perpage */
    protected $perpage;

    /** @var system_report $report */
    protected $report;

    /** @var bool $canmanage */
    protected $canmanage;

    /** @var bool $canviewreport */
    protected $canviewreport;

    /** @var bool $canreceiveissues */
    protected $canreceiveissues;

    /** @var bool */
    private $canviewall;

    /** @var cm_info $cm */
    protected $cm;

    /** @var string $issuecode */
    protected $issuecode;

    /** @var int $resolvedtemplateid */
    protected $resolvedtemplateid;

    /** @var string $resolvedtemplatename */
    protected $resolvedtemplatename = '';

    /** @var int $activegroupid */
    protected $activegroupid = 0;

    /** @var bool $selectcompanyprompt */
    protected $selectcompanyprompt = false;

    /**
     * Constructor.
     *
     * @param int $id
     * @param int $page
     * @param int $perpage
     * @param \stdClass $course
     * @param cm_info $cm
     */
    public function __construct(int $id, int $page, int $perpage, \stdClass $course, cm_info $cm) {
        global $DB, $USER;

        $this->perpage = $perpage;
        $this->cm = $cm;

        $context = context_module::instance($this->cm->id);
        $this->certificate = $DB->get_record('coursecertificate', ['id' => $this->cm->instance], '*', MUST_EXIST);
        $this->canviewreport = permission::can_view_report($context);
        $this->canmanage = permission::can_manage($context);
        $this->canviewall = permission::can_view_all_issues($course->id);
        $this->canreceiveissues = permission::can_receive_issues($context);

        // Trigger the event.
        $event = \mod_coursecertificate\event\course_module_viewed::create([
            'objectid' => $this->certificate->id,
            'context' => $context,
        ]);
        $event->add_record_snapshot('course', $course);
        $event->add_record_snapshot('coursecertificate', $this->certificate);
        $event->trigger();

        // Update the completion.
        $completion = new completion_info($course);
        $completion->set_module_viewed($this->cm);

        // Get the current group.
        $groupid = (int) groups_get_activity_group($this->cm, true);
        $this->activegroupid = $groupid;

        // Multi-Tenant IOMAD Navbar Company Synchronization:
        $navbarcompanyid = helper::get_active_iomad_company_id();
        if ($navbarcompanyid > 0 && $DB->get_manager()->table_exists('company_course_groups')) {
            $navbarccg = $DB->get_record('company_course_groups', [
                'companyid' => $navbarcompanyid,
                'courseid' => $course->id,
            ]);
            $switchgroup = false;
            if ($groupid === 0) {
                $switchgroup = true;
            } else if ($navbarccg && !empty($navbarccg->groupid) && (int)$navbarccg->groupid !== $groupid) {
                // If the currently selected group belongs to a different company, switch to active navbar company's group.
                $curgroupcomp = $DB->get_record('company_course_groups', [
                    'courseid' => $course->id,
                    'groupid' => $groupid,
                ]);
                if ($curgroupcomp && (int)$curgroupcomp->companyid !== $navbarcompanyid) {
                    $switchgroup = true;
                }
            }
            if ($switchgroup && $navbarccg && !empty($navbarccg->groupid)) {
                $groupid = (int)$navbarccg->groupid;
                $this->activegroupid = $groupid;

                // Synchronize active group in Moodle session so UI dropdown menu also reflects the company group.
                global $SESSION;
                $gmode = groups_get_activity_groupmode($this->cm);
                if ($gmode && isset($SESSION)) {
                    if (!isset($SESSION->activegroup)) {
                        $SESSION->activegroup = [];
                    }
                    if (!isset($SESSION->activegroup[$course->id])) {
                        $SESSION->activegroup[$course->id] = [SEPARATEGROUPS => [], VISIBLEGROUPS => [], 'aag' => []];
                    }
                    $SESSION->activegroup[$course->id][$gmode][$this->cm->groupingid] = $groupid;
                    if (has_capability('moodle/site:accessallgroups', $context)) {
                        $SESSION->activegroup[$course->id]['aag'][$this->cm->groupingid] = $groupid;
                    }
                }
            }
        }

        $hastpltbl = $DB->get_manager()->table_exists('tool_certificate_templates');

        // Resolve the effective template for the current student (or group/company manager).
        $usertemplateid = helper::resolve_user_template_id(
            (int)$USER->id,
            (int)$course->id,
            (int)$this->certificate->template
        );
        $validusertemplate = $usertemplateid > 0 &&
            $hastpltbl &&
            $DB->record_exists('tool_certificate_templates', ['id' => $usertemplateid]);
        $this->resolvedtemplateid = $validusertemplate ? $usertemplateid : 0;

        // View certificate issue PDF if user can not manage, can receive issues and resolved template is valid.
        if (!$this->canviewall && $this->canreceiveissues && $validusertemplate) {
            helper::issue_certificate($USER, $this->certificate, $course);
            if ($existingcertificate = helper::get_user_certificate($USER->id, $course->id, $usertemplateid)) {
                $this->issuecode = $existingcertificate->code;
            }
        }

        // Show issues table for teachers / Site Admins / Company Managers using the selected group's resolved template.
        if ($this->canviewreport) {
            $groupmode = groups_get_activity_groupmode($this->cm);
            $allowedgroups = ($groupmode !== NOGROUPS) ? groups_get_activity_allowed_groups($this->cm) : [];

            // Trema / IOMAD Multi-Tenant Company Selector Behavior:
            // If the viewer is a Site Admin / Manager with access to multiple company groups and currently has
            // "All participants" ($groupid == 0) selected in a course with groups, prompt them to select a company
            // from the dropdown menu so each company's template and report are displayed cleanly!
            if ($groupid === 0 && !empty($allowedgroups)) {
                if (!$this->canviewall && count($allowedgroups) >= 1) {
                    // For a non-admin manager with 1+ allowed groups, automatically select their first company group.
                    $firstgrp = reset($allowedgroups);
                    $groupid = (int)$firstgrp->id;
                    $this->activegroupid = $groupid;
                } else if ($this->canviewall) {
                    // Site Admin viewing "Todos los participantes":
                    // If a company is already selected in the navbar ($navbarcompanyid > 0), do NOT block with the prompt.
                    // Only show prompt if no company is active in the navbar.
                    if ($navbarcompanyid <= 0) {
                        $this->selectcompanyprompt = true;
                        $this->resolvedtemplateid = 0;
                        return;
                    }
                }
            }

            $reporttemplateid = helper::resolve_group_template_id(
                $groupid,
                (int)$course->id,
                (int)$this->certificate->template,
                (int)$USER->id
            );
            $tplrec = ($reporttemplateid > 0 && $hastpltbl)
                ? $DB->get_record('tool_certificate_templates', ['id' => $reporttemplateid])
                : null;
            $validreporttemplate = !empty($tplrec);
            $this->resolvedtemplateid = $validreporttemplate ? (int)$tplrec->id : 0;
            $this->resolvedtemplatename = $validreporttemplate ? format_string($tplrec->name) : '';

            if ($validreporttemplate) {
                $this->report = system_report_factory::create(issues::class, $context, '', '', 0, [
                    'templateid' => (int)$tplrec->id,
                    'groupid' => $groupid,
                ]);
            }
        }
    }

    /**
     * Function to export the renderer data in a format that is suitable for a mustache template.
     *
     * @param \renderer_base $output
     * @return \stdClass|array
     */
    public function export_for_template(\renderer_base $output) {
        global $PAGE;
        $data = [];
        $data['certificateid'] = $this->certificate->id;
        $data['automaticsend'] = $this->certificate->automaticsend;
        $data['groupselector'] = groups_print_activity_menu($this->cm, $PAGE->url, true);

        if ($this->selectcompanyprompt) {
            $data['report'] = \html_writer::div(
                '<i class="fa fa-building-o mr-2" aria-hidden="true"></i>' .
                '<strong>Selecciona una empresa en el menú desplegable superior</strong> para ver y descargar sus certificados emitidos.',
                'alert alert-info mt-3 mb-3'
            );
            $data['showreport'] = true;
            $data['showdownloadall'] = false;
            $data['notemplateselected'] = false;
        } else {
            if (isset($this->report)) {
                $tplbadge = '';
                if ($this->resolvedtemplatename !== '') {
                    $tplbadge = \html_writer::div(
                        '<i class="fa fa-certificate mr-1" aria-hidden="true"></i> Plantilla activa: <strong>' .
                        s($this->resolvedtemplatename) . '</strong>',
                        'alert alert-info py-2 px-3 mt-2 mb-3',
                        ['style' => 'display:inline-block; font-size:0.9rem;']
                    );
                }
                $data['report'] = $tplbadge . $this->report->output();
            }
            $data['showreport'] = $this->canviewreport && isset($this->report);
            if ($this->canviewreport && !empty($this->resolvedtemplateid)) {
                $data['showdownloadall'] = true;
                $dlparams = [
                    'id' => $this->cm->id,
                    'downloadall' => 1,
                ];
                if ($this->activegroupid > 0) {
                    $dlparams['group'] = $this->activegroupid;
                }
                $data['downloadallurl'] = (new \moodle_url('/mod/coursecertificate/view.php', $dlparams))->out(false);
            }
            $data['notemplateselected'] = empty($this->resolvedtemplateid);
        }

        $data['showautomaticsend'] = $this->canmanage;
        $data['studentview'] = !$this->canviewall && $this->canreceiveissues;
        $data['showhiddenwarning'] = $this->certificate->automaticsend && !$this->cm->visible;
        $data['shownoautosendinfo'] = !$this->certificate->automaticsend && $this->cm->visible;
        $data['issuecode'] = $this->issuecode;
        if ($this->issuecode) {
            $data['viewurl'] = \tool_certificate\template::view_url($data['issuecode'])->out(false);
        }

        return $data;
    }
}
