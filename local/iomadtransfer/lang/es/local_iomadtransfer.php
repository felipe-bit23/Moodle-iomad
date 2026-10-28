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
 * Spanish language strings for local_iomadtransfer.
 *
 * @package    local_iomadtransfer
 * @copyright  2026 IOMAD Multi-Tenant Consolidation Engine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'IOMAD Course Transfer & Consolidator';
$string['nav_summary'] = 'Resumen y Token de Servicio';
$string['nav_sites'] = 'Plataformas Moodle Emparejadas';
$string['nav_consolidate'] = 'Consolidar y Transferir Cursos';
$string['nav_logs'] = 'Registros y Progreso de Ejecución';
$string['nav_settings'] = 'Configuración del Plugin';

$string['iomadtransfer:manage'] = 'Gestionar plataformas y tokens de IOMAD Course Transfer';
$string['iomadtransfer:restore'] = 'Restaurar cursos remotos';
$string['iomadtransfer:consolidate_iomad'] = 'Consolidar cursos remotos, usuarios, notas e intentos en IOMAD';
$string['iomadtransfer:view_logs'] = 'Ver registros de ejecución de transferencias';

$string['settings_general_heading'] = 'Configuración General de Transferencia y Seguridad';
$string['settings_general_desc'] = 'Configura tiempos de espera, límites de backup y el campo de identificación de usuarios entre los Moodles de origen e IOMAD.';
$string['setting_max_backup_size_mb'] = 'Tamaño máximo de backup (MB)';
$string['setting_max_backup_size_mb_desc'] = 'Tamaño máximo permitido en MB para un archivo .mbz entrante.';
$string['setting_request_timeout'] = 'Tiempo de espera de Servicio Web (segundos)';
$string['setting_request_timeout_desc'] = 'Tiempo límite HTTP en segundos para peticiones REST entre servidores Moodle emparejados.';
$string['setting_ignorecurlsecurity'] = 'Omitir bloqueo cURL de IPs privadas';
$string['setting_ignorecurlsecurity_desc'] = 'Permitir peticiones cURL hacia servidores Moodle en red interna o mismo servidor.';
$string['setting_user_match_field'] = 'Campo de emparejamiento de usuarios';
$string['setting_user_match_field_desc'] = 'Campo utilizado para identificar y no duplicar estudiantes entre plataformas (se recomienda email).';

$string['status_error'] = 'Error';
$string['status_not_started'] = 'En Cola';
$string['status_in_progress'] = 'Solicitado';
$string['status_backup'] = 'Creando Backup';
$string['status_download'] = 'Descargando MBZ';
$string['status_downloaded'] = 'Descargado';
$string['status_restore'] = 'Restaurando Curso';
$string['status_consolidating'] = 'Consolidando en IOMAD';
$string['status_completed'] = 'Completado';

$string['mode_consolidate_iomad'] = 'Consolidar en Curso Maestro Existente de IOMAD (Usuarios, Notas, Completitud e Intentos de Cuestionarios sin duplicar actividades ni borrar grupos de IOMAD)';
$string['mode_new_course'] = 'Crear como Curso Nuevo en la Categoría Destino (asignándolo a la Empresa IOMAD seleccionada)';
$string['mode_replace_course'] = 'Reemplazar Contenido del Curso Existente (preservando grupos de IOMAD)';
$string['mode_merge_course'] = 'Fusión Estándar de Moodle sobre Curso Existente';

$string['cachedef_remote_courses'] = 'Caché del catálogo de cursos remotos consultados desde las plataformas Moodle de origen.';

$string['privacy:metadata:local_iomadtransfer_request'] = 'Información sobre solicitudes de transferencia y consolidación de cursos en IOMAD.';
$string['privacy:metadata:local_iomadtransfer_request:userid'] = 'El ID del usuario que lanzó la solicitud de transferencia.';
$string['privacy:metadata:local_iomadtransfer_request:siteurl'] = 'La URL de la plataforma Moodle emparejada.';
$string['privacy:metadata:local_iomadtransfer_request:origin_course_id'] = 'El ID del curso de origen.';
$string['privacy:metadata:local_iomadtransfer_request:target_course_id'] = 'El ID del curso de destino.';
$string['privacy:metadata:local_iomadtransfer_request:companyid'] = 'El ID de la empresa IOMAD de destino.';
$string['privacy:metadata:local_iomadtransfer_request:status'] = 'El estado actual de la transferencia.';
$string['privacy:metadata:local_iomadtransfer_request:timecreated'] = 'Fecha y hora en que se creó la solicitud.';

$string['privacy:metadata:local_iomadtransfer_origin'] = 'Plataformas Moodle de origen registradas por los administradores.';
$string['privacy:metadata:local_iomadtransfer_origin:userid'] = 'El ID del administrador que registró el sitio de origen.';
$string['privacy:metadata:local_iomadtransfer_origin:host'] = 'La URL del servidor Moodle remoto.';
$string['privacy:metadata:local_iomadtransfer_origin:timecreated'] = 'Fecha y hora de registro del sitio de origen.';

$string['privacy:metadata:local_iomadtransfer_target'] = 'Plataformas Moodle/IOMAD de destino autorizadas por los administradores.';
$string['privacy:metadata:local_iomadtransfer_target:userid'] = 'El ID del administrador que registró el sitio de destino.';
$string['privacy:metadata:local_iomadtransfer_target:host'] = 'La URL del servidor Moodle remoto.';
$string['privacy:metadata:local_iomadtransfer_target:timecreated'] = 'Fecha y hora de registro del sitio de destino.';

$string['privacy:metadata:remote_moodle'] = 'Copias de seguridad de cursos y registros de estudiantes intercambiados con plataformas Moodle emparejadas.';
$string['privacy:metadata:remote_moodle:userid'] = 'El ID del usuario que inicia o forma parte de la transferencia del curso.';
$string['privacy:metadata:remote_moodle:courseid'] = 'El ID del curso transferido o consolidado.';
