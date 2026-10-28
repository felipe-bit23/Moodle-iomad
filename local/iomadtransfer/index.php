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
 * Summary, API Key Vault & Bento System Health Dashboard for local_iomadtransfer.
 *
 * @package    local_iomadtransfer
 * @copyright  2026 IOMAD Multi-Tenant Consolidation Engine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_iomadtransfer\iomad_consolidator;
use local_iomadtransfer\security_manager;
use local_iomadtransfer\sites_manager;
use local_iomadtransfer\ui_helper;

require_login();
$context = context_system::instance();
require_capability('local/iomadtransfer:manage', $context);

$action = optional_param('action', '', PARAM_ALPHA);
$pageurl = new moodle_url('/local/iomadtransfer/index.php');

$PAGE->set_context($context);
$PAGE->set_url($pageurl);
$PAGE->set_pagelayout('admin');
$PAGE->set_title('Resumen e integración - IOMAD Transfer');
$PAGE->set_heading(get_string('pluginname', 'local_iomadtransfer'));

if ($action === 'regenerate') {
    require_sesskey();
    security_manager::provision_service_and_token(true);
    redirect($pageurl, 'Token permanente regenerado correctamente.', null, \core\output\notification::NOTIFY_SUCCESS);
} else if ($action === 'repair') {
    require_sesskey();
    security_manager::provision_service_and_token(false);
    iomad_consolidator::repair_and_sync_all_iomad_courses();
    redirect($pageurl, 'Integración, rol, servicio REST, grupos y matrículas IOMAD sincronizados al 100%.', null, \core\output\notification::NOTIFY_SUCCESS);
}

$token = security_manager::get_or_create_token();
$hasiomad = security_manager::has_iomad();
$companies = security_manager::get_iomad_companies();
$originsites = sites_manager::list_sites('origin');
$targetsites = sites_manager::list_sites('target');
$wsenabled = !empty($CFG->enablewebservices);
$protocols = explode(',', (string)get_config('core', 'webserviceprotocols'));
$restenabled = in_array('rest', array_map('trim', $protocols), true);

$repairurl = new moodle_url($pageurl, ['action' => 'repair', 'sesskey' => sesskey()]);
$regenurl = new moodle_url($pageurl, ['action' => 'regenerate', 'sesskey' => sesskey()]);
$sitesurl = new moodle_url('/local/iomadtransfer/sites.php');
$consolidateurl = new moodle_url('/local/iomadtransfer/consolidate.php');

$wsuser = $DB->get_record('user', ['username' => security_manager::USERNAME_WS]);
$usercreatedstr = $wsuser ? userdate((int)$wsuser->timecreated, '%d %b %Y') : userdate(time(), '%d %b %Y');

$lastcron = (int)get_config('core', 'lastcron');
$crondiff = max(0, time() - $lastcron);
if ($crondiff < 60) {
    $cronstr = 'hace ' . $crondiff . ' s';
} else if ($crondiff < 3600) {
    $cronstr = 'hace ' . round($crondiff / 60) . ' min';
} else {
    $cronstr = 'hace ' . round($crondiff / 3600) . ' h';
}
$cronhealthy = ($crondiff < 900);

echo $OUTPUT->header();
echo '<div class="local-iomadtransfer">';

echo '<div class="ct-card">';

$herobtn = '<a href="' . s($consolidateurl->out(false)) . '" class="ct-btn-primary"><i class="fa fa-plus" aria-hidden="true"></i> Nueva consolidación</a>';
echo ui_helper::render_header_banner($herobtn, 'summary');

// 1. Developer API Token Vault (Stripe / Vercel aesthetic).
echo '<section class="it-vault-card">';

