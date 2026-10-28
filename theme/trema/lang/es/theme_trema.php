<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Spanish language file for theme_trema (IOMAD Multi-Tenant).
 *
 * @package     theme_trema
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['multitenant_selector_title'] = 'Configuración Multi-Tenant por Compañía (IOMAD)';
$string['multitenant_select_label'] = 'Seleccionar compañía a configurar:';
$string['multitenant_global'] = 'Global (Por defecto para todas las compañías)';
$string['multitenant_editing_company'] = 'Editando la configuración personalizada del tema para la compañía: {$a}. Cualquier campo vacío heredará el valor Global.';
$string['multitenant_editing_global'] = 'Editando la configuración Global por defecto (se aplica cuando una compañía no define su propia configuración).';
$string['enable_tenant_config'] = 'Habilitar configuración personalizada del tema para esta compañía';
$string['enable_tenant_config_desc'] = 'Si se activa, esta compañía usará sus propios colores, logos, banners, carrusel, tarjetas, textos y pie de página configurados aquí. Si se desactiva, usará la configuración Global.';
$string['tenantsitename'] = 'Título del Sitio Principal o Compañía (Barra superior y Login)';
$string['tenantsitename_desc'] = 'Nombre personalizado opcional para mostrar en la barra superior y login del Sitio Principal (al editar Global) o de esta compañía. Si se deja vacío, se usará automáticamente el nombre de la compañía en IOMAD o el nombre corto del sitio.';
$string['logo'] = 'Logo completo (Login y cabecera)';
$string['logo_desc'] = 'Sube el logo principal para esta compañía (o el global). Si se deja vacío, tomará automáticamente el logo de la compañía en IOMAD o el global de Moodle.';
$string['compactlogo'] = 'Logo compacto (Barra de navegación superior)';
$string['compactlogo_desc'] = 'Sube el logo pequeño que aparece en la barra superior izquierda para esta compañía.';
$string['isolatecompanyblocks'] = 'Aislar bloques centrales de la página de inicio para esta compañía';
$string['isolatecompanyblocks_desc'] = 'Si se activa, la región central de bloques de la página de inicio será exclusiva para esta compañía (content-cID), permitiendo agregar bloques distintos para cada empresa.';
$string['region-content'] = 'Contenido Central de Página de Inicio';
