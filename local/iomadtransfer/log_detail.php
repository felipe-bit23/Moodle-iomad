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
 * On-Demand Single Request Audit & Certificate Verification Page for local_iomadtransfer.
 * Executes detailed SQL queries (students, grades, completions, Company Managers, CustomCert & Workplace
 * tool_certificate issues and templates) strictly on demand for a single request ID.
 *
 * @package    local_iomadtransfer
 * @copyright  2026 IOMAD Multi-Tenant Consolidation Engine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_iomadtransfer\iomad_consolidator;
use local_iomadtransfer\request_manager;
use local_iomadtransfer\security_manager;
use local_iomadtransfer\ui_helper;

require_login();
$context = context_system::instance();
require_capability('local/iomadtransfer:view_logs', $context);

$id = required_param('id', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);

$r = request_manager::get($id);
$logsurl = new moodle_url('/local/iomadtransfer/logs.php');
if (!$r) {
    redirect($logsurl, 'La solicitud #' . $id . ' no existe.', null, \core\output\notification::NOTIFY_ERROR);
}

$pageurl = new moodle_url('/local/iomadtransfer/log_detail.php', ['id' => $id]);
$PAGE->set_context($context);
$PAGE->set_url($pageurl);
$PAGE->set_pagelayout('admin');
$PAGE->set_title('Detalle de Operación #' . $id . ' - IOMAD Transfer');
$PAGE->set_heading(get_string('pluginname', 'local_iomadtransfer'));

if ($action === 'syncrequest') {
    require_sesskey();
    $stats = iomad_consolidator::repair_and_sync_all_iomad_courses(0, 0, $id);
    $tplpart = !empty($stats['templates_imported'])
        ? ', ' . (int)$stats['templates_imported'] . ' plantilla(s) de certificado importada(s) desde Origen'
        : '';
    $msg = 'Sincronización de la solicitud #' . $id . ' completada: '
        . (int)$stats['users_synced'] . ' estudiante(s), '
        . (int)($stats['managers_synced'] ?? 0) . ' gestor(es) con rol Client Course Access, '
        . (int)($stats['certificates_verified'] ?? 0) . ' certificado(s) verificado(s)' . $tplpart . '.';
    redirect($pageurl, $msg, null, \core\output\notification::NOTIFY_SUCCESS);
} else if ($action === 'synctemplate') {
    require_sesskey();
    $tplres = iomad_consolidator::sync_and_import_origin_certificate_templates($r, true);
    request_manager::sync_company_managers_and_certificates($r);
    if (!empty($tplres['error'])) {
        redirect($pageurl, 'Aviso al sincronizar plantilla: ' . $tplres['error'], null, \core\output\notification::NOTIFY_WARNING);
    }
    $names = !empty($tplres['template_names']) ? implode(', ', $tplres['template_names']) : 'Plantilla verificada';
    $msg = 'Plantilla(s) de certificado sincronizada(s) desde Origen correctamente: ' . $names
        . ' (' . (int)($tplres['templates_linked'] ?? 0) . ' actividad(es) y '
        . (int)($tplres['issues_relinked'] ?? 0) . ' certificado(s) emitido(s) revinculado(s) al curso).';
    redirect($pageurl, $msg, null, \core\output\notification::NOTIFY_SUCCESS);
}

$companies = security_manager::get_iomad_companies();
$audit = request_manager::get_detailed_audit_report($r);

$st = (int)$r->status;
$badge = request_manager::get_status_badge($st);
$dirlabel = ((int)$r->direction === request_manager::DIRECTION_TARGET) ? 'Consolidación IOMAD' : 'Exportación MBZ';
$origtitle = trim(($r->origin_course_fullname ?: '') ?: ($r->origin_course_shortname ?: 'Curso #' . (int)$r->origin_course_id));
$origsub = 'ID #' . (int)$r->origin_course_id . ($r->origin_course_shortname ? ' · ' . $r->origin_course_shortname : '');

$targetlink = '<span style="font-weight:700;">Curso #' . (int)$r->target_course_id . '</span>';
if ((int)$r->target_course_id > 1) {
    $turl = new moodle_url('/course/view.php', ['id' => (int)$r->target_course_id]);
    $targetlink = '<a href="' . s($turl->out(false)) . '" target="_blank" style="font-weight:700; color:var(--it-accent); text-decoration:none;"><i class="fa fa-book" aria-hidden="true"></i> Curso Maestro #' . (int)$r->target_course_id . ' <i class="fa fa-external-link" style="font-size:0.75rem;" aria-hidden="true"></i></a>';
}

