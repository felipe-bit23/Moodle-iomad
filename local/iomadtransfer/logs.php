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
 * High-Performance Deployment & Consolidation Activity Stream for local_iomadtransfer.
 * Renders ultra-fast compact cards with zero per-student SQL queries on initial load;
 * clicking "Ver más detalles" navigates to log_detail.php?id={ID} on demand.
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

$action = optional_param('action', '', PARAM_ALPHA);
$requestid = optional_param('requestid', 0, PARAM_INT);
$pageurl = new moodle_url('/local/iomadtransfer/logs.php');
$PAGE->set_context($context);
$PAGE->set_url($pageurl);
$PAGE->set_pagelayout('admin');
$PAGE->set_title('Actividad y Auditoría - IOMAD Transfer');
$PAGE->set_heading(get_string('pluginname', 'local_iomadtransfer'));

if ($action === 'synciomad' || $action === 'syncrequest') {
    require_sesskey();
    $targetreqid = ($action === 'syncrequest' && $requestid > 0) ? $requestid : 0;

    // Additively synchronize migrated students, official company groups, Company Managers (Client Course Access
    // across all subgroups of their company), and CustomCert / Workplace certificates within the requested scope.
    $stats = iomad_consolidator::repair_and_sync_all_iomad_courses(0, 0, $targetreqid);

    $msg = 'Sincronización IOMAD completada: '
        . (int)$stats['courses_synced'] . ' curso(s) verificado(s), '
        . (int)$stats['groups_verified'] . ' grupo(s) oficial(es) IOMAD sincronizado(s), '
        . (int)$stats['users_synced'] . ' estudiante(s), '
        . (int)($stats['managers_synced'] ?? 0) . ' gestor(es) con rol Client Course Access y '
        . (int)($stats['certificates_verified'] ?? 0) . ' certificado(s) verificado(s).';
    redirect($pageurl, $msg, null, \core\output\notification::NOTIFY_SUCCESS);
}

$companies = security_manager::get_iomad_companies();
$requests = $DB->get_records(request_manager::TABLE, null, 'id DESC', '*', 0, 200);

$kpitotal = count($requests);
$kpicompleted = 0;
$kpiactive = 0;
$kpiusers = 0;
$kpigroups = 0;

foreach ($requests as $r) {
    $st = (int)$r->status;
    if ($st === request_manager::STATUS_COMPLETED) {
        $kpicompleted++;
    } else if ($st !== request_manager::STATUS_ERROR) {
        $kpiactive++;
    }
    if (!empty($r->consolidate_summary)) {
        $sum = json_decode($r->consolidate_summary, true);
        if (is_array($sum)) {
            $kpiusers += (int)($sum['users_processed'] ?? 0);
            $kpigroups += (int)($sum['users_added_to_iomad_group'] ?? 0);
        }
    }
}

echo $OUTPUT->header();
echo '<div class="local-iomadtransfer">';

echo '<div class="ct-card">';

$topactions = '<div style="display:flex; gap:0.5rem; flex-wrap:wrap;">';
if (security_manager::has_iomad()) {
    $syncurl = new moodle_url('/local/iomadtransfer/logs.php', ['action' => 'synciomad', 'sesskey' => sesskey()]);
    $topactions .= '<a href="' . s($syncurl->out(false)) . '" class="ct-btn-secondary" title="Sincroniza alumnos, Company Managers (Client Course Access) y certificados en IOMAD"><i class="fa fa-magic" style="color:var(--it-accent);" aria-hidden="true"></i> Sincronizar IOMAD</a>';
}
$topactions .= '<a href="' . s($pageurl->out(false)) . '" class="ct-btn-secondary"><i class="fa fa-refresh" aria-hidden="true"></i></a>';
$topactions .= '<a href="' . s((new moodle_url('/local/iomadtransfer/consolidate.php'))->out(false)) . '" class="ct-btn-primary"><i class="fa fa-plus" aria-hidden="true"></i> Nueva consolidación</a>';
$topactions .= '</div>';

echo ui_helper::render_header_banner($topactions, 'logs');

// 1. Minimalist SaaS KPI Ribbon.
echo '<div class="ct-kpi-grid">';

echo '<div class="ct-kpi-card">';
echo '<div class="ct-kpi-icon is-indigo"><i class="fa fa-bolt" aria-hidden="true"></i></div>';
echo '<div><div class="ct-kpi-value">' . $kpitotal . '</div><div class="ct-kpi-label">Operaciones</div></div>';
echo '</div>';

echo '<div class="ct-kpi-card">';
echo '<div class="ct-kpi-icon is-green"><i class="fa fa-check" aria-hidden="true"></i></div>';
echo '<div><div class="ct-kpi-value">' . $kpicompleted . '</div><div class="ct-kpi-label">Completadas</div></div>';
echo '</div>';

