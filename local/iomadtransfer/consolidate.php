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
 * Interactive SaaS Consolidation Studio for local_iomadtransfer.
 * Replaces legacy HTML listboxes with interactive visual course cards and a 3-column mode grid.
 *
 * @package    local_iomadtransfer
 * @copyright  2026 IOMAD Multi-Tenant Consolidation Engine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/course/lib.php');

use local_iomadtransfer\api\request;
use local_iomadtransfer\request_manager;
use local_iomadtransfer\security_manager;
use local_iomadtransfer\sites_manager;
use local_iomadtransfer\ui_helper;

require_login();
$context = context_system::instance();
require_capability('local/iomadtransfer:consolidate_iomad', $context);

$originsiteid = optional_param('originsiteid', 0, PARAM_INT);
$targetcourseid = optional_param('target_course_id', 0, PARAM_INT);
$refresh = optional_param('refresh', 0, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);

$pageurl = new moodle_url('/local/iomadtransfer/consolidate.php', [
    'originsiteid' => $originsiteid,
    'target_course_id' => $targetcourseid,
]);
$PAGE->set_context($context);
$PAGE->set_url($pageurl);
$PAGE->set_pagelayout('admin');
$PAGE->set_title('Consolidar en IOMAD - IOMAD Transfer');
$PAGE->set_heading(get_string('pluginname', 'local_iomadtransfer'));

$originsites = sites_manager::list_sites('origin');
$companies = security_manager::get_iomad_companies();

// Handle form submission to launch transfer/consolidation.
if ($action === 'launch' && data_submitted()) {
    require_sesskey();
    $originsiteid = required_param('originsiteid', PARAM_INT);
    $origincourseid = required_param('origin_course_id', PARAM_INT);
    $mode = required_param('target_target', PARAM_INT);
    $targetcourseid = optional_param('target_course_id', 0, PARAM_INT);
    $targetcatid = optional_param('target_category_id', 1, PARAM_INT);
    $companyid = optional_param('companyid', 0, PARAM_INT);
    $includeusers = 1;

    $originsite = sites_manager::get_site('origin', $originsiteid);
    if (!$originsite) {
        redirect($pageurl, 'Plataforma de origen no encontrada.', null, \core\output\notification::NOTIFY_ERROR);
    }

    if ($mode === request_manager::TARGET_NEW_COURSE) {
        $cat = core_course_category::get($targetcatid, IGNORE_MISSING);
        if (!$cat) {
            $cat = core_course_category::get_default();
        }
        $newcourse = new stdClass();
        $newcourse->category = $cat->id;
        $newcourse->fullname = 'Restaurando desde ' . $originsite->name . '...';
        $newcourse->shortname = 'IT-RESTORE-' . time() . '-' . rand(100, 999);
        $newcourse->groupmode = SEPARATEGROUPS;
        $created = create_course($newcourse);
        $targetcourseid = (int)$created->id;
    } else if ($targetcourseid <= 1) {
        redirect($pageurl, 'Debes seleccionar el Curso Maestro Destino en este Moodle/IOMAD.', null, \core\output\notification::NOTIFY_ERROR);
    }

    $req = new stdClass();
    $req->type = ($mode === request_manager::TARGET_CONSOLIDATE_IOMAD) ? 5 : 0;
    $req->siteurl = $originsite->host;
    $req->direction = request_manager::DIRECTION_TARGET;
    $req->origin_course_id = $origincourseid;
    $req->origin_enrolusers = $includeusers;
    $req->target_course_id = $targetcourseid;
    $req->target_category_id = $targetcatid;
    $req->target_target = $mode;
    $req->companyid = $companyid;
    $req->userid = (int)$USER->id;
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

        $logsurl = new moodle_url('/local/iomadtransfer/logs.php');
        redirect($logsurl, 'Solicitud #' . $requestid . ' iniciada en ' . $originsite->name . '. El backup y la consolidación se ejecutan en segundo plano.', null, \core\output\notification::NOTIFY_SUCCESS);
    } else {
        $errcode = $wsres->errors[0]->code ?? 'WS_ERR';
        $errmsg = $wsres->errors[0]->msg ?? 'Error al solicitar backup en el sitio de origen';
        request_manager::mark_error($requestid, $errcode, $errmsg);
        $logsurl = new moodle_url('/local/iomadtransfer/logs.php');
        redirect($logsurl, 'Error al iniciar transferencia (#' . $requestid . '): ' . $errmsg, null, \core\output\notification::NOTIFY_ERROR);
    }
}