$compbadges = '';
if ((int)$r->companyid > 0) {
    $cname = isset($companies[$r->companyid]) ? $companies[$r->companyid]->name : 'Empresa #' . (int)$r->companyid;
    $compbadges .= '<span class="ct-platform-iomad-comp"><i class="fa fa-building-o" aria-hidden="true"></i> ' . s($cname) . '</span>';
    if ((int)$r->company_groupid > 0) {
        $compbadges .= ' <span class="ct-direction-badge"><i class="fa fa-users" aria-hidden="true"></i> Grupo IOMAD #' . (int)$r->company_groupid . '</span>';
    }
}

$dlmb = round(((int)$r->downloaded) / 1048576, 1);
$totmb = round(((int)($r->origin_backup_size ?: $r->origin_backup_size_estimated)) / 1048576, 1);
$pct = (int)$r->restored;
if ($st === request_manager::STATUS_COMPLETED) {
    $pct = 100;
}
$barcolor = ($st === request_manager::STATUS_ERROR) ? 'var(--it-rose)' : (($st === request_manager::STATUS_COMPLETED) ? 'var(--it-emerald)' : 'var(--it-accent)');

$sum = !empty($r->consolidate_summary) ? json_decode($r->consolidate_summary, true) : [];
if (!is_array($sum)) {
    $sum = [];
}
$usersproc = (int)($sum['users_processed'] ?? 0);
$ucomp = (int)($sum['users_assigned_to_company'] ?? 0);
$ugroup = (int)($sum['users_added_to_iomad_group'] ?? 0);

$synctplurl = new moodle_url('/local/iomadtransfer/log_detail.php', [
    'id' => $id,
    'action' => 'synctemplate',
    'sesskey' => sesskey(),
]);

echo $OUTPUT->header();
echo '<div class="local-iomadtransfer">';
echo '<div class="ct-card">';

$topactions = '<div style="display:flex; gap:0.5rem; flex-wrap:wrap;">';
$topactions .= '<a href="' . s($logsurl->out(false)) . '" class="ct-btn-secondary"><i class="fa fa-arrow-left" aria-hidden="true"></i> Volver a Logs</a>';
if ((int)$r->direction === request_manager::DIRECTION_TARGET && $st === request_manager::STATUS_COMPLETED) {
    if ((int)$r->companyid > 0 && security_manager::has_iomad()) {
        $syncurl = new moodle_url('/local/iomadtransfer/log_detail.php', [
            'id' => $id,
            'action' => 'syncrequest',
            'sesskey' => sesskey(),
        ]);
        $topactions .= '<a href="' . s($syncurl->out(false)) . '" class="ct-btn-secondary" style="color:var(--it-accent)!important; border-color:var(--it-accent-border)!important;"><i class="fa fa-users" aria-hidden="true"></i> Sincronizar Solicitud #' . $id . '</a>';
    }
    if (!empty($audit['workplace_only_instances'])) {
        $topactions .= '<a href="' . s($synctplurl->out(false)) . '" class="ct-btn-secondary" style="color:var(--it-emerald)!important; border-color:var(--it-emerald-border)!important;"><i class="fa fa-certificate" aria-hidden="true"></i> Sincronizar Plantilla desde Origen</a>';
    }
}
if ((int)$r->direction === request_manager::DIRECTION_TARGET && ($st === request_manager::STATUS_ERROR || ($st === request_manager::STATUS_COMPLETED && $usersproc === 0))) {
    $retryurl = new moodle_url('/local/iomadtransfer/retry.php', ['id' => $id, 'sesskey' => sesskey()]);
    $topactions .= '<a href="' . s($retryurl->out(false)) . '" class="ct-btn-secondary" style="color:var(--it-amber)!important; border-color:var(--it-amber-border)!important;"><i class="fa fa-repeat" aria-hidden="true"></i> Reintentar</a>';
}
$topactions .= '<a href="' . s($pageurl->out(false)) . '" class="ct-btn-secondary" title="Actualizar vista"><i class="fa fa-refresh" aria-hidden="true"></i></a>';
$topactions .= '</div>';

echo ui_helper::render_header_banner($topactions, 'logs');

echo '<article class="it-stream-card">';