echo '<div class="ct-kpi-card">';
echo '<div class="ct-kpi-icon is-amber"><i class="fa fa-circle-o-notch" aria-hidden="true"></i></div>';
echo '<div><div class="ct-kpi-value">' . $kpiactive . '</div><div class="ct-kpi-label">En Ejecución</div></div>';
echo '</div>';

echo '<div class="ct-kpi-card">';
echo '<div class="ct-kpi-icon is-blue"><i class="fa fa-user-plus" aria-hidden="true"></i></div>';
echo '<div><div class="ct-kpi-value">' . $kpiusers . '</div><div class="ct-kpi-label">Alumnos Importados</div></div>';
echo '</div>';

echo '<div class="ct-kpi-card">';
echo '<div class="ct-kpi-icon is-magenta"><i class="fa fa-users" aria-hidden="true"></i></div>';
echo '<div><div class="ct-kpi-value">' . $kpigroups . '</div><div class="ct-kpi-label">En Grupos IOMAD</div></div>';
echo '</div>';

echo '</div>'; // End ct-kpi-grid.

if ($kpiactive > 0) {
    echo '<div class="ct-callout-info">';
    echo '<i class="fa fa-refresh fa-spin" aria-hidden="true"></i>';
    echo '<div><strong>' . $kpiactive . ' operación(es) en curso.</strong> El progreso se actualiza automáticamente cada 15 segundos.</div>';
    echo '</div>';
    echo '<script>setTimeout(function(){ window.location.reload(); }, 15000);</script>';
}

// 2. Command Filter Bar.
echo '<div class="ct-filter-bar">';
echo '<input type="text" id="it-log-search" class="ct-filter-input" placeholder="Buscar por #ID, nombre de curso, dominio o empresa..." oninput="filterStreamCards();">';
echo '<select id="it-log-status" class="ct-filter-select" onchange="filterStreamCards();">';
echo '<option value="">Todos los estados</option>';
echo '<option value="100">Completado</option>';
echo '<option value="active">En ejecución</option>';
echo '<option value="0">Con error</option>';
echo '</select>';
if (!empty($companies)) {
    echo '<select id="it-log-company" class="ct-filter-select" onchange="filterStreamCards();">';
    echo '<option value="">Todas las empresas IOMAD</option>';
    foreach ($companies as $comp) {
        echo '<option value="' . (int)$comp->id . '">' . s($comp->name) . '</option>';
    }
    echo '</select>';
}
echo '</div>';