$localcourses = $DB->get_records_select('course', 'id > 1', null, 'fullname ASC', 'id, fullname, shortname');
$localcats = core_course_category::make_categories_list();

$selectedorigin = ($originsiteid > 0) ? sites_manager::get_site('origin', $originsiteid) : null;
$remotecourses = [];
$remoteerror = '';
if ($selectedorigin) {
    $cache = null;
    $cachekey = 'origin_' . (int)$selectedorigin->id;
    try {
        $cache = \cache::make('local_iomadtransfer', 'remote_courses');
    } catch (\Throwable $e) {
        $cache = null;
    }

    $cached = (!$refresh && $cache) ? $cache->get($cachekey) : false;
    if (is_array($cached) && !empty($cached)) {
        $remotecourses = $cached;
    } else {
        $client = new request($selectedorigin);
        $res = $client->origin_get_courses();
        if ($res->success && is_array($res->data)) {
            $remotecourses = $res->data;
            if ($cache) {
                $cache->set($cachekey, $remotecourses);
            }
        } else {
            $remoteerror = $res->errors[0]->msg ?? 'No se pudo obtener la lista de cursos remotos.';
        }
    }
}

echo $OUTPUT->header();
echo '<div class="local-iomadtransfer">';

echo '<div class="ct-card">';

$topbtn = $selectedorigin
    ? '<a href="' . s((new moodle_url('/local/iomadtransfer/consolidate.php'))->out(false)) . '" class="ct-btn-secondary"><i class="fa fa-exchange" aria-hidden="true"></i> Cambiar servidor</a>'
    : '<a href="' . s((new moodle_url('/local/iomadtransfer/logs.php'))->out(false)) . '" class="ct-btn-secondary"><i class="fa fa-bolt" aria-hidden="true"></i> Ver actividad</a>';
echo ui_helper::render_header_banner($topbtn, 'consolidate');

echo '<div class="it-studio-surface">';

// Sleek Pill Stepper.
$step1done = ($selectedorigin !== null);
echo '<div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:1rem; margin-bottom:1.25rem;">';
echo '<div class="ct-stepper" style="margin:0;">';
echo '<div class="ct-step-item ' . ($step1done ? 'is-done' : 'is-active') . '" id="step-node-1">';
echo '<div class="ct-step-number">' . ($step1done ? '✓' : '1') . '</div>';
echo '<span class="ct-step-label">Servidor Origen</span>';
echo '</div>';
echo '<div class="ct-step-connector"></div>';
echo '<div class="ct-step-item ' . ($step1done ? 'is-active' : '') . '" id="step-node-2">';
echo '<div class="ct-step-number">2</div>';
echo '<span class="ct-step-label">Curso &amp; Destino IOMAD</span>';
echo '</div>';
echo '<div class="ct-step-connector"></div>';
echo '<div class="ct-step-item" id="step-node-3">';
echo '<div class="ct-step-number">3</div>';
echo '<span class="ct-step-label">Confirmar y Lanzar</span>';
echo '</div>';
echo '</div>';