// 1. Header & Route Pipeline.
echo '<div class="it-stream-top">';
echo '<div class="it-stream-id-group">';
echo '<span class="it-stream-id">#' . $id . '</span>';
echo '<span class="' . s($badge['class']) . '">' . s($badge['label']) . '</span>';
echo '<span class="ct-direction-badge">' . s($dirlabel) . '</span>';
echo '<span class="it-node-host">' . s($r->siteurl) . '</span>';
if ($compbadges !== '') {
    echo $compbadges;
}
echo '</div>';
echo '<div style="font-size:0.78rem; color:var(--it-text-subtle); font-variant-numeric:tabular-nums;">';
echo 'Creada: ' . userdate((int)$r->timecreated, '%d %b %Y, %H:%M:%S') . ' &middot; Actualizada: ' . userdate((int)$r->timemodified, '%d %b %Y, %H:%M:%S');
echo '</div>';
echo '</div>';

echo '<div class="it-stream-pipeline" style="margin-bottom:0;">';
echo '<div class="it-stream-route">';
echo '<div>';
echo '<div style="font-size:0.95rem; font-weight:700; color:var(--it-text-primary);">' . s($origtitle) . '</div>';
echo '<div style="font-size:0.76rem; color:var(--it-text-muted); font-family:monospace;">' . s($origsub) . '</div>';
echo '</div>';
echo '<i class="fa fa-long-arrow-right it-stream-arrow" aria-hidden="true"></i>';
echo '<div>' . $targetlink . '</div>';
echo '</div>';

echo '<div style="min-width:190px; text-align:right;">';
echo '<div style="font-size:0.76rem; color:var(--it-text-secondary); font-variant-numeric:tabular-nums;">' . $dlmb . ($totmb > 0 ? ' / ' . $totmb : '') . ' MB &middot; <strong>' . $pct . '%</strong></div>';
echo '<div style="background:#e2e8f0; border-radius:9999px; height:6px; overflow:hidden; margin-top:4px;"><div style="background:' . $barcolor . '; height:100%; border-radius:9999px; width:' . $pct . '%;"></div></div>';
echo '</div>';
echo '</div>';

if (!empty($r->error_message)) {
    echo '<div style="font-size:0.82rem; color:var(--it-rose); margin-top:0.65rem;"><i class="fa fa-exclamation-triangle" aria-hidden="true"></i> <strong>' . s((string)$r->error_code) . ':</strong> ' . s((string)$r->error_message) . '</div>';
}

// 2. Full Audit Details Section (Loaded strictly on demand for this single request!).
echo '<div class="it-details-drawer" style="display:block;">';

$ucls = ($usersproc > 0) ? 'is-users' : 'is-zero-warn';
echo '<div class="it-drawer-metrics-row">';
echo '<span class="ct-metric-chip ' . $ucls . '"><i class="fa fa-user" aria-hidden="true"></i> Usuarios: <strong>' . $usersproc . '</strong></span>';
echo '<span class="ct-metric-chip ' . ($ucomp > 0 ? 'is-company' : '') . '"><i class="fa fa-building-o" aria-hidden="true"></i> En Empresa: <strong>' . $ucomp . '</strong></span>';
echo '<span class="ct-metric-chip ' . ($ugroup > 0 ? 'is-group' : '') . '"><i class="fa fa-users" aria-hidden="true"></i> En Grupo IOMAD: <strong>' . $ugroup . '</strong></span>';
echo '<span class="ct-metric-chip ' . ($audit['managers_count'] > 0 ? 'is-company' : '') . '"><i class="fa fa-id-badge" aria-hidden="true"></i> Gestores IOMAD: <strong>' . (int)$audit['managers_count'] . '</strong></span>';
echo '<span class="ct-metric-chip ' . ($audit['customcert_issued'] > 0 ? 'is-users' : '') . '"><i class="fa fa-certificate" aria-hidden="true"></i> Certificados Emitidos: <strong>' . (int)$audit['customcert_issued'] . '</strong></span>';
echo '<span class="ct-metric-chip">Actividades: <strong>' . (int)($sum['activities_consolidated'] ?? 0) . '</strong></span>';
echo '<span class="ct-metric-chip is-grades">Quiz: <strong>' . (int)($sum['quiz_attempts_moved'] ?? 0) . '</strong></span>';
echo '<span class="ct-metric-chip is-grades">Tareas: <strong>' . (int)($sum['assign_submissions_moved'] ?? 0) . '</strong></span>';
echo '<span class="ct-metric-chip is-grades">Notas: <strong>' . (int)($sum['grades_moved'] ?? 0) . '</strong></span>';
echo '<span class="ct-metric-chip is-grades">Completitud: <strong>' . (int)($sum['completions_moved'] ?? 0) . '</strong></span>';
echo '</div>';

// 3. Two-Column Audit Grid: Company Managers + Certificates (CustomCert & Workplace tool_certificate).
echo '<div class="it-drawer-audit-grid">';

