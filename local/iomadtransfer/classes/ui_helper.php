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
 * SaaS App Shell, Segmented Navigation Dock & Layout Helper for local_iomadtransfer.
 *
 * @package    local_iomadtransfer
 * @copyright  2026 IOMAD Multi-Tenant Consolidation Engine
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_iomadtransfer;

use moodle_url;

defined('MOODLE_INTERNAL') || die();

/**
 * Renders the modern SaaS top navigation bar and discreet footer status bar.
 */
class ui_helper {
    /**
     * Returns the navigation tab definitions.
     *
     * @return array
     */
    private static function get_nav_items(): array {
        return [
            'summary' => [
                'icon' => 'fa-shield',
                'label' => 'Resumen',
                'url' => new moodle_url('/local/iomadtransfer/index.php'),
            ],
            'sites' => [
                'icon' => 'fa-globe',
                'label' => 'Plataformas',
                'url' => new moodle_url('/local/iomadtransfer/sites.php'),
            ],
            'consolidate' => [
                'icon' => 'fa-sliders',
                'label' => 'Consolidar',
                'url' => new moodle_url('/local/iomadtransfer/consolidate.php'),
            ],
            'logs' => [
                'icon' => 'fa-bolt',
                'label' => 'Actividad',
                'url' => new moodle_url('/local/iomadtransfer/logs.php'),
            ],
            'settings' => [
                'icon' => 'fa-cog',
                'label' => 'Ajustes',
                'url' => new moodle_url('/admin/settings.php', ['section' => 'local_iomadtransfer']),
            ],
        ];
    }

    /**
     * Renders the unified SaaS top application bar with Brand, Segmented Navigation Dock,
     * and Contextual Action CTA.
     *
     * @param string $actionbutton Optional right-aligned CTA HTML
     * @param string $active Active tab key ('summary', 'sites', 'consolidate', 'logs', 'settings')
     * @return string HTML
     */
    public static function render_header_banner(string $actionbutton = '', string $active = 'summary'): string {
        $tabs = self::get_nav_items();

        $html = '<header class="it-topbar">';

        // 1. Brand identity.
        $html .= '<div class="it-brand">';
        $html .= '<div class="it-brand-logo"><i class="fa fa-compress" aria-hidden="true"></i></div>';
        $html .= '<div>';
        $html .= '<div class="it-brand-title-row">';
        $html .= '<h1 class="it-brand-title">IOMAD Transfer</h1>';
        $html .= '<span class="it-brand-badge">Multi-Tenant Studio</span>';
        $html .= '</div>';
        $html .= '<p class="it-brand-subtitle">Consolidación inteligente de cursos, alumnos, notas y grupos IOMAD</p>';
        $html .= '</div>';
        $html .= '</div>';

        // 2. Center Segmented Dock.
        $html .= '<nav class="it-nav-dock" aria-label="Navegación principal">';
        foreach ($tabs as $key => $tab) {
            $cls = ($key === $active) ? 'it-nav-tab is-active' : 'it-nav-tab';
            $html .= '<a href="' . s($tab['url']->out(false)) . '" class="' . $cls . '">';
            $html .= '<i class="fa ' . s($tab['icon']) . '" aria-hidden="true"></i>';
            $html .= '<span>' . s($tab['label']) . '</span>';
            $html .= '</a>';
        }
        $html .= '</nav>';

        // 3. Contextual Action Button.
        if ($actionbutton !== '') {
            $html .= '<div class="it-topbar-actions">' . $actionbutton . '</div>';
        }

        $html .= '</header>';
        return $html;
    }

    /**
     * Renders a sleek, minimal SaaS footer bar.
     *
     * @param string $active
     * @return string HTML
     */
    public static function render_footer_nav(string $active): string {
        $tabs = self::get_nav_items();
        $html = '<footer class="it-footer-bar">';
        $html .= '<div><i class="fa fa-check-circle" style="color:var(--it-emerald);" aria-hidden="true"></i> <strong>IOMAD Course Transfer &amp; Consolidator</strong> &middot; Motor de Sincronización 12 a 1</div>';
        $html .= '<div class="it-footer-links">';
        foreach ($tabs as $key => $tab) {
            $cls = ($key === $active) ? 'is-active' : '';
            $html .= '<a href="' . s($tab['url']->out(false)) . '" class="' . $cls . '">' . s($tab['label']) . '</a>';
        }
        $html .= '</div>';
        $html .= '</footer>';
        return $html;
    }

    /**
     * Backward-compatible alias for render_footer_nav.
     *
     * @param string $active
     * @return string
     */
    public static function render_tabs(string $active): string {
        return self::render_footer_nav($active);
    }
}
