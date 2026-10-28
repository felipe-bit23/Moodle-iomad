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
 * Connected Moodle Nodes Studio & Diagnostics for local_iomadtransfer.
 *
 * @package    local_iomadtransfer
 * @copyright  2026 IOMAD Multi-Tenant Consolidation Engine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_iomadtransfer\security_manager;
use local_iomadtransfer\sites_manager;
use local_iomadtransfer\ui_helper;

require_login();
$context = context_system::instance();
require_capability('local/iomadtransfer:manage', $context);

$action = optional_param('action', '', PARAM_ALPHA);
$type = optional_param('type', 'origin', PARAM_ALPHA);
$id = optional_param('id', 0, PARAM_INT);
$showadd = optional_param('showadd', 0, PARAM_INT);

$pageurl = new moodle_url('/local/iomadtransfer/sites.php');
$PAGE->set_context($context);
$PAGE->set_url($pageurl);
$PAGE->set_pagelayout('admin');
$PAGE->set_title('Plataformas conectadas - IOMAD Transfer');
$PAGE->set_heading(get_string('pluginname', 'local_iomadtransfer'));

$testreports = [];
$editsite = null;

if ($action === 'save' && data_submitted()) {
    require_sesskey();
    $name = required_param('name', PARAM_TEXT);
    $host = required_param('host', PARAM_RAW_TRIMMED);
    $token = required_param('token', PARAM_RAW_TRIMMED);
    $defaultcompanyid = optional_param('default_companyid', 0, PARAM_INT);
    $relorigin = optional_param('rel_origin', 0, PARAM_INT);
    $reltarget = optional_param('rel_target', 0, PARAM_INT);

    $primarytype = ($relorigin || !$reltarget) ? 'origin' : 'target';
    $savedid = sites_manager::save_site($primarytype, $name, $host, $token, $defaultcompanyid, $id);

    if ($relorigin && $reltarget) {
        sites_manager::save_site('target', $name, $host, $token, 0);
    }

    try {
        \cache::make('local_iomadtransfer', 'remote_courses')->purge();
    } catch (\Throwable $e) {
        // Ignore if cache not initialized yet.
    }

    $test = sites_manager::test_site_connection($primarytype, $savedid);
    $msg = $test['success']
        ? 'Nodo "' . s($name) . '" guardado y verificado (' . $test['latency_ms'] . ' ms).'
        : 'Nodo "' . s($name) . '" guardado, pero la verificación respondió: ' . $test['message'];
    $ntype = $test['success'] ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_WARNING;
    redirect($pageurl, $msg, null, $ntype);
} else if ($action === 'delete' && $id > 0) {
    require_sesskey();
    sites_manager::delete_site($type, $id);
    redirect($pageurl, 'Plataforma eliminada.', null, \core\output\notification::NOTIFY_SUCCESS);
} else if ($action === 'test' && $id > 0) {
    require_sesskey();
    $testreports[] = sites_manager::test_site_connection($type, $id);
} else if ($action === 'testall') {
    require_sesskey();
    $testreports = sites_manager::test_all_connections('origin');
} else if ($action === 'edit' && $id > 0) {
    $editsite = sites_manager::get_site($type, $id);
    $showadd = 1;
}

$companies = security_manager::get_iomad_companies();
$originsites = sites_manager::list_sites('origin');
$targetsites = sites_manager::list_sites('target');