// Left Subcard: IOMAD Company Managers.
echo '<div class="it-drawer-subcard">';
echo '<div class="it-drawer-subtitle"><i class="fa fa-user-secret" style="color:var(--it-accent);" aria-hidden="true"></i> Gestores de Empresa (IOMAD Company Managers)</div>';
if (!empty($audit['managers'])) {
    echo '<div style="font-size:0.78rem; color:var(--it-text-secondary); margin-bottom:0.45rem;">Matriculados con rol <strong>Client Course Access</strong> y acceso activo a los <strong>' . max(1, (int)$audit['company_groups_count']) . '</strong> grupo(s)/subgrupo(s) de la empresa en este Curso Maestro:</div>';
    echo '<div style="display:flex; flex-wrap:wrap; gap:0.35rem;">';
    foreach ($audit['managers'] as $mname) {
        echo '<span class="ct-metric-chip is-company"><i class="fa fa-check-circle" aria-hidden="true"></i> ' . s($mname) . '</span>';
    }
    echo '</div>';
} else {
    echo '<div style="font-size:0.78rem; color:var(--it-text-muted);">Esta empresa no tiene usuarios con rol <em>Company Manager</em> o <em>Educator</em> asignados actualmente en IOMAD.</div>';
}
echo '</div>';

// Right Subcard: CustomCert & Workplace Course Certificate Verification.
echo '<div class="it-drawer-subcard">';
echo '<div class="it-drawer-subtitle"><i class="fa fa-certificate" style="color:var(--it-emerald);" aria-hidden="true"></i> Verificación de Certificados (CustomCert &amp; Workplace tool_certificate)</div>';

if (!empty($audit['workplace_templates'])) {
    echo '<div style="margin-bottom:0.55rem; display:flex; flex-wrap:wrap; gap:0.4rem; align-items:center;">';
    foreach ($audit['workplace_templates'] as $wtpl) {
        if (!empty($wtpl['exists'])) {
            echo '<span class="ct-metric-chip is-users"><i class="fa fa-check-circle" aria-hidden="true"></i> Plantilla: <strong>' . s($wtpl['template_name']) . ' (ID #' . (int)$wtpl['template_id'] . ')</strong></span>';
        } else {
            echo '<span class="ct-metric-chip is-zero-warn"><i class="fa fa-exclamation-triangle" aria-hidden="true"></i> Plantilla ID #' . (int)$wtpl['template_id'] . ' no encontrada en destino</span>';
            echo '<a href="' . s($synctplurl->out(false)) . '" class="ct-btn-secondary" style="font-size:0.74rem; padding:0.2rem 0.6rem; color:var(--it-emerald)!important; border-color:var(--it-emerald-border)!important;"><i class="fa fa-cloud-download" aria-hidden="true"></i> Importar Plantilla desde Origen</a>';
        }
    }
    echo '</div>';
}

if (!empty($audit['customcert_items'])) {
    echo '<div style="font-size:0.78rem; color:var(--it-text-secondary); margin-bottom:0.45rem;">Se consolidaron <strong>' . count($audit['customcert_items']) . '</strong> certificado(s) emitido(s) en <strong>' . (int)$audit['customcert_instances'] . '</strong> actividad(es) de certificado:</div>';
    echo '<div style="display:flex; flex-direction:column; gap:0.35rem; max-height:220px; overflow-y:auto;">';
    foreach ($audit['customcert_items'] as $citem) {
        $code = trim((string)($citem['code'] ?? ''));
        $plabel = (string)($citem['pluginlabel'] ?? 'Certificado');
        $vurl = !empty($citem['verifyurl'])
            ? (string)$citem['verifyurl']
            : (new moodle_url('/admin/tool/certificate/index.php', ['code' => $code]))->out(false);
        $verifylink = '';
        if ($code !== '') {
            $verifylink = '<a href="' . s($vurl) . '" target="_blank" class="it-cert-code-badge" title="Verificar código oficial (' . s($plabel) . ')"><i class="fa fa-shield" aria-hidden="true"></i> Código: <strong>' . s($code) . '</strong> <i class="fa fa-external-link" aria-hidden="true"></i></a>';
        }
        echo '<div class="it-cert-row">';
        echo '<div><strong>' . s($citem['fullname']) . '</strong> <span style="color:var(--it-text-muted);">· ' . s($citem['certname']) . ' (' . s($plabel) . ')</span></div>';
        echo '<div>' . $verifylink . '</div>';
        echo '</div>';
    }
    echo '</div>';
} else if ((int)$audit['customcert_instances'] > 0) {
    $haswp = ((int)($audit['workplace_only_instances'] ?? 0) > 0);
    $verifyindexurl = $haswp
        ? new moodle_url('/admin/tool/certificate/index.php')
        : new moodle_url('/mod/customcert/verify_certificate.php');
    echo '<div style="font-size:0.78rem; color:var(--it-text-muted);">El Curso Maestro contiene <strong>' . (int)$audit['customcert_instances'] . '</strong> actividad(es) de certificado (' . ($haswp ? 'Workplace Course Certificate' : 'CustomCert') . '), pero los alumnos migrados aún no tienen emisiones registradas. <a href="' . s($verifyindexurl->out(false)) . '" target="_blank" style="color:var(--it-accent); font-weight:600;">Ir al verificador oficial <i class="fa fa-external-link" aria-hidden="true"></i></a></div>';
} else {
    echo '<div style="font-size:0.78rem; color:var(--it-text-muted);">Este curso no contiene actividades de tipo <code>mod_customcert</code> ni <code>mod_coursecertificate</code> (Workplace).</div>';
}
echo '</div>';