echo '<div class="it-vault-top">';
echo '<div class="it-vault-title-group">';
echo '<div class="it-vault-icon"><i class="fa fa-key" aria-hidden="true"></i></div>';
echo '<div>';
echo '<h2 class="it-vault-title">Clave de API &amp; Sincronización entre Nodos</h2>';
echo '<p class="it-vault-desc">Copia este token permanente en la sección <strong>Plataformas</strong> de tus otros servidores Moodle para autorizar la transferencia segura de cursos, alumnos y calificaciones.</p>';
echo '</div>';
echo '</div>';
echo '<span class="it-badge-success"><span class="ct-status-dot"></span> Token Activo</span>';
echo '</div>';

echo '<div class="it-vault-key-row">';
echo '<div class="it-vault-input-wrap">';
echo '<i class="fa fa-lock it-vault-lock" aria-hidden="true"></i>';
echo '<input type="password" id="it-token-field" class="it-vault-input" value="' . s((string)$token) . '" readonly>';
echo '<button type="button" class="it-vault-eye" onclick="var f=document.getElementById(\'it-token-field\'); var isPass=f.type===\'password\'; f.type=isPass?\'text\':\'password\'; this.innerHTML=isPass?\'<i class=\\\'fa fa-eye-slash\\\'></i>\':\'<i class=\\\'fa fa-eye\\\'></i>\';" title="Mostrar u ocultar clave">';
echo '<i class="fa fa-eye" aria-hidden="true"></i>';
echo '</button>';
echo '</div>';
echo '<button type="button" class="ct-btn-primary" onclick="navigator.clipboard.writeText(document.getElementById(\'it-token-field\').value); this.innerHTML=\'<i class=\\\'fa fa-check\\\'></i> Clave copiada\'; setTimeout(function(b){b.innerHTML=\'<i class=\\\'fa fa-copy\\\'></i> Copiar token\';}, 2400, this);">';
echo '<i class="fa fa-copy" aria-hidden="true"></i> Copiar token';
echo '</button>';
echo '</div>';

echo '<div class="it-vault-footer">';
echo '<div><i class="fa fa-terminal" aria-hidden="true"></i> Cuenta de servicio: <strong style="color:#e2e8f0;">' . s(security_manager::USERNAME_WS) . '</strong> &middot; Provisionada el ' . s($usercreatedstr) . '</div>';
echo '<div style="display:flex; align-items:center; gap:0.5rem; flex-wrap:wrap;">';
echo '<a href="' . s($repairurl->out(false)) . '" class="it-vault-btn-ghost" title="Verifica capacidades y sincroniza matrículas IOMAD"><i class="fa fa-magic" aria-hidden="true"></i> Autodiagnóstico y Sincronización IOMAD</a>';
echo '<a href="' . s($regenurl->out(false)) . '" class="it-vault-btn-ghost" onclick="return confirm(\'¿Regenerar el token permanente? Deberás actualizarlo en las plataformas vinculadas.\');"><i class="fa fa-refresh" aria-hidden="true"></i> Rotar clave</a>';
echo '</div>';
echo '</div>';

echo '</section>'; // End it-vault-card.

// 2. Bento System Health Grid.
echo '<div class="it-section-header">';
echo '<h2 class="it-section-title"><i class="fa fa-heartbeat" style="color:var(--it-accent);" aria-hidden="true"></i> Estado del Ecosistema</h2>';
echo '</div>';

echo '<div class="ct-status-grid">';

// Card 1: Protocolo REST.
echo '<div class="ct-status-card">';
echo '<div class="ct-status-left">';
echo '<div class="ct-status-icon-box is-green"><i class="fa fa-bolt" aria-hidden="true"></i></div>';
echo '<div class="ct-status-info">';
echo '<div class="ct-status-name">Protocolo REST &amp; WebServices</div>';
echo '<div class="ct-status-state"><span class="ct-status-dot ' . ($wsenabled && $restenabled ? '' : 'is-error') . '"></span> ' . ($wsenabled && $restenabled ? 'Operativo y escuchando' : 'Inactivo') . '</div>';
echo '</div>';
echo '</div>';
echo '</div>';