if ($selectedorigin) {
    echo '<div style="display:flex; align-items:center; gap:0.5rem;">';
    echo '<span class="ct-pill-badge is-connected"><span class="ct-status-dot"></span> ' . s($selectedorigin->name) . ' (' . count($remotecourses) . ' cursos)</span>';
    $refurl = new moodle_url('/local/iomadtransfer/consolidate.php', [
        'originsiteid' => (int)$selectedorigin->id,
        'target_course_id' => $targetcourseid,
        'refresh' => 1,
    ]);
    echo '<a href="' . s($refurl->out(false)) . '" class="ct-btn-icon-square is-test" title="Sincronizar catálogo remoto en vivo"><i class="fa fa-refresh" aria-hidden="true"></i></a>';
    echo '</div>';
}
echo '</div>';

// STEP 1: Interactive Node Cards (No lonely government dropdown!).
if (!$selectedorigin) {
    echo '<div id="step-pane-1">';
    echo '<h3 style="font-size:1.15rem; font-weight:700; color:var(--it-text-primary); margin:0 0 0.2rem 0;">Selecciona el servidor Moodle de origen</h3>';
    echo '<p style="color:var(--it-text-muted); font-size:0.86rem; margin-bottom:1.25rem;">Haz clic sobre una plataforma conectada para explorar su catálogo de cursos e iniciar la fusión.</p>';

    if (empty($originsites)) {
        echo '<div class="ct-callout-info">';
        echo '<i class="fa fa-info-circle" aria-hidden="true"></i>';
        echo '<div>Aún no hay servidores de origen registrados. Conecta tu primer Moodle en <a href="' . s((new moodle_url('/local/iomadtransfer/sites.php', ['showadd' => 1]))->out(false)) . '"><strong>Plataformas</strong></a>.</div>';
        echo '</div>';
    } else {
        echo '<div class="it-nodes-grid" style="margin-bottom:0;">';
        foreach ($originsites as $os) {
            $selurl = new moodle_url('/local/iomadtransfer/consolidate.php', [
                'originsiteid' => (int)$os->id,
                'target_course_id' => $targetcourseid,
            ]);
            $compbadge = '';
            if (!empty($os->default_companyid) && isset($companies[$os->default_companyid])) {
                $compbadge = '<span class="ct-platform-iomad-comp"><i class="fa fa-building-o" aria-hidden="true"></i> ' . s($companies[$os->default_companyid]->name) . '</span>';
            }
            echo '<a href="' . s($selurl->out(false)) . '" class="it-node-card" style="text-decoration:none!important; color:inherit;">';
            echo '<div class="it-node-top">';
            echo '<div class="it-node-identity">';
            echo '<div class="it-node-avatar"><i class="fa fa-server" aria-hidden="true"></i></div>';
            echo '<div>';
            echo '<div class="it-node-title">' . s($os->name) . '</div>';
            echo '<span class="it-node-host">' . s($os->host) . '</span>';
            echo '</div>';
            echo '</div>';
            echo '<span class="ct-btn-secondary" style="padding:0.35rem 0.75rem; font-size:0.78rem; color:var(--it-accent)!important; border-color:var(--it-accent-border)!important;">Explorar <i class="fa fa-arrow-right" aria-hidden="true"></i></span>';
            echo '</div>';
            if ($compbadge !== '') {
                echo '<div class="it-node-bottom" style="padding-top:0.65rem;">' . $compbadge . '</div>';
            }
            echo '</a>';
        }
        echo '</div>';
    }
    echo '</div>'; // End step 1.
} else {
    $defaultcompid = (int)($selectedorigin->default_companyid ?? 0);
    if ($defaultcompid <= 0 && !empty($companies)) {
        $firstcomp = reset($companies);
        $defaultcompid = (int)$firstcomp->id;
    }

    echo '<form method="post" action="' . s((new moodle_url('/local/iomadtransfer/consolidate.php'))->out(false)) . '" id="consolidate-wizard-form">';
    echo '<input type="hidden" name="sesskey" value="' . s(sesskey()) . '">';
    echo '<input type="hidden" name="action" value="launch">';
    echo '<input type="hidden" name="originsiteid" value="' . (int)$selectedorigin->id . '">';
    echo '<input type="hidden" name="includeusers" value="1">';

    // STEP 2 PANE.
    echo '<div id="step-pane-2">';

    if ($remoteerror !== '') {
        echo '<div class="ct-callout-info" style="border-left-color:var(--it-rose);"><i class="fa fa-exclamation-circle" style="color:var(--it-rose);" aria-hidden="true"></i><div>Error al consultar ' . s($selectedorigin->host) . ': ' . s($remoteerror) . '</div></div>';
    } else if (empty($remotecourses)) {
        echo '<div class="ct-callout-info"><i class="fa fa-info-circle" aria-hidden="true"></i><div>No se encontraron cursos disponibles en el servidor remoto.</div></div>';
    } else {
        // 1. Visual Interactive Course Picker (Replaces ugly <select size="6">!).
        $firstcourse = (object)reset($remotecourses);
        $firstid = (int)$firstcourse->id;
        $firstmb = round(((int)($firstcourse->estimatedbytes ?? 0)) / 1048576, 1);
        $firstlabel = '[ID: ' . $firstid . '] [' . $firstcourse->shortname . '] ' . $firstcourse->fullname . ' (' . (int)($firstcourse->enrolledcount ?? 0) . ' alumnos · ~' . $firstmb . ' MB)';

        echo '<input type="hidden" name="origin_course_id" id="origin-course-select" value="' . $firstid . '" data-label="' . s($firstlabel) . '">';

        echo '<div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:0.5rem;">';
        echo '<label style="font-size:0.82rem; font-weight:700; color:var(--it-text-primary); margin:0;">1. Elige el curso de origen en ' . s($selectedorigin->name) . '</label>';
        echo '<span style="font-size:0.76rem; color:var(--it-text-muted);">Haz clic en una tarjeta para seleccionarla</span>';
        echo '</div>';

        echo '<div class="it-course-picker">';
        echo '<div class="it-course-picker-header">';
        echo '<i class="fa fa-search" style="color:var(--it-text-subtle);" aria-hidden="true"></i>';
        echo '<input type="text" class="it-course-search" placeholder="Buscar curso por nombre, código corto o ID..." oninput="filterRemoteCourses(this.value);">';
        echo '</div>';

        echo '<div class="it-course-list" id="it-remote-course-list">';
        $isfirst = true;
        foreach ($remotecourses as $rc) {
            $rc = (object)$rc;
            $cid = (int)$rc->id;
            $mb = round(((int)($rc->estimatedbytes ?? 0)) / 1048576, 1);
            $enrolled = (int)($rc->enrolledcount ?? 0);
            $full = (string)$rc->fullname;
            $short = (string)$rc->shortname;
            $summarylabel = '[ID: ' . $cid . '] [' . $short . '] ' . $full . ' (' . $enrolled . ' alumno(s) · ~' . $mb . ' MB)';

            echo '<div class="it-course-item ' . ($isfirst ? 'is-selected' : '') . '" data-id="' . $cid . '" data-label="' . s($summarylabel) . '" onclick="selectRemoteCourseCard(this);">';
            echo '<div class="it-course-item-left">';
            echo '<div class="it-course-radio"></div>';
            echo '<div>';
            echo '<div class="it-course-title">' . s($full) . '</div>';
            echo '<div class="it-course-sub">ID #' . $cid . ' &middot; ' . s($short) . '</div>';
            echo '</div>';
            echo '</div>';
            echo '<div class="it-course-meta">';
            echo '<span class="ct-metric-chip is-users"><i class="fa fa-user" aria-hidden="true"></i> ' . $enrolled . '</span>';
            echo '<span class="ct-metric-chip">~' . $mb . ' MB</span>';
            echo '</div>';
            echo '</div>';
            $isfirst = false;
        }
        echo '</div>';
        echo '</div>'; // End it-course-picker.

        // 2. 3-Column Visual Mode Selector.
        echo '<label style="font-size:0.82rem; font-weight:700; color:var(--it-text-primary); margin-bottom:0.5rem; display:block;">2. Estrategia de consolidación</label>';
        echo '<div class="it-mode-grid">';

        echo '<label class="ct-option-card is-selected" id="opt-card-mode-5" onclick="selectConsolidateMode(5);">';
        echo '<input type="radio" name="target_target" id="rb_mode_5" value="' . request_manager::TARGET_CONSOLIDATE_IOMAD . '" checked="checked" onclick="event.stopPropagation(); selectConsolidateMode(5);">';
        echo '<div>';
        echo '<div class="ct-option-title"><i class="fa fa-compress" style="color:var(--it-accent);" aria-hidden="true"></i> Fusión 12 a 1 en Curso Maestro</div>';
        echo '<div class="ct-option-desc">Fusiona alumnos, notas, completitud e intentos en las actividades existentes del Curso Maestro y los vincula al grupo oficial de la empresa en IOMAD.</div>';
        echo '</div>';
        echo '</label>';

        echo '<label class="ct-option-card" id="opt-card-mode-2" onclick="selectConsolidateMode(2);">';
        echo '<input type="radio" name="target_target" id="rb_mode_2" value="' . request_manager::TARGET_NEW_COURSE . '" onclick="event.stopPropagation(); selectConsolidateMode(2);">';
        echo '<div>';
        echo '<div class="ct-option-title"><i class="fa fa-plus-circle" style="color:var(--it-emerald);" aria-hidden="true"></i> Crear como Curso Nuevo</div>';
        echo '<div class="ct-option-desc">Crea un curso independiente en la categoría elegida con todos sus alumnos, calificaciones y cuestionarios asignados a la empresa IOMAD.</div>';
        echo '</div>';
        echo '</label>';

        echo '<label class="ct-option-card" id="opt-card-mode-3" onclick="selectConsolidateMode(3);">';
        echo '<input type="radio" name="target_target" id="rb_mode_3" value="' . request_manager::TARGET_REPLACE_COURSE . '" onclick="event.stopPropagation(); selectConsolidateMode(3);">';
        echo '<div>';
        echo '<div class="ct-option-title"><i class="fa fa-refresh" style="color:var(--it-amber);" aria-hidden="true"></i> Reemplazar Curso Existente</div>';
        echo '<div class="ct-option-desc">Actualiza la estructura del curso destino conservando las vinculaciones multi-empresa y matriculando a los nuevos alumnos.</div>';
        echo '</div>';
        echo '</label>';

        echo '</div>'; // End it-mode-grid.

        // 3. Target Master Course & IOMAD Company Studio.
        echo '<div class="row">';
        echo '<div class="col-md-6 mb-3" id="target-course-col">';
        echo '<label style="font-size:0.82rem; font-weight:700; color:var(--it-text-primary); margin-bottom:0.4rem; display:block;"><i class="fa fa-book" style="color:var(--it-accent);" aria-hidden="true"></i> 3. Curso Maestro Destino (IOMAD)</label>';
        echo '<input type="text" class="ct-filter-input mb-2" style="width:100%; height:36px;" placeholder="Filtrar curso maestro local..." oninput="var q=this.value.toLowerCase(); var opts=document.getElementById(\'target-course-select\').options; for(var i=1;i<opts.length;i++){ opts[i].style.display = opts[i].text.toLowerCase().indexOf(q)!==-1 ? \'\' : \'none\'; }">';
        echo '<select name="target_course_id" id="target-course-select" class="ct-filter-select" style="width:100%; height:42px;">';
        echo '<option value="0">-- Selecciona el Curso Maestro Destino --</option>';
        foreach ($localcourses as $lc) {
            $sel = ((int)$lc->id === $targetcourseid) ? ' selected="selected"' : '';
            echo '<option value="' . (int)$lc->id . '"' . $sel . '>[ID: ' . (int)$lc->id . '] [' . s($lc->shortname) . '] ' . s($lc->fullname) . '</option>';
        }
        echo '</select>';
        echo '</div>';

        echo '<div class="col-md-6 mb-3" id="target-category-row" style="display:none;">';
        echo '<label style="font-size:0.82rem; font-weight:700; color:var(--it-text-primary); margin-bottom:0.4rem; display:block;"><i class="fa fa-folder-open-o" style="color:var(--it-accent);" aria-hidden="true"></i> 3. Categoría Destino</label>';
        echo '<div style="height:36px; margin-bottom:0.5rem; display:flex; align-items:center; font-size:0.78rem; color:var(--it-text-muted);">Categoría donde se creará el nuevo curso en IOMAD.</div>';
        echo '<select name="target_category_id" id="target-category-select" class="ct-filter-select" style="width:100%; height:42px;">';
        foreach ($localcats as $catid => $catname) {
            echo '<option value="' . (int)$catid . '">' . s($catname) . '</option>';
        }
        echo '</select>';
        echo '</div>';

        echo '<div class="col-md-6 mb-3">';
        echo '<label style="font-size:0.82rem; font-weight:700; color:var(--it-text-primary); margin-bottom:0.4rem; display:block;"><i class="fa fa-building-o" style="color:var(--it-accent);" aria-hidden="true"></i> 4. Empresa y Grupo Oficial IOMAD</label>';
        echo '<div style="height:36px; margin-bottom:0.5rem; display:flex; align-items:center; font-size:0.78rem; color:var(--it-text-muted);">Matricula automáticamente a los estudiantes en el grupo de esta empresa.</div>';
        echo '<select name="companyid" id="company-select" class="ct-filter-select" style="width:100%; height:42px;">';
        echo '<option value="0">-- Sin empresa específica --</option>';
        foreach ($companies as $comp) {
            $sel = ((int)$comp->id === $defaultcompid) ? ' selected="selected"' : '';
            echo '<option value="' . (int)$comp->id . '"' . $sel . '>' . s($comp->name) . ' [' . s($comp->shortname) . '] (ID: ' . (int)$comp->id . ')</option>';
        }
        echo '</select>';
        echo '</div>';
        echo '</div>';

        // Sleek Emerald Guarantee Strip.
        echo '<div style="background:var(--it-emerald-bg); border:1px solid var(--it-emerald-border); border-radius:10px; padding:0.75rem 1rem; display:flex; align-items:center; gap:0.75rem; margin-bottom:1.25rem; font-size:0.82rem; color:var(--it-emerald);">';
        echo '<i class="fa fa-check-circle" style="font-size:1.1rem;" aria-hidden="true"></i>';
        echo '<div><strong>Sincronización Integral Activa:</strong> Se importarán todos los estudiantes matriculados junto con sus notas, intentos de cuestionarios, entregas de tareas y membresía en el grupo oficial de IOMAD.</div>';
        echo '</div>';

        // Step 2 Action Bar.
        echo '<div style="display:flex; justify-content:space-between; align-items:center; padding-top:1rem; border-top:1px solid var(--it-border);">';
        echo '<a href="' . s((new moodle_url('/local/iomadtransfer/consolidate.php'))->out(false)) . '" class="ct-btn-secondary"><i class="fa fa-arrow-left" aria-hidden="true"></i> Cambiar servidor</a>';
        echo '<button type="button" class="ct-btn-primary" onclick="goToStep3();">Continuar a revisión <i class="fa fa-arrow-right" aria-hidden="true"></i></button>';
        echo '</div>';
    }
    echo '</div>'; // End step 2.

    // STEP 3 PANE (Launch Pad).
    echo '<div id="step-pane-3" style="display:none;">';
    echo '<h3 style="font-size:1.15rem; font-weight:700; color:var(--it-text-primary); margin:0 0 0.2rem 0;">Confirmar operación de consolidación</h3>';
    echo '<p style="color:var(--it-text-muted); font-size:0.86rem; margin-bottom:1.25rem;">Revisa el flujo de datos antes de iniciar el empaquetado remoto y la fusión en segundo plano.</p>';

    echo '<div class="ct-summary-grid">';

    echo '<div class="ct-summary-item">';
    echo '<div class="ct-summary-label"><i class="fa fa-server" style="color:var(--it-accent);" aria-hidden="true"></i> Servidor de Origen</div>';
    echo '<div class="ct-summary-value" id="sum-origin-name">' . s($selectedorigin->name) . ' <span style="font-weight:400; font-size:0.8rem; color:var(--it-text-muted);">(' . s($selectedorigin->host) . ')</span></div>';
    echo '</div>';

    echo '<div class="ct-summary-item">';
    echo '<div class="ct-summary-label"><i class="fa fa-sliders" style="color:var(--it-accent);" aria-hidden="true"></i> Estrategia</div>';
    echo '<div class="ct-summary-value"><span id="sum-mode-name" class="ct-pill-badge is-connected">Fusión 12 a 1 en Curso Maestro</span></div>';
    echo '</div>';

    echo '<div class="ct-summary-item">';
    echo '<div class="ct-summary-label"><i class="fa fa-graduation-cap" style="color:var(--it-accent);" aria-hidden="true"></i> Curso Remoto</div>';
    echo '<div class="ct-summary-value" id="sum-origin-course" style="color:var(--it-accent);">—</div>';
    echo '</div>';

    echo '<div class="ct-summary-item">';
    echo '<div class="ct-summary-label"><i class="fa fa-book" style="color:var(--it-accent);" aria-hidden="true"></i> Destino en IOMAD</div>';
    echo '<div class="ct-summary-value" id="sum-target-course">—</div>';
    echo '</div>';

    echo '<div class="ct-summary-item">';
    echo '<div class="ct-summary-label"><i class="fa fa-building-o" style="color:var(--it-accent);" aria-hidden="true"></i> Empresa y Grupo IOMAD</div>';
    echo '<div class="ct-summary-value" id="sum-company-name">—</div>';
    echo '</div>';

    echo '<div class="ct-summary-item">';
    echo '<div class="ct-summary-label"><i class="fa fa-users" style="color:var(--it-emerald);" aria-hidden="true"></i> Alcance de Datos</div>';
    echo '<div class="ct-summary-value" style="color:var(--it-emerald);">Alumnos + Grupo IOMAD + Calificaciones + Intentos</div>';
    echo '</div>';

    echo '</div>'; // End ct-summary-grid.

    echo '<div style="display:flex; justify-content:space-between; align-items:center; padding-top:1rem; border-top:1px solid var(--it-border);">';
    echo '<button type="button" class="ct-btn-secondary" onclick="backToStep2();"><i class="fa fa-arrow-left" aria-hidden="true"></i> Volver a editar</button>';
    echo '<button type="submit" class="ct-btn-primary" style="padding:0.68rem 1.65rem; font-size:0.95rem;">';
    echo '<i class="fa fa-rocket" aria-hidden="true"></i> Iniciar Consolidación Ahora';
    echo '</button>';
    echo '</div>';

    echo '</div>'; // End step 3.

    echo '</form>';
}