$unifiedsites = [];
foreach ($originsites as $s) {
    $nhost = sites_manager::normalize_host($s->host);
    $unifiedsites[$nhost] = [
        'id' => (int)$s->id,
        'name' => (string)$s->name,
        'host' => (string)$s->host,
        'is_origin' => true,
        'is_target' => false,
        'origin_id' => (int)$s->id,
        'target_id' => 0,
        'companyid' => (int)($s->default_companyid ?? 0),
        'lasttest' => $s->lasttest,
        'lastteststatus' => $s->lastteststatus,
        'lasttesterror' => $s->lasttesterror,
    ];
}
foreach ($targetsites as $s) {
    $nhost = sites_manager::normalize_host($s->host);
    if (isset($unifiedsites[$nhost])) {
        $unifiedsites[$nhost]['is_target'] = true;
        $unifiedsites[$nhost]['target_id'] = (int)$s->id;
    } else {
        $unifiedsites[$nhost] = [
            'id' => (int)$s->id,
            'name' => (string)$s->name,
            'host' => (string)$s->host,
            'is_origin' => false,
            'is_target' => true,
            'origin_id' => 0,
            'target_id' => (int)$s->id,
            'companyid' => 0,
            'lasttest' => $s->lasttest,
            'lastteststatus' => $s->lastteststatus,
            'lasttesterror' => $s->lasttesterror,
        ];
    }
}

echo $OUTPUT->header();
echo '<div class="local-iomadtransfer">';

echo '<div class="ct-card">';

$hdrbtns = '<div style="display:flex; gap:0.5rem; flex-wrap:wrap;">';
if (!empty($originsites)) {
    $testallurl = new moodle_url($pageurl, ['action' => 'testall', 'sesskey' => sesskey()]);
    $hdrbtns .= '<a href="' . s($testallurl->out(false)) . '" class="ct-btn-secondary"><i class="fa fa-bolt" aria-hidden="true"></i> Probar todas</a>';
}
$hdrbtns .= '<a href="' . s((new moodle_url('/local/iomadtransfer/sites.php', ['showadd' => 1]))->out(false)) . '" class="ct-btn-primary" onclick="var p=document.getElementById(\'ct-add-wizard\'); if(p){p.style.display=\'block\'; p.scrollIntoView({behavior:\'smooth\'}); return false;}">';
$hdrbtns .= '<i class="fa fa-plus" aria-hidden="true"></i> Conectar servidor';
$hdrbtns .= '</a>';
$hdrbtns .= '</div>';

echo ui_helper::render_header_banner($hdrbtns, 'sites');

// Connection Test Result Banner if triggered.
if (!empty($testreports)) {
    foreach ($testreports as $rep) {
        $isok = !empty($rep['success']);
        echo '<div class="ct-callout-info" style="border-left-color:' . ($isok ? 'var(--it-emerald)' : 'var(--it-rose)') . ';">';
        echo '<i class="fa ' . ($isok ? 'fa-check-circle' : 'fa-exclamation-circle') . '" style="color:' . ($isok ? 'var(--it-emerald)' : 'var(--it-rose)') . ';" aria-hidden="true"></i>';
        echo '<div>';
        if ($isok) {
            echo '<strong>' . s($rep['site_name']) . '</strong> (<code>' . s($rep['site_host']) . '</code>) conectado &middot; ';
            echo 'Moodle ' . s($rep['remote_release']) . ' &middot; <strong>' . (int)$rep['total_courses'] . ' cursos</strong> &middot; ' . (int)$rep['latency_ms'] . ' ms';
        } else {
            echo '<strong>No se pudo conectar con "' . s($rep['site_name']) . '"</strong>: ' . s($rep['message']);
        }
        echo '</div>';
        echo '</div>';
    }
}

// Slide-Down Node Configuration Studio.
$wzdisplay = ($showadd || $editsite) ? 'block' : 'none';
$editid = $editsite ? (int)$editsite->id : 0;
$editname = $editsite ? (string)$editsite->name : '';
$edithost = $editsite ? (string)$editsite->host : '';
$edittoken = $editsite ? (string)$editsite->token : '';
$editcompid = ($editsite && isset($editsite->default_companyid)) ? (int)$editsite->default_companyid : 0;
$iseditorigin = ($type === 'origin' || empty($editsite));
$isedittarget = ($type === 'target');

echo '<div id="ct-add-wizard" class="it-studio-surface" style="display:' . $wzdisplay . '; margin-bottom:1.5rem; border-color:var(--it-accent-border);">';

