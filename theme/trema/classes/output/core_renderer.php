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
 * Core renderer with IOMAD Multi-Tenant support (combining theme_trema and theme_iomad).
 *
 * @package     theme_trema
 * @copyright   2019-2026 Trema - {@link https://trema.tech/}
 * @copyright   2023-2026 TNG Consulting Inc. - {@link https://www.tngconsulting.ca/}
 * @author      Rodrigo Mady
 * @author      Trevor Furtado
 * @author      Michael Milette
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace theme_trema\output;

use custom_menu;
use stdClass;
use moodle_url;
use html_writer;

defined('MOODLE_INTERNAL') || die;

require_once($CFG->dirroot . '/course/format/lib.php');
require_once($CFG->dirroot . '/theme/trema/locallib.php');

/**
 * Class core_renderer.
 *
 * @package theme_trema
 */
class core_renderer extends \theme_boost\output\core_renderer {
    /**
     * Returns the url of the favicon (resolved per IOMAD company if configured).
     *
     * @return moodle_url|string
     */
    public function favicon() {
        global $CFG;
        static $favicons = [];

        $companyid = theme_trema_get_active_companyid();
        if (!empty($favicons[$companyid])) {
            return $favicons[$companyid];
        }

        $rev = function_exists('theme_get_revision') ? (int)theme_get_revision() : 1;
        $ckey = "renderer_favicon_{$companyid}_{$rev}";
        $muccache = function_exists('theme_trema_get_image_muc_cache') ? theme_trema_get_image_muc_cache() : null;
        if ($muccache) {
            $hit = $muccache->get($ckey);
            if ($hit !== false && !empty($hit)) {
                $favicons[$companyid] = new moodle_url($hit);
                return $favicons[$companyid];
            }
        }

        // Use favicon configured in theme's settings (company-specific or global).
        $favicon = theme_trema_setting_file_url('favicon', 'favicon', $this->page->theme, $companyid);

        // If no favicon set in the theme, see if theme includes a favicon.ico file.
        if (empty($favicon) && (file_exists($this->page->theme->dir . '/pix/favicon.ico'))) {
            $favicon = $this->page->theme->image_url('favicon', 'theme');
        }

        // If no favicon found yet, check favicon settings in Moodle's Appearance/Logo settings.
        if (empty($favicon)) {
            $corefavicon = get_config('core_admin', 'favicon');
            if (!empty($corefavicon)) {
                $favicon = \moodle_url::make_pluginfile_url(
                    \context_system::instance()->id,
                    'core_admin',
                    'favicon',
                    '64x64/',
                    theme_get_revision(),
                    $corefavicon
                );
            }
        }

        if (empty($favicon)) {
            $parsedurl = parse_url($CFG->wwwroot);
            $favicon = $parsedurl['scheme'] . '://' . $parsedurl['host'] . '/favicon.ico';
        }

        if ($muccache && !empty($favicon)) {
            $favstr = ($favicon instanceof moodle_url) ? $favicon->out(false) : (string)$favicon;
            $muccache->set($ckey, $favstr);
        }
        $favicons[$companyid] = $favicon;
        return $favicon;
    }

    /**
     * Get the full logo URL.
     * Priority:
     * 1. Company-specific logo uploaded in Trema Settings (logo_c{id})
     * 2. Company-specific logo uploaded in IOMAD Dashboard -> Edit Company -> Appearance (companylogo / logo{id})
     * 3. Global logo uploaded in Trema Settings (logo)
     * 4. Moodle / IOMAD core parent::get_logo_url()
     *
     * @param int $maxwidth The maximum width, or null when the maximum width does not matter.
     * @param int $maxheight The maximum height, or null when the maximum height does not matter.
     * @return moodle_url|false
     */
    public function get_logo_url($maxwidth = null, $maxheight = 200) {
        $companyid = theme_trema_get_active_companyid();
        $rev = function_exists('theme_get_revision') ? (int)theme_get_revision() : 1;
        $wkey = (int)($maxwidth ?? 0);
        $hkey = (int)($maxheight ?? 200);
        $ckey = "renderer_logo_{$companyid}_{$wkey}_{$hkey}_{$rev}";
        $muccache = function_exists('theme_trema_get_image_muc_cache') ? theme_trema_get_image_muc_cache() : null;
        if ($muccache) {
            $hit = $muccache->get($ckey);
            if ($hit !== false) {
                return ($hit === '__none__') ? false : new moodle_url($hit);
            }
        }
        $resolved = $this->resolve_tenant_logo_url($companyid, $maxwidth, $maxheight);
        if ($muccache) {
            $val = ($resolved instanceof moodle_url) ? $resolved->out(false) : ($resolved ? (string)$resolved : '__none__');
            $muccache->set($ckey, $val);
        }
        return $resolved;
    }

    /**
     * Internal resolver for full logo URL across Trema and IOMAD storage areas.
     */
    protected function resolve_tenant_logo_url(int $companyid, $maxwidth = null, $maxheight = 200) {

        if ($companyid > 0) {
            // 1. Check company-specific logo or compactlogo in Trema settings (verified in mdl_files).
            $tremacomplogo = theme_trema_company_only_file_url('logo', 'logo', $companyid);
            if (!empty($tremacomplogo)) {
                return new moodle_url($tremacomplogo);
            }
            $tremacompcompact = theme_trema_company_only_file_url('compactlogo', 'compactlogo', $companyid);
            if (!empty($tremacompcompact)) {
                return new moodle_url($tremacompcompact);
            }

            // 2. Delegate to IOMAD's native core_renderer::get_logo_url() / get_compact_logo_url().
            $parentlogo = parent::get_logo_url($maxwidth, $maxheight);
            if (!empty($parentlogo)) {
                return $parentlogo;
            }
            $parentcompact = parent::get_compact_logo_url($maxwidth, $maxheight);
            if (!empty($parentcompact)) {
                return $parentcompact;
            }

            // 3. Direct file_storage lookup for IOMAD company logos (important when resolved via subdomain prior to login).
            $fs = get_file_storage();
            $context = \context_system::instance();
            foreach (["logo{$companyid}", "compactlogo{$companyid}"] as $corearea) {
                $files = $fs->get_area_files($context->id, 'core_admin', $corearea, 0, 'sortorder, filepath, filename', false);
                if (!empty($files)) {
                    $file = reset($files);
                    return moodle_url::make_pluginfile_url(
                        $context->id,
                        'core_admin',
                        $corearea,
                        '0x200/',
                        theme_get_revision(),
                        '/' . ltrim($file->get_filename(), '/')
                    );
                }
            }
            foreach (['companylogo', 'logo', 'companylogocompact', 'logocompact'] as $area) {
                foreach (['core_admin', 'theme_iomad', 'theme_iomadboost', 'local_iomad'] as $comp) {
                    $files = $fs->get_area_files($context->id, $comp, $area, $companyid, 'sortorder, filepath, filename', false);
                    if (!empty($files)) {
                        $file = reset($files);
                        return moodle_url::make_pluginfile_url(
                            $context->id,
                            $comp,
                            $area,
                            $companyid . '/',
                            theme_get_revision(),
                            '/' . ltrim($file->get_filename(), '/')
                        );
                    }
                }
            }
        }

        // 4. Check Global Trema logo (verified in mdl_files).
        $tremagloballogo = theme_trema_setting_file_url('logo', 'logo', $this->page->theme, 0);
        if (!empty($tremagloballogo)) {
            return new moodle_url($tremagloballogo);
        }

        // 5. Fallback to Moodle / IOMAD core get_logo_url().
        return parent::get_logo_url($maxwidth, $maxheight);
    }

    /**
     * Get the compact logo URL.
     * Priority:
     * 1. Company-specific compact logo (or full logo) uploaded in Trema Settings (compactlogo_c{id} / logo_c{id})
     * 2. IOMAD native parent::get_compact_logo_url() / parent::get_logo_url()
     * 3. Direct file_storage lookup for IOMAD company compactlogo / logo
     * 4. Global compact logo (or full logo) uploaded in Trema Settings
     *
     * @param int $maxwidth The maximum width, or null when the maximum width does not matter.
     * @param int $maxheight The maximum height, or null when the maximum height does not matter.
     * @return moodle_url|false
     */
    public function get_compact_logo_url($maxwidth = 300, $maxheight = 300) {
        $companyid = theme_trema_get_active_companyid();
        $rev = function_exists('theme_get_revision') ? (int)theme_get_revision() : 1;
        $wkey = (int)($maxwidth ?? 300);
        $hkey = (int)($maxheight ?? 300);
        $ckey = "renderer_compactlogo_{$companyid}_{$wkey}_{$hkey}_{$rev}";
        $muccache = function_exists('theme_trema_get_image_muc_cache') ? theme_trema_get_image_muc_cache() : null;
        if ($muccache) {
            $hit = $muccache->get($ckey);
            if ($hit !== false) {
                return ($hit === '__none__') ? false : new moodle_url($hit);
            }
        }
        $resolved = $this->resolve_tenant_compact_logo_url($companyid, $maxwidth, $maxheight);
        if ($muccache) {
            $val = ($resolved instanceof moodle_url) ? $resolved->out(false) : ($resolved ? (string)$resolved : '__none__');
            $muccache->set($ckey, $val);
        }
        return $resolved;
    }

    /**
     * Internal resolver for compact logo URL across Trema and IOMAD storage areas.
     */
    protected function resolve_tenant_compact_logo_url(int $companyid, $maxwidth = 300, $maxheight = 300) {

        if ($companyid > 0) {
            // 1. Check company-specific compact logo or full logo in Trema settings (verified in mdl_files).
            $tremacompcompact = theme_trema_company_only_file_url('compactlogo', 'compactlogo', $companyid);
            if (!empty($tremacompcompact)) {
                return new moodle_url($tremacompcompact);
            }
            $tremacomplogo = theme_trema_company_only_file_url('logo', 'logo', $companyid);
            if (!empty($tremacomplogo)) {
                return new moodle_url($tremacomplogo);
            }

            // 2. Delegate to IOMAD's native core_renderer::get_compact_logo_url() / get_logo_url().
            $parentcompact = parent::get_compact_logo_url($maxwidth, $maxheight);
            if (!empty($parentcompact)) {
                return $parentcompact;
            }
            $parentlogo = parent::get_logo_url($maxwidth, $maxheight);
            if (!empty($parentlogo)) {
                return $parentlogo;
            }

            // 3. Direct file_storage lookup for IOMAD company logos (with leading '/' on filename).
            $fs = get_file_storage();
            $context = \context_system::instance();
            foreach (["compactlogo{$companyid}", "logo{$companyid}"] as $corearea) {
                $files = $fs->get_area_files($context->id, 'core_admin', $corearea, 0, 'sortorder, filepath, filename', false);
                if (!empty($files)) {
                    $file = reset($files);
                    return moodle_url::make_pluginfile_url(
                        $context->id,
                        'core_admin',
                        $corearea,
                        '300x300/',
                        theme_get_revision(),
                        '/' . ltrim($file->get_filename(), '/')
                    );
                }
            }
            foreach (['companylogocompact', 'logocompact', 'companylogo', 'logo'] as $area) {
                foreach (['core_admin', 'theme_iomad', 'theme_iomadboost', 'local_iomad'] as $comp) {
                    $files = $fs->get_area_files($context->id, $comp, $area, $companyid, 'sortorder, filepath, filename', false);
                    if (!empty($files)) {
                        $file = reset($files);
                        return moodle_url::make_pluginfile_url(
                            $context->id,
                            $comp,
                            $area,
                            $companyid . '/',
                            theme_get_revision(),
                            '/' . ltrim($file->get_filename(), '/')
                        );
                    }
                }
            }
        }

        // 4. Check Global Trema compact logo or full logo (verified in mdl_files).
        $tremaglobalcompact = theme_trema_setting_file_url('compactlogo', 'compactlogo', $this->page->theme, 0);
        if (!empty($tremaglobalcompact)) {
            return new moodle_url($tremaglobalcompact);
        }
        $tremagloballogo = theme_trema_setting_file_url('logo', 'logo', $this->page->theme, 0);
        if (!empty($tremagloballogo)) {
            return new moodle_url($tremagloballogo);
        }

        // 5. Fallback to Moodle / IOMAD core get_compact_logo_url().
        return parent::get_compact_logo_url($maxwidth, $maxheight);
    }

    /**
     * Always show the compact logo when its defined.
     *
     * @return bool
     */
    public function should_display_navbar_logo() {
        $logo = $this->get_compact_logo_url();
        return !empty($logo);
    }

    /**
     * Renders the navbar button to control the display of the custom menu on smaller screens (ported from theme_iomad).
     *
     * @return string HTML fragment
     */
    public function navbar_button() {
        global $CFG;

        $custommenuitems = theme_trema_get_setting('custommenuitems', null, '');

        if (empty($CFG->custommenuitems) && $this->lang_menu() == '' && empty($custommenuitems)) {
            return '';
        }

        $iconbar = html_writer::tag('span', '', ['class' => 'icon-bar']);
        $button = html_writer::tag('a', $iconbar . "\n" . $iconbar . "\n" . $iconbar, [
            'class'       => 'btn btn-navbar',
            'data-toggle' => 'collapse',
            'data-target' => '.nav-collapse',
        ]);
        return $button;
    }

    /**
     * Return the frontpage settings menu.
     *
     * @return string HTML to display the main header.
     */
    public function frontpage_settings_menu() {
        $header = new stdClass();
        $header->settingsmenu = $this->context_header_settings_menu();
        return $this->render_from_template('theme_trema/frontpage_settings_menu', $header);
    }

    /**
     * Renders the lang menu
     *
     * @param bool $showlang Optional false = just the globe, or 'showlang' to force display globe + current language.
     * @return string Rendered language menu in HTML.
     */
    public function render_lang_menu($showlang = false) {
        $langs = get_string_manager()->get_list_of_translations();
        $haslangmenu = $this->lang_menu() != '';
        $menu = new custom_menu();

        if ($haslangmenu) {
            $currlang = current_language();
            if (isset($langs[$currlang])) {
                $currentlang = $langs[$currlang];
            } else {
                $currentlang = get_string('language');
            }

            if ($showlang) {
                $strlang = $currentlang;
            } else {
                $strlang = '';
            }
            $this->language = $menu->add($currentlang, new moodle_url('#'), $strlang, 10000);

            if (isset($langs[$currlang])) {
                $langs = [ $currlang => $langs[$currlang] ] + $langs;
            }
            foreach ($langs as $langtype => $langname) {
                $lang = str_replace('_', '-', $langtype);
                $this->language->add($langname, new moodle_url($this->page->has_set_url() ? $this->page->url : '/', ['lang' => $langtype]), $lang);
            }

            foreach ($menu->get_children() as $item) {
                $context = $item->export_for_template($this);
            }

            if (isset($context)) {
                global $CFG;
                $databs = $CFG->branch >= 500 ? 'bs-' : '';
                $context->databs = $databs;
                return $this->render_from_template('theme_trema/lang_menu', $context);
            }
        }
        return '';
    }

    /**
     * Renders the login form (with tenant-specific logo and sitename).
     *
     * @param \core_auth\output\login $form The renderable.
     * @return string
     */
    public function render_login(\core_auth\output\login $form) {
        global $CFG;

        $context = $form->export_for_template($this);

        if ($CFG->rememberusername == 0) {
            $context->cookieshelpiconformatted = $this->help_icon('cookiesenabledonlysession');
        } else {
            $context->cookieshelpiconformatted = $this->help_icon('cookiesenabled');
        }

        $context->rememberusername = $CFG->rememberusername == 2;
        $context->errorformatted = $this->error_text($context->error);
        $url = $this->get_logo_url();
        if ($url) {
            $url = $url->out(false);
        }
        $context->logourl = $url;
        $context->sitename = theme_trema_get_tenant_sitename(true);
        $context->loginpagecreatefirst = theme_trema_get_setting('loginpagecreatefirst');
        $context->hasinstructions = !empty($context->instructions) || !empty($context->cansignup);

        return $this->render_from_template('core/loginform', $context);
    }

    /**
     * Generates the standard HTML for the head section of the page, appending dynamic Multi-Tenant CSS.
     *
     * @return string The HTML content for the head section of the page.
     */
    public function standard_head_html() {
        global $CFG;

        $additionalhtmlhead = $CFG->additionalhtmlhead;

        if (!empty(theme_trema_get_setting('navfilter')) && strpos($CFG->additionalhtmlhead, '}') !== false) {
            $filtermanager = \filter_manager::instance();
            $CFG->additionalhtmlhead = $filtermanager->filter_string($CFG->additionalhtmlhead, \context_system::instance());
        }

        $output = parent::standard_head_html();
        $CFG->additionalhtmlhead = $additionalhtmlhead;

        // Append runtime Multi-Tenant CSS overrides so each company gets its own colors/fonts/banners without cache collisions.
        $output .= theme_trema_get_tenant_custom_css();

        return $output;
    }

    /**
     * Override the header method to ensure the page heading is always filtered using the system context.
     *
     * @return string
     */
    public function header(): string {
        global $SITE;

        if (!empty($this->page->course->id) && $this->page->course->id == $SITE->id) {
            $heading = $this->page->heading ?? theme_trema_get_tenant_sitename(true);
            $this->page->set_heading(\format_string($heading, true, ['context' => \context_system::instance()]));
        }
        return parent::header();
    }

    /**
     * Adds additional HTML at the top of the body for every page.
     *
     * @return string Additional HTML to be placed at the top of the body.
     */
    public function standard_top_of_body_html() {
        global $CFG;
        $additionalhtmltopofbody = $CFG->additionalhtmltopofbody;
        if (strpos($additionalhtmltopofbody, '}') !== false) {
            $CFG->additionalhtmltopofbody = \format_text(
                $CFG->additionalhtmltopofbody,
                FORMAT_HTML,
                ['noclean' => true, 'context' => $this->page->context]
            );
        }
        $output = parent::standard_top_of_body_html();
        $CFG->additionalhtmltopofbody = $additionalhtmltopofbody;
        return $output;
    }

    /**
     * Adds additional HTML at the end of the body for every page.
     *
     * @return string Additional HTML to be placed at the end of the body.
     */
    public function standard_end_of_body_html() {
        global $CFG;
        $additionalhtmlfooter = $CFG->additionalhtmlfooter;
        if (strpos($additionalhtmlfooter, '}') !== false) {
            $CFG->additionalhtmlfooter = \format_text(
                $CFG->additionalhtmlfooter,
                FORMAT_HTML,
                ['noclean' => true, 'context' => $this->page->context]
            );
        }
        $output = parent::standard_end_of_body_html();
        $CFG->additionalhtmlfooter = $additionalhtmlfooter;
        return $output;
    }

    /**
     * Returns the title to use on the page - processed through Moodle filters.
     *
     * @return string
     */
    public function page_title() {
        return \format_string($this->page->title, true, ['context' => \context_system::instance()]);
    }
}