echo '</div>'; // End it-studio-surface.

echo '<script>
function selectRemoteCourseCard(card) {
    document.querySelectorAll("#it-remote-course-list .it-course-item").forEach(function(el) {
        el.classList.remove("is-selected");
    });
    card.classList.add("is-selected");
    var hidden = document.getElementById("origin-course-select");
    if (hidden) {
        hidden.value = card.getAttribute("data-id") || "";
        hidden.setAttribute("data-label", card.getAttribute("data-label") || "");
    }
}

function filterRemoteCourses(query) {
    var q = (query || "").toLowerCase();
    document.querySelectorAll("#it-remote-course-list .it-course-item").forEach(function(el) {
        var txt = (el.textContent || "").toLowerCase();
        el.style.display = (!q || txt.indexOf(q) !== -1) ? "" : "none";
    });
}

function selectConsolidateMode(mode) {
    document.querySelectorAll(".ct-option-card[id^=opt-card-mode]").forEach(function(e){ e.classList.remove("is-selected"); });
    var rb = document.getElementById("rb_mode_" + mode);
    if (rb) { rb.checked = true; }
    var card = document.getElementById("opt-card-mode-" + mode);
    if (card) { card.classList.add("is-selected"); }

    var catRow = document.getElementById("target-category-row");
    var courseCol = document.getElementById("target-course-col");
    if (mode === 2) {
        if (catRow) catRow.style.display = "";
        if (courseCol) courseCol.style.display = "none";
    } else {
        if (catRow) catRow.style.display = "none";
        if (courseCol) courseCol.style.display = "";
    }
}