echo '<div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:1.25rem; flex-wrap:wrap; gap:0.75rem;">';
echo '<div>';
echo '<h3 style="font-size:1.1rem; font-weight:700; margin:0; color:var(--it-text-primary);">' . ($editid > 0 ? 'Editar Servidor #' . $editid : 'Conectar Nuevo Servidor Moodle') . '</h3>';
echo '<p style="font-size:0.82rem; color:var(--it-text-muted); margin:0.15rem 0 0 0;">Configura la dirección URL, el token permanente y la empresa IOMAD predeterminada.</p>';
echo '</div>';
echo '<a href="' . s($pageurl->out(false)) . '" class="ct-btn-secondary" style="padding:0.35rem 0.8rem; font-size:0.8rem;">Cerrar</a>';
echo '</div>';

echo '<form method="post" action="' . s($pageurl->out(false)) . '" id="ct-platform-form">';
echo '<input type="hidden" name="sesskey" value="' . s(sesskey()) . '">';
echo '<input type="hidden" name="action" value="save">';
echo '<input type="hidden" name="id" value="' . $editid . '">';

// Role Cards in 2-Column Grid.
echo '<div class="it-mode-grid" style="margin-bottom:1.25rem;">';

echo '<label class="ct-option-card ' . ($iseditorigin ? 'is-selected' : '') . '" id="opt-card-origin" onclick="var cb=document.getElementById(\'cb_rel_origin\'); cb.checked=!cb.checked; this.classList.toggle(\'is-selected\', cb.checked); event.preventDefault();">';
echo '<input type="checkbox" name="rel_origin" id="cb_rel_origin" value="1" ' . ($iseditorigin ? 'checked="checked"' : '') . ' onclick="event.stopPropagation(); document.getElementById(\'opt-card-origin\').classList.toggle(\'is-selected\', this.checked);">';
echo '<div>';
echo '<div class="ct-option-title"><i class="fa fa-cloud-download" style="color:var(--it-accent);" aria-hidden="true"></i> Nodo de Origen (Traer cursos)</div>';
echo '<div class="ct-option-desc">Importa y consolida cursos, alumnos, notas e intentos desde este Moodle hacia tu Curso Maestro IOMAD.</div>';
echo '</div>';
echo '</label>';

echo '<label class="ct-option-card ' . ($isedittarget ? 'is-selected' : '') . '" id="opt-card-target" onclick="var cb=document.getElementById(\'cb_rel_target\'); cb.checked=!cb.checked; this.classList.toggle(\'is-selected\', cb.checked); event.preventDefault();">';
echo '<input type="checkbox" name="rel_target" id="cb_rel_target" value="1" ' . ($isedittarget ? 'checked="checked"' : '') . ' onclick="event.stopPropagation(); document.getElementById(\'opt-card-target\').classList.toggle(\'is-selected\', this.checked);">';
echo '<div>';
echo '<div class="ct-option-title"><i class="fa fa-cloud-upload" style="color:var(--it-emerald);" aria-hidden="true"></i> Nodo de Destino (Permitir envíos)</div>';
echo '<div class="ct-option-desc">Autoriza a esa plataforma remota a solicitar copias de seguridad de cursos alojados en este servidor.</div>';
echo '</div>';
echo '</label>';

echo '</div>';

// Connection Inputs in Clean 2x2 Grid.
echo '<div class="row">';
echo '<div class="col-md-6 mb-3">';
echo '<label style="font-size:0.78rem; font-weight:700; color:var(--it-text-secondary); margin-bottom:0.35rem; display:block;">Nombre de la Plataforma</label>';
echo '<input type="text" name="name" class="ct-token-input" style="width:100%;" value="' . s($editname) . '" placeholder="Ej. Educa.coop Regional" required>';
echo '</div>';