// 3. Lightweight Stream Cards (Zero heavy per-card SQL queries on load!).
if (empty($requests)) {
    echo '<div class="it-studio-surface" style="text-align:center; padding:3.5rem 1.5rem;">';
    echo '<div style="width:52px; height:52px; border-radius:14px; background:var(--it-accent-soft); color:var(--it-accent); display:inline-flex; align-items:center; justify-content:center; font-size:1.35rem; margin-bottom:0.85rem;"><i class="fa fa-bolt" aria-hidden="true"></i></div>';
    echo '<h4 style="font-size:1rem; font-weight:700; color:var(--it-text-primary); margin-bottom:0.25rem;">Sin operaciones registradas</h4>';
    echo '<p style="font-size:0.85rem; color:var(--it-text-muted); margin:0;">Cuando lances una consolidación desde el asistente, verás aquí su flujo y métricas en tiempo real.</p>';
    echo '</div>';
} else {
    echo '<div class="it-stream-list" id="it-stream-container">';
    foreach ($requests as $r) {
        $reqid = (int)$r->id;
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

        $actionbtns = [];
        if ((int)$r->direction === request_manager::DIRECTION_TARGET) {
            if ($st === request_manager::STATUS_COMPLETED && (int)$r->companyid > 0 && security_manager::has_iomad()) {
                $rowSyncUrl = new moodle_url('/local/iomadtransfer/logs.php', [
                    'action' => 'syncrequest',
                    'requestid' => $reqid,
                    'sesskey' => sesskey(),
                ]);
                $actionbtns[] = '<a href="' . s($rowSyncUrl->out(false)) . '" class="ct-btn-secondary" style="padding:0.32rem 0.75rem; font-size:0.78rem; color:var(--it-accent)!important; border-color:var(--it-accent-border)!important;" title="Sincronizar alumnos, Company Managers (Client Course Access) y certificados de esta solicitud"><i class="fa fa-users" aria-hidden="true"></i> Sincronizar</a>';
            }
            if ($st === request_manager::STATUS_ERROR || ($st === request_manager::STATUS_COMPLETED && $usersproc === 0)) {
                $retryurl = new moodle_url('/local/iomadtransfer/retry.php', ['id' => $reqid, 'sesskey' => sesskey()]);
                $actionbtns[] = '<a href="' . s($retryurl->out(false)) . '" class="ct-btn-secondary" style="padding:0.32rem 0.75rem; font-size:0.78rem; color:var(--it-amber)!important; border-color:var(--it-amber-border)!important;" title="Relanzar restauración e importación de alumnos"><i class="fa fa-repeat" aria-hidden="true"></i> Reintentar</a>';
            }
        }

        // Direct link to on-demand detail page log_detail.php?id={ID}
        $detailurl = new moodle_url('/local/iomadtransfer/log_detail.php', ['id' => $reqid]);
        $actionbtns[] = '<a href="' . s($detailurl->out(false)) . '" class="ct-btn-secondary" style="padding:0.32rem 0.75rem; font-size:0.78rem;" title="Abrir auditoría detallada de estudiantes, gestores y certificados de la solicitud #' . $reqid . '">'
            . '<i class="fa fa-eye" aria-hidden="true"></i> Ver más detalles'
            . '</a>';

        $statuscat = ($st === request_manager::STATUS_COMPLETED) ? '100' : (($st === request_manager::STATUS_ERROR) ? '0' : 'active');

        echo '<article class="it-stream-card" data-status="' . $statuscat . '" data-company="' . (int)$r->companyid . '">';

        // Top Row: ID + Status + Host + Timestamp + Action Buttons.
        echo '<div class="it-stream-top">';
        echo '<div class="it-stream-id-group">';
        echo '<span class="it-stream-id">#' . $reqid . '</span>';
        echo '<span class="' . s($badge['class']) . '">' . s($badge['label']) . '</span>';
        echo '<span class="ct-direction-badge">' . s($dirlabel) . '</span>';
        echo '<span class="it-node-host">' . s($r->siteurl) . '</span>';
        if ($compbadges !== '') {
            echo $compbadges;
        }
        echo '</div>';

        echo '<div style="display:flex; align-items:center; gap:0.65rem; flex-wrap:wrap;">';
        echo '<span style="font-size:0.76rem; color:var(--it-text-subtle); font-variant-numeric:tabular-nums;">' . userdate((int)$r->timemodified, '%d %b %Y, %H:%M:%S') . '</span>';
        echo '<div style="display:flex; gap:0.4rem; flex-wrap:wrap;">' . implode('', $actionbtns) . '</div>';
        echo '</div>';
        echo '</div>'; // End it-stream-top.

        // Pipeline Row: Origin Course -> Target Master Course + Progress Bar.
        echo '<div class="it-stream-pipeline" style="margin-bottom:0;">';
        echo '<div class="it-stream-route">';
        echo '<div>';
        echo '<div style="font-size:0.88rem; font-weight:700; color:var(--it-text-primary);">' . s($origtitle) . '</div>';
        echo '<div style="font-size:0.74rem; color:var(--it-text-muted); font-family:monospace;">' . s($origsub) . '</div>';
        echo '</div>';
        echo '<i class="fa fa-long-arrow-right it-stream-arrow" aria-hidden="true"></i>';
        echo '<div>' . $targetlink . '</div>';
        echo '</div>';

        echo '<div style="min-width:190px; text-align:right;">';
        echo '<div style="font-size:0.75rem; color:var(--it-text-secondary); font-variant-numeric:tabular-nums;">' . $dlmb . ($totmb > 0 ? ' / ' . $totmb : '') . ' MB &middot; <strong>' . $pct . '%</strong></div>';
        echo '<div style="background:#e2e8f0; border-radius:9999px; height:5px; overflow:hidden; margin-top:4px;"><div style="background:' . $barcolor . '; height:100%; border-radius:9999px; width:' . $pct . '%; transition:width 0.4s ease;"></div></div>';
        echo '</div>';
        echo '</div>'; // End it-stream-pipeline.

        if (!empty($r->error_message)) {
            echo '<div style="font-size:0.8rem; color:var(--it-rose); margin-top:0.55rem;"><i class="fa fa-exclamation-triangle" aria-hidden="true"></i> <strong>' . s((string)$r->error_code) . ':</strong> ' . s((string)$r->error_message) . '</div>';
        }

        echo '</article>';
    }
    echo '</div>'; // End it-stream-list.
}

echo '<script>
function filterStreamCards() {
    var q = (document.getElementById("it-log-search") ? document.getElementById("it-log-search").value : "").toLowerCase();
    var st = document.getElementById("it-log-status") ? document.getElementById("it-log-status").value : "";
    var comp = document.getElementById("it-log-company") ? document.getElementById("it-log-company").value : "";
    var cards = document.querySelectorAll("#it-stream-container .it-stream-card");
    cards.forEach(function(c) {
        var matchText = !q || c.textContent.toLowerCase().indexOf(q) !== -1;
        var matchSt = !st || c.getAttribute("data-status") === st;
        var matchComp = !comp || c.getAttribute("data-company") === comp;
        c.style.display = (matchText && matchSt && matchComp) ? "" : "none";
    });
}
</script>';

echo ui_helper::render_footer_nav('logs');

echo '</div>'; // End ct-card.
echo '</div>'; // End local-iomadtransfer.
echo $OUTPUT->footer();