echo '</div>'; // End it-drawer-audit-grid.

// 4. Per-Student Detailed Audit Table.
echo '<div class="it-drawer-subcard" style="margin-top:0.85rem;">';
echo '<div class="it-drawer-subtitle"><i class="fa fa-graduation-cap" style="color:var(--it-accent);" aria-hidden="true"></i> Detalle de Estudiantes Consolidados (' . count($audit['students']) . ')</div>';
if (!empty($audit['students'])) {
    echo '<div style="overflow-x:auto;">';
    echo '<table class="it-audit-table">';
    echo '<thead><tr><th>Estudiante</th><th>Usuario / Email</th><th>Grupo IOMAD</th><th>Nota Curso</th><th>Actividades Completadas</th><th>Certificado (CustomCert / Workplace)</th></tr></thead>';
    echo '<tbody>';
    foreach ($audit['students'] as $stu) {
        $uprofile = new moodle_url('/user/view.php', ['id' => (int)$stu['userid'], 'course' => (int)$r->target_course_id]);
        $grpbadge = $stu['in_group']
            ? '<span class="ct-metric-chip is-users" style="font-size:0.7rem; padding:0.12rem 0.45rem;"><i class="fa fa-check" aria-hidden="true"></i> En Grupo IOMAD</span>'
            : '<span class="ct-metric-chip is-zero-warn" style="font-size:0.7rem; padding:0.12rem 0.45rem;">Pendiente</span>';
        $certhtml = '<span style="color:var(--it-text-subtle); font-size:0.75rem;">—</span>';
        if (!empty($stu['certificates'])) {
            $cparts = [];
            foreach ($stu['certificates'] as $sc) {
                $scode = trim((string)($sc['code'] ?? ''));
                $splabel = (string)($sc['pluginlabel'] ?? 'Certificado');
                if ($scode !== '') {
                    $svurl = !empty($sc['verifyurl'])
                        ? (string)$sc['verifyurl']
                        : (new moodle_url('/admin/tool/certificate/index.php', ['code' => $scode]))->out(false);
                    $cparts[] = '<a href="' . s($svurl) . '" target="_blank" class="it-cert-code-badge" title="Verificar en ' . s($splabel) . '"><i class="fa fa-certificate" aria-hidden="true"></i> ' . s($scode) . '</a>';
                }
            }
            if (!empty($cparts)) {
                $certhtml = implode(' ', $cparts);
            }
        }
        echo '<tr>';
        echo '<td><a href="' . s($uprofile->out(false)) . '" target="_blank" style="font-weight:600; color:var(--it-text-primary); text-decoration:none;">' . s($stu['fullname']) . '</a></td>';
        echo '<td style="font-family:monospace; font-size:0.74rem; color:var(--it-text-secondary);">' . s($stu['username']) . ' &middot; ' . s($stu['email']) . '</td>';
        echo '<td>' . $grpbadge . '</td>';
        echo '<td style="font-weight:700; color:var(--it-accent);">' . s($stu['final_grade']) . '</td>';
        echo '<td>' . (int)$stu['completions'] . ' completada(s)</td>';
        echo '<td>' . $certhtml . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
    echo '</div>';
} else {
    echo '<div style="font-size:0.8rem; color:var(--it-text-muted);">No hay estudiantes registrados en esta operación.</div>';
}
echo '</div>';

echo '</div>'; // End it-details-drawer.
echo '</article>';

echo ui_helper::render_footer_nav('logs');
echo '</div>'; // End ct-card.
echo '</div>'; // End local-iomadtransfer.
echo $OUTPUT->footer();