echo '<div class="col-md-6 mb-3">';
echo '<label style="font-size:0.78rem; font-weight:700; color:var(--it-text-secondary); margin-bottom:0.35rem; display:block;">URL del Servidor Moodle</label>';
echo '<input type="url" name="host" class="ct-token-input" style="width:100%;" value="' . s($edithost) . '" placeholder="https://prueba.educa.coop" required>';
echo '</div>';

echo '<div class="col-md-6 mb-3">';
echo '<label style="font-size:0.78rem; font-weight:700; color:var(--it-text-secondary); margin-bottom:0.35rem; display:block;">Token de API (obtenido en ese Moodle)</label>';
echo '<input type="text" name="token" class="ct-token-input" style="width:100%; font-family:monospace;" value="' . s($edittoken) . '" placeholder="Pegar clave de 32 caracteres..." required>';
echo '</div>';

echo '<div class="col-md-6 mb-3">';
echo '<label style="font-size:0.78rem; font-weight:700; color:var(--it-text-secondary); margin-bottom:0.35rem; display:block;">Empresa IOMAD Predeterminada</label>';
echo '<select name="default_companyid" class="ct-filter-select" style="width:100%;">';
echo '<option value="0">-- Elegir manualmente en cada transferencia --</option>';
foreach ($companies as $comp) {
    $sel = ((int)$comp->id === $editcompid) ? ' selected="selected"' : '';
    echo '<option value="' . (int)$comp->id . '"' . $sel . '>' . s($comp->name) . ' [' . s($comp->shortname) . ']</option>';
}
echo '</select>';
echo '</div>';
echo '</div>';

echo '<div style="display:flex; justify-content:flex-end; gap:0.65rem; margin-top:0.75rem; padding-top:1rem; border-top:1px solid var(--it-border);">';
echo '<a href="' . s($pageurl->out(false)) . '" class="ct-btn-secondary">Cancelar</a>';
echo '<button type="submit" class="ct-btn-primary"><i class="fa fa-check" aria-hidden="true"></i> Guardar y verificar conexión</button>';
echo '</div>';

echo '</form>';
echo '</div>'; // End ct-add-wizard.