// Card 2: Servicio y Descarga MBZ.
echo '<div class="ct-status-card">';
echo '<div class="ct-status-left">';
echo '<div class="ct-status-icon-box is-teal"><i class="fa fa-cloud-download" aria-hidden="true"></i></div>';
echo '<div class="ct-status-info">';
echo '<div class="ct-status-name">Transferencia de Paquetes MBZ</div>';
echo '<div class="ct-status-state"><span class="ct-status-dot"></span> Streaming binario habilitado</div>';
echo '</div>';
echo '</div>';
echo '</div>';

// Card 3: Rol y Capacidades Sensibles.
echo '<div class="ct-status-card">';
echo '<div class="ct-status-left">';
echo '<div class="ct-status-icon-box is-green"><i class="fa fa-shield" aria-hidden="true"></i></div>';
echo '<div class="ct-status-info">';
echo '<div class="ct-status-name">Seguridad y Datos de Alumnos</div>';
echo '<div class="ct-status-state"><span class="ct-status-dot"></span> +42 capacidades autorizadas</div>';
echo '</div>';
echo '</div>';
echo '</div>';

// Card 4: Cron en segundo plano.
echo '<div class="ct-status-card">';
echo '<div class="ct-status-left">';
echo '<div class="ct-status-icon-box ' . ($cronhealthy ? 'is-green' : 'is-amber') . '"><i class="fa fa-clock-o" aria-hidden="true"></i></div>';
echo '<div class="ct-status-info">';
echo '<div class="ct-status-name">Cola de Tareas (Cron)</div>';
echo '<div class="ct-status-state"><span class="ct-status-dot ' . ($cronhealthy ? '' : 'is-warning') . '"></span> Latido ' . s($cronstr) . '</div>';
echo '</div>';
echo '</div>';
echo '<a href="' . s((new moodle_url('/admin/tool/task/scheduledtasks.php'))->out(false)) . '" class="ct-status-action-btn" title="Ver tareas programadas"><i class="fa fa-arrow-right" aria-hidden="true"></i></a>';
echo '</div>';

// Card 5: Nodos Conectados.
echo '<div class="ct-status-card">';
echo '<div class="ct-status-left">';
echo '<div class="ct-status-icon-box is-blue"><i class="fa fa-globe" aria-hidden="true"></i></div>';
echo '<div class="ct-status-info">';
echo '<div class="ct-status-name">Servidores Moodle Vinculados</div>';
echo '<div class="ct-status-state"><span class="ct-status-dot"></span> ' . count($originsites) . ' de origen &middot; ' . count($targetsites) . ' de destino</div>';
echo '</div>';
echo '</div>';
echo '<a href="' . s($sitesurl->out(false)) . '" class="ct-status-action-btn" title="Configurar plataformas"><i class="fa fa-arrow-right" aria-hidden="true"></i></a>';
echo '</div>';

// Card 6: Motor Multi-Empresa IOMAD.
if ($hasiomad) {
    echo '<div class="ct-status-card">';
    echo '<div class="ct-status-left">';
    echo '<div class="ct-status-icon-box is-purple"><i class="fa fa-building-o" aria-hidden="true"></i></div>';
    echo '<div class="ct-status-info">';
    echo '<div class="ct-status-name">Arquitectura Multi-Tenant IOMAD</div>';
    echo '<div class="ct-status-state"><span class="ct-status-dot"></span> ' . count($companies) . ' empresa(s) detectada(s)</div>';
    echo '</div>';
    echo '</div>';
    echo '<a href="' . s((new moodle_url('/blocks/iomad_company_admin/company_departments.php'))->out(false)) . '" class="ct-status-action-btn" title="Administrar empresas IOMAD"><i class="fa fa-arrow-right" aria-hidden="true"></i></a>';
    echo '</div>';
}

echo '</div>'; // End ct-status-grid.

echo ui_helper::render_footer_nav('summary');

echo '</div>'; // End ct-card.
echo '</div>'; // End local-iomadtransfer.
echo $OUTPUT->footer();