function goToStep3() {
    var cHidden = document.getElementById("origin-course-select");
    if (!cHidden || !cHidden.value) {
        alert("Selecciona un curso de origen de la lista.");
        return;
    }

    var mode = 5;
    if (document.getElementById("rb_mode_2") && document.getElementById("rb_mode_2").checked) mode = 2;
    if (document.getElementById("rb_mode_3") && document.getElementById("rb_mode_3").checked) mode = 3;

    var tSel = document.getElementById("target-course-select");
    if (mode !== 2 && (!tSel || tSel.value === "0" || !tSel.value)) {
        alert("Selecciona el Curso Maestro Destino en este Moodle/IOMAD.");
        return;
    }

    document.getElementById("sum-origin-course").textContent = cHidden.getAttribute("data-label") || ("Curso ID #" + cHidden.value);
    document.getElementById("sum-mode-name").textContent = (mode === 5) ? "Fusión 12 a 1 en Curso Maestro" : (mode === 2 ? "Crear como Curso Nuevo" : "Reemplazar Curso Existente");
    if (mode === 2) {
        var catSel = document.getElementById("target-category-select");
        var catTxt = (catSel && catSel.selectedIndex >= 0) ? catSel.options[catSel.selectedIndex].text : "Categoría por defecto";
        document.getElementById("sum-target-course").textContent = "Curso nuevo en: " + catTxt;
    } else {
        document.getElementById("sum-target-course").textContent = tSel.options[tSel.selectedIndex].text;
    }

    var compSel = document.getElementById("company-select");
    document.getElementById("sum-company-name").textContent = (compSel && compSel.value !== "0") ? compSel.options[compSel.selectedIndex].text : "Sin empresa específica";

    document.getElementById("step-pane-2").style.display = "none";
    document.getElementById("step-pane-3").style.display = "block";
    document.getElementById("step-node-2").classList.remove("is-active");
    document.getElementById("step-node-2").classList.add("is-done");
    document.getElementById("step-node-3").classList.add("is-active");
    window.scrollTo({top: 0, behavior: "smooth"});
}

function backToStep2() {
    document.getElementById("step-pane-3").style.display = "none";
    document.getElementById("step-pane-2").style.display = "block";
    document.getElementById("step-node-3").classList.remove("is-active");
    document.getElementById("step-node-2").classList.remove("is-done");
    document.getElementById("step-node-2").classList.add("is-active");
    window.scrollTo({top: 0, behavior: "smooth"});
}
</script>';

echo ui_helper::render_footer_nav('consolidate');

echo '</div>'; // End ct-card.
echo '</div>'; // End local-iomadtransfer.
echo $OUTPUT->footer();