// Connected Nodes Grid.
if (empty($unifiedsites)) {
    echo '<div class="it-studio-surface" style="text-align:center; padding:3.5rem 1.5rem;">';
    echo '<div style="width:56px; height:56px; border-radius:16px; background:var(--it-accent-soft); color:var(--it-accent); display:inline-flex; align-items:center; justify-content:center; font-size:1.5rem; margin-bottom:1rem;"><i class="fa fa-globe" aria-hidden="true"></i></div>';
    echo '<h4 style="font-size:1.05rem; font-weight:700; color:var(--it-text-primary); margin-bottom:0.35rem;">Ningún servidor Moodle conectado todavía</h4>';
    echo '<p style="font-size:0.86rem; color:var(--it-text-muted); max-width:440px; margin:0 auto 1.25rem auto;">Vincula tus plataformas remotas con su URL y su clave de API para explorar sus cursos y consolidarlos en IOMAD.</p>';
    echo '<button type="button" class="ct-btn-primary" onclick="var p=document.getElementById(\'ct-add-wizard\'); if(p){p.style.display=\'block\'; p.scrollIntoView({behavior:\'smooth\'});}">';
    echo '<i class="fa fa-plus" aria-hidden="true"></i> Conectar primer servidor';
    echo '</button>';
    echo '</div>';
} else {
    echo '<div class="it-nodes-grid">';
    foreach ($unifiedsites as $s) {
        $ststatus = $s['lastteststatus'];
        $sterr = (string)$s['lasttesterror'];
        $stbadge = '<span class="ct-pill-badge is-pending"><span class="ct-status-dot is-warning"></span> Pendiente</span>';
        if ($ststatus !== null) {
            $stbadge = ((int)$ststatus === 1)
                ? '<span class="ct-pill-badge is-connected"><span class="ct-status-dot"></span> Conectado</span>'
                : '<span class="ct-pill-badge is-error"><span class="ct-status-dot is-error"></span> ' . s($sterr ?: 'Error') . '</span>';
        }

        $lastteststr = !empty($s['lasttest']) ? userdate((int)$s['lasttest'], '%d %b %H:%M') : 'Sin verificar';
        $testurl = new moodle_url($pageurl, [
            'action' => 'test',
            'type' => $s['is_origin'] ? 'origin' : 'target',
            'id' => $s['id'],
            'sesskey' => sesskey(),
        ]);
        $editurl = new moodle_url($pageurl, [
            'action' => 'edit',
            'type' => $s['is_origin'] ? 'origin' : 'target',
            'id' => $s['id'],
        ]);
        $delurl = new moodle_url($pageurl, [
            'action' => 'delete',
            'type' => $s['is_origin'] ? 'origin' : 'target',
            'id' => $s['id'],
            'sesskey' => sesskey(),
        ]);

        $compname = '';
        if (!empty($s['companyid']) && isset($companies[$s['companyid']])) {
            $compname = s($companies[$s['companyid']]->name);
        }

        echo '<div class="it-node-card">';

        echo '<div class="it-node-top">';
        echo '<div class="it-node-identity">';
        echo '<div class="it-node-avatar"><i class="fa fa-server" aria-hidden="true"></i></div>';
        echo '<div>';
        echo '<div class="it-node-title">' . s($s['name']) . ' ' . $stbadge . '</div>';
        echo '<span class="it-node-host">' . s($s['host']) . '</span>';
        echo '</div>';
        echo '</div>';

        echo '<div style="display:flex; gap:0.35rem;">';
        echo '<a href="' . s($testurl->out(false)) . '" class="ct-btn-icon-square is-test" title="Verificar latencia y conexión"><i class="fa fa-refresh" aria-hidden="true"></i></a>';
        echo '<a href="' . s($editurl->out(false)) . '" class="ct-btn-icon-square" title="Editar nodo"><i class="fa fa-pencil" aria-hidden="true"></i></a>';
        echo '<a href="' . s($delurl->out(false)) . '" class="ct-btn-icon-square is-delete" title="Eliminar nodo" onclick="return confirm(\'¿Eliminar el nodo ' . s($s['name']) . '?\');"><i class="fa fa-trash-o" aria-hidden="true"></i></a>';
        echo '</div>';
        echo '</div>'; // End it-node-top.

        echo '<div class="it-node-bottom">';
        echo '<div class="it-node-tags">';
        if ($s['is_origin']) {
            echo '<span class="ct-direction-badge is-active"><i class="fa fa-arrow-down" aria-hidden="true"></i> Origen</span>';
        }
        if ($s['is_target']) {
            echo '<span class="ct-direction-badge is-active"><i class="fa fa-arrow-up" aria-hidden="true"></i> Destino</span>';
        }
        if ($compname !== '') {
            echo '<span class="ct-platform-iomad-comp"><i class="fa fa-building-o" aria-hidden="true"></i> ' . $compname . '</span>';
        }
        echo '<span style="font-size:0.74rem; color:var(--it-text-subtle);">' . s($lastteststr) . '</span>';
        echo '</div>';

        if ($s['is_origin'] && $s['origin_id'] > 0) {
            $launchurl = new moodle_url('/local/iomadtransfer/consolidate.php', ['originsiteid' => (int)$s['origin_id']]);
            echo '<a href="' . s($launchurl->out(false)) . '" class="ct-btn-primary" style="padding:0.38rem 0.85rem; font-size:0.78rem;">Consolidar <i class="fa fa-arrow-right" aria-hidden="true"></i></a>';
        }
        echo '</div>'; // End it-node-bottom.

        echo '</div>'; // End it-node-card.
    }
    echo '</div>'; // End it-nodes-grid.
}

echo ui_helper::render_footer_nav('sites');

echo '</div>'; // End ct-card.
echo '</div>'; // End local-iomadtransfer.
echo $OUTPUT->footer();
