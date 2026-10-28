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
 * Lib file with IOMAD Multi-Tenant support.
 *
 * @package     theme_trema
 * @copyright   2019-2026 Trema - {@link https://trema.tech/}
 * @copyright   2023-2026 TNG Consulting Inc. - {@link https://www.tngconsulting.ca/}
 * @author      Rodrigo Mady
 * @author      Trevor Furtado
 * @author      Michael Milette
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/locallib.php');

/**
 * Get SCSS to prepend for the global base stylesheet.
 * Always compiles Global (companyid = 0) into disk cache so tenants never pollute the shared all.css;
 * tenant-specific overrides are injected dynamically in <head> via theme_trema_get_tenant_custom_css().
 *
 * @param theme_config $theme The theme config object.
 * @return string
 */
function theme_trema_get_pre_scss($theme) {
    global $CFG;

    $scss = '';
    $pluginsettings = theme_trema_get_plugin_settings(0);

    $defaultvarsfile = "$CFG->dirroot/theme/trema/scss/defaultvariables.scss";
    if (file_exists($defaultvarsfile)) {
        $scss .= file_get_contents($defaultvarsfile) . "\n";
    }

    // Emit safe !default values for all variables used across scss/trema/*.scss
    // so scssphp NEVER fails with "Undefined variable" even if Global settings haven't been saved yet.
    $scss .= '
$white: #ffffff !default;
$gray-100: #f8f9fa !default;
$gray-200: #e9ecef !default;
$gray-300: #dee2e6 !default;
$gray-400: #ced4da !default;
$gray-500: #adb5bd !default;
$gray-600: #868e96 !default;
$gray-700: #495057 !default;
$gray-800: #373a3c !default;
$gray-900: #212529 !default;
$black: #000000 !default;
$primary: #1c6ca3 !default;
$secondary: #343a40 !default;
$body-bg-color: #f1f1f1 !default;
$dropdown-bg-color: #f1f1f1 !default;
$header-bg-color: #ffffff !default;
$loginbtn-bg-color: #1c6ca3 !default;
$drawer-bg-color: #e9ecef !default;
$footer-bg-color: #343a40 !default;
$footer-opacity: 1 !default;
$custom-menu-alignment: left !default;
$links-decoration: underline !default;
$banner-height: 100vh !default;
$banner-text-align: center !default;
$banner-font-color: #ffffff !default;
$banner-title-font-family: Lato, sans-serif !default;
$banner-title-transform: none !default;
$banner-title-spacing: normal !default;
$text-transform: none !default;
$login-box-align: center !default;
$softness: 0.4rem !default;
$course-content-maxwidth: 1280px !default;
$coursecards-bg-color: #ffffff !default;
$highlight-background-color: #ffee28 !default;
$editmodeon-bg-color: #343a40 !default;
';

    $configurable = [
        // Target SCSS variable name => Trema theme setting.
        'primary' => 'primarycolor',
        'secondary' => 'secondarycolor',
        'body-bg-color' => 'bodybackgroundcolor',
        'body-font-family' => 'sitefont',
        'h1-font-family' => 'h1font',
        'hx-font-family' => 'hxfont',
        'text-transform' => 'texttransform',
        'banner-title-font-family' => 'bannertitlesfont',
        'banner-title-transform' => 'bannertitletransform',
        'banner-title-spacing' => 'bannertitlespacing',
        'banner-text-align' => 'frontpagebannercontentalign',
        'login-box-align' => 'loginboxcontentalign',
        'banner-height' => 'bannerheight',
        'custom-menu-alignment' => 'custommenualignment',
        'links-decoration' => 'linkdecoration',
        'dropdown-bg-color' => 'bodybackgroundcolor',
        'footer-opacity' => 'footeropacity',
    ];

    foreach ($configurable as $scssvar => $themesetting) {
        $value = null;
        if (isset($pluginsettings->{$themesetting}) && trim((string)$pluginsettings->{$themesetting}) !== '') {
            $value = trim((string)$pluginsettings->{$themesetting});
        } else if (isset($theme->settings->{$themesetting}) && trim((string)$theme->settings->{$themesetting}) !== '') {
            $value = trim((string)$theme->settings->{$themesetting});
        }
        if ($value !== null && $value !== '') {
            $scss .= '$' . $scssvar . ': ' . $value . ";\n";
        }
    }

    // Background color of page header (never emit an empty value).
    $headerbgcolor = !empty($pluginsettings->headerbgcolor) ? trim($pluginsettings->headerbgcolor) : '$white';
    if (strpos('$#', substr($headerbgcolor, 0, 1)) === false) {
        $headerbgcolor = !empty($pluginsettings->{$headerbgcolor}) ? trim($pluginsettings->{$headerbgcolor}) : '#ffffff';
    }
    $scss .= '$header-bg-color: ' . ($headerbgcolor ?: '#ffffff') . ";\n";

    // Background color of Log In button in page header.
    $loginbtnbgcolor = !empty($pluginsettings->loginbtnbgcolor) ? trim($pluginsettings->loginbtnbgcolor) : 'primarycolor';
    if (strpos('$#', substr($loginbtnbgcolor, 0, 1)) === false) {
        $loginbtnbgcolor = !empty($pluginsettings->{$loginbtnbgcolor}) ? trim($pluginsettings->{$loginbtnbgcolor}) : '#1c6ca3';
    }
    $scss .= '$loginbtn-bg-color: ' . ($loginbtnbgcolor ?: '#1c6ca3') . ";\n";

    // Background color of drawers.
    $drawerbgcolor = !empty($pluginsettings->drawerbgcolor) ? trim($pluginsettings->drawerbgcolor) : '$gray-200';
    if (strpos('$#', substr($drawerbgcolor, 0, 1)) === false) {
        $drawerbgcolor = !empty($pluginsettings->{$drawerbgcolor}) ? trim($pluginsettings->{$drawerbgcolor}) : '#e9ecef';
    }
    $scss .= '$drawer-bg-color: ' . ($drawerbgcolor ?: '#e9ecef') . ";\n";

    // Background color of footer.
    $footerbgcolor = !empty($pluginsettings->footerbgcolor) ? trim($pluginsettings->footerbgcolor) : '$gray-800';
    if (strpos('$#', substr($footerbgcolor, 0, 1)) === false) {
        $footerbgcolor = !empty($pluginsettings->{$footerbgcolor}) ? trim($pluginsettings->{$footerbgcolor}) : '#343a40';
    }
    $scss .= '$footer-bg-color: ' . ($footerbgcolor ?: '#343a40') . ";\n";

    // Fonts.
    $fonts = [
        'Lato, sans-serif' => 'Lato',
        'Arial, Helvetica, sans-serif' => 'Arial',
        'Verdana, Tahoma, sans-serif' => 'Verdana',
        '"Times New Roman", Times, serif' => 'TimesNewRoman',
        'Georgia, serif' => 'Georgia',
        '"Courier New", Courier, monospace' => 'CourierNew',
        'Alegreya, serif' => 'Alegreya',
        '"CrimsonText", serif' => 'CrimsonText',
        '"EBGaramond", sans-serif' => 'EBGaramond',
        'Montserrat, sans-serif' => 'Montserrat',
        '"NotoSans", sans-serif' => 'NotoSans',
        '"OpenSans", sans-serif' => 'OpenSans',
        '"PlayfairDisplay", serif' => 'PlayfairDisplay',
        'Poppins, sans-serif' => 'Poppins',
        'Roboto, Arial, Helvetica, sans-serif' => 'Roboto',
    ];

    $scss .= '$bodyfontfile: "' . ($fonts[$pluginsettings->sitefont ?? ''] ?? 'Lato') . '";' . PHP_EOL;
    $scss .= '$bannertitlesfontfile: "' . ($fonts[$pluginsettings->bannertitlesfont ?? ''] ?? 'Lato') . '";' . PHP_EOL;
    $scss .= '$h1fontfile: "' . ($fonts[$pluginsettings->h1font ?? ''] ?? 'Lato') . '";' . PHP_EOL;
    $scss .= '$hxfontfile: "' . ($fonts[$pluginsettings->hxfont ?? ''] ?? 'Lato') . '";' . PHP_EOL;

    // Show/hide User profile fields & global visibility toggles (only hide when explicitly set to '0').
    $fields = [];
    $fields['showprofileemaildisplay'] = '#fitem_id_maildisplay';
    if ($CFG->branch < 502 && isset($pluginsettings->showmoodlenetprofile) && (string)$pluginsettings->showmoodlenetprofile === '0') {
        $fields['showmoodlenetprofile'] = '#fitem_id_moodlenetprofile';
    }
    $fields['showprofilecity'] = '#fitem_id_city';
    $fields['showprofilecountry'] = '#fitem_id_country';
    $fields['showprofiletimezone'] = '#fitem_id_timezone';

    $fields['showprofiledescription'] = 'body#page-user-editadvanced #fitem_id_description_editor,';
    $fields['showprofiledescription'] .= 'body#page-admin-user-editadvanced #fitem_id_description_editor,';
    $fields['showprofiledescription'] .= 'body#page-user-edit #fitem_id_description_editor,';
    $fields['showprofiledescription'] .= 'body#page-login-signup #fitem_id_description_editor';

    $fields['showprofilepictureofuser'] = '#id_moodle_picture';
    $fields['showprofileadditionalnames'] = '#id_moodle_additional_names';
    $fields['showprofilefirstnamephonetic'] = '#fitem_id_firstnamephonetic';
    $fields['showprofilelastnamephonetic'] = '#fitem_id_lastnamephonetic';
    $fields['showprofilemiddlename'] = '#fitem_id_middlename';
    $fields['showprofilealternatename'] = '#fitem_id_alternatename';
    $fields['showprofileinterests'] = '#id_moodle_interests';
    $fields['showprofileoptional'] = '#id_moodle_optional';

    if ($CFG->branch < 502) {
        $fields['showprofilemoodlenetprofile'] = '#fitem_id_moodlenetprofile';
    }
    $fields['showprofileidnumber'] = 'body#page-user-editadvanced #fitem_id_idnumber,';
    $fields['showprofileidnumber'] .= 'body#page-admin-user-editadvanced #fitem_id_idnumber,';
    $fields['showprofileidnumber'] .= 'body#page-user-edit #fitem_id_idnumber,';
    $fields['showprofileidnumber'] .= 'body#page-login-signup #fitem_id_idnumber';
    $fields['showprofileinstitution'] = '#fitem_id_institution';
    $fields['showprofiledepartment'] = '#fitem_id_department';
    $fields['showprofilephone1'] = '#fitem_id_phone1';
    $fields['showprofilephone2'] = '#fitem_id_phone2';
    $fields['showprofileaddress'] = '#fitem_id_address';

    $fields['showactivityicons'] = '.page-header-image,.activityiconcontainer.courseicon';
    $fields['loginshowloginform'] = '#login, .loginform .login-form, .login-form-forgotpassword form-group';
    $fields['showumlogoutlink'] = '#carousel-item-main a:last-of-type, #carousel-item-main .dropdown-divider:last-of-type';
    $fields['showfrontpagelinkstopages'] = '#page-site-index:not(.editing) #page-content .modtype_page';
    $fields['showbranding'] = '.sitelink,.footer-section.p-3:not(.border-bottom)';

    $customscss = '';
    if (!empty($CFG->autologinguests) && !empty($CFG->guestloginbutton)) {
        $customscss .= '#guestlogin,';
    }

    foreach ($fields as $setting => $field) {
        if (isset($pluginsettings->$setting) && (string)$pluginsettings->$setting === '0') {
            $customscss .= $field . ',' . PHP_EOL;
        }
    }
    if (!empty($customscss)) {
        $scss .= $customscss . 'displaynone {display: none;}';
    }

    // Login page background image (Global base).
    $backgroundimageurl = theme_trema_setting_file_url('loginbackgroundimage', 'loginbackgroundimage', $theme, 0);
    if (($pluginsettings->loginpagestyle ?? 'none') == 'image' && !empty($backgroundimageurl)) {
        $scss .= "\$login-backgroundimage: '$backgroundimageurl';\n";
    } else {
        $scss .= "\$login-backgroundimage: '[[pix:theme|login/background]]';\n";
    }

    if (($pluginsettings->loginpagestyle ?? 'none') !== 'image') {
        $scss .= "body.pagelayout-login #page-wrapper { background-image: none; }\n";
    }

    if ($CFG->branch >= 502) {
        $scss .= '
        $spacer: 1rem !default;
            body#page-login-index {
                .loginform {
                    h1.login-heading {
                        margin: ($spacer * 2) 0;
                    }
                    .login-divider {
                        margin: ($spacer * 0.5) 0;
                    }
                }
            }
            .pagelayout-login .login-container {
                background-color: #fff;
                padding: 3rem;
                box-shadow: 0 .5rem 1rem rgb(0 0 0 / .15);
                margin-bottom: 2rem;
            }
        ';
    }

    $scss .= '$softness: ' . ((!isset($pluginsettings->softness) || !empty($pluginsettings->softness)) ? '.4rem' : '0') . ";\n";

    if (!empty($pluginsettings->scsspre)) {
        $scss .= $pluginsettings->scsspre;
    }

    return $scss;
}

/**
 * Load the main SCSS and the frontpage banner (Global base).
 *
 * @param theme_config $theme The theme config object.
 * @return string
 */
function theme_trema_get_main_scss_content($theme) {
    global $CFG, $SITE;

    $scss = '';
    $pluginsettings = theme_trema_get_plugin_settings(0);

    $filename = !empty($pluginsettings->preset) ? basename($pluginsettings->preset) : 'default.scss';
    $presetpath = "$CFG->dirroot/theme/trema/scss/preset/{$filename}";
    if (file_exists($presetpath)) {
        $scss .= file_get_contents($presetpath);
    } else if (file_exists("$CFG->dirroot/theme/trema/scss/preset/default.scss")) {
        $scss .= file_get_contents("$CFG->dirroot/theme/trema/scss/preset/default.scss");
    } else if (file_exists("$CFG->dirroot/theme/boost/scss/preset/default.scss")) {
        $scss .= file_get_contents("$CFG->dirroot/theme/boost/scss/preset/default.scss");
    }

    if (isset($pluginsettings->enabletremalines) && (string)$pluginsettings->enabletremalines === '0') {
        $scss .= "%border-frequency { &:before, &:after { content: none !important;}}";
    }

    if (isset($pluginsettings->frontpageenabledarkoverlay) && (string)$pluginsettings->frontpageenabledarkoverlay === '0') {
        $darkoverlay = "";
    } else {
        $darkoverlay = "url([[pix:theme|frontpage/overlay]]),";
    }

    if ($frontpagebannerurl = theme_trema_setting_file_url('frontpagebanner', 'frontpagebanner', $theme, 0)) {
        $scss .= "#frontpage-banner {background-image: $darkoverlay url('$frontpagebannerurl');}";
    } else {
        $scss .= "#frontpage-banner {background-image: $darkoverlay url([[pix:theme|frontpage/banner]]);}";
    }

    $sitenamefontscale = strlen($SITE->shortname ?? '') < 36 ? '1' : '0.75';
    $scss .= '$sitename-font-scale: ' . $sitenamefontscale . ";\n";

    return $scss;
}

/**
 * Inject additional SCSS for images (Global base).
 *
 * @param theme_config $theme Theme config object.
 * @return string
 */
function theme_trema_get_extra_scss($theme) {
    $scss = '';
    $pluginsettings = theme_trema_get_plugin_settings(0);

    return !empty($pluginsettings->scss) ? "{$pluginsettings->scss}\n{$scss}" : $scss;
}

/**
 * Serves any files associated with the theme settings (including tenant-specific fileareas).
 *
 * @param stdClass $course
 * @param stdClass $cm
 * @param context $context
 * @param string $filearea
 * @param array $args
 * @param bool $forcedownload
 * @param array $options
 * @return bool
 */
function theme_trema_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    if ($context->contextlevel == CONTEXT_SYSTEM) {
        $theme = theme_config::load('trema');
        if (!array_key_exists('cacheability', $options)) {
            $options['cacheability'] = 'public';
        }
        return $theme->setting_file_serve($filearea, $args, $forcedownload, $options);
    } else {
        send_file_not_found();
    }
}

/**
 * MoodlePage init for adding classes to body tag (including active IOMAD company classes
 * and client-side enhancers for IOMAD Edit Company -> Appearance and Trema Multi-Tenant Settings).
 *
 * @param moodle_page $page
 * @throws coding_exception
 */
function theme_trema_page_init(moodle_page $page) {
    global $COURSE, $USER, $CFG;

    // Add admin classes.
    $page->add_body_class(is_siteadmin() ? "is_siteadmin" : "not_siteadmin");

    // Add active IOMAD company classes to body tag for multi-tenant CSS scoping.
    $companyid = theme_trema_get_active_companyid();
    if ($companyid > 0) {
        $page->add_body_class("company-id-{$companyid}");
        $company = theme_trema_get_company_record($companyid);
        if ($company && !empty($company->shortname)) {
            $clean = preg_replace('/[^a-zA-Z0-9_-]/', '', strtolower($company->shortname));
            if (!empty($clean)) {
                $page->add_body_class("company-{$clean}");
            }
        }
    } else {
        $page->add_body_class("company-global");
    }

    // Add module idnumber class.
    if (in_array($page->pagelayout, ['incourse']) && !empty($page->cm->idnumber)) {
        $page->add_body_class("idnumber-{$page->cm->idnumber}");
    }

    // Add role classes.
    if (in_array($page->pagelayout, ['course', 'incourse'])) {
        $context = context_course::instance($COURSE->id);
        if (user_has_role_assignment($USER->id, 5, $context->id)) {
            $page->add_body_class('is_student');
        }
        if (user_has_role_assignment($USER->id, 4, $context->id)) {
            $page->add_body_class('is_teacher');
        }
        if (user_has_role_assignment($USER->id, 3, $context->id)) {
            $page->add_body_class('is_editingteacher');
        }
    }

    // Show Login form if URL parameters include saml=off or auth=manual.
    if (optional_param('saml', '', PARAM_ALPHA) === 'off' || optional_param('auth', '', PARAM_ALPHA) === 'manual') {
        $page->add_body_class('local-login');
    }

    // Load course style by shortname from: /style/course/$shortname.css.
    if ($COURSE->id > 1) {
        $shortname   = explode('|', $COURSE->shortname);
        $shortname   = trim($shortname[0]);
        $coursestyle = "/style/course/{$shortname}.css";
        if (file_exists($page->theme->dir . $coursestyle)) {
            $page->requires->css(new moodle_url("/theme/trema{$coursestyle}"));
        }
    }

    // Safely resolve page path (prevents Fatal Error during AJAX calls like repository_ajax.php where $page->url is null).
    $pagepath = '';
    if ($page->has_set_url()) {
        $pagepath = $page->url->get_path();
    } else if (!empty($_SERVER['SCRIPT_NAME'])) {
        $pagepath = $_SERVER['SCRIPT_NAME'];
    }

    // 1. Enhance IOMAD Edit Company -> Appearance (#id_theme) so selecting 'trema' (or default)
    // is 100% recognized and never hides IOMAD Appearance fields (logo, colors, customcss).
    if (strpos($pagepath, '/blocks/iomad_company_admin/company_edit_form.php') !== false) {
        $tremasettingsurl = (new moodle_url('/admin/settings.php', [
            'section' => 'themesettingtrema',
            'trema_companyid' => $companyid,
        ]))->out(false);
        $js = "
        (function() {
            function unlockTremaAppearance() {
                var themeSelect = document.getElementById('id_theme');
                if (!themeSelect) { return; }
                var val = themeSelect.value;
                if (val === 'trema' || val === '') {
                    var container = themeSelect.closest('fieldset') || document;
                    var hiddenItems = container.querySelectorAll('.fitem, [data-groupname]');
                    hiddenItems.forEach(function(el) {
                        if (el.id === 'fitem_id_theme') { return; }
                        el.removeAttribute('hidden');
                        el.classList.remove('hidden', 'd-none');
                        if (el.style && el.style.display === 'none') {
                            el.style.display = '';
                        }
                        var inputs = el.querySelectorAll('input, select, textarea, button');
                        inputs.forEach(function(inp) { inp.removeAttribute('disabled'); });
                    });
                }
                if (!document.getElementById('trema-iomad-edit-link')) {
                    var wrap = document.createElement('div');
                    wrap.id = 'trema-iomad-edit-link';
                    wrap.className = 'mt-2';
                    wrap.innerHTML = '<a href=\"" . addslashes($tremasettingsurl) . "\" class=\"btn btn-sm btn-outline-primary\">' +
                        '<i class=\"fa fa-paint-brush mr-1 me-1\"></i> Configurar banner, tarjetas y pie de página de Trema para esta compañía</a>';
                    themeSelect.parentNode.appendChild(wrap);
                }
            }
            document.addEventListener('DOMContentLoaded', function() {
                unlockTremaAppearance();
                setTimeout(unlockTremaAppearance, 150);
                setTimeout(unlockTremaAppearance, 500);
                var themeSelect = document.getElementById('id_theme');
                if (themeSelect) {
                    themeSelect.addEventListener('change', function() {
                        setTimeout(unlockTremaAppearance, 10);
                        setTimeout(unlockTremaAppearance, 100);
                    });
                    var fs = themeSelect.closest('fieldset');
                    if (fs && window.MutationObserver) {
                        var obs = new MutationObserver(function() {
                            if (themeSelect.value === 'trema' || themeSelect.value === '') {
                                unlockTremaAppearance();
                            }
                        });
                        obs.observe(fs, { attributes: true, subtree: true, attributeFilter: ['hidden', 'class', 'style'] });
                    }
                }
            });
            unlockTremaAppearance();
        })();
        ";
        $page->requires->js_amd_inline($js);
    }

    // 2. Enhance Trema Admin Settings page:
    // - Strips &trema_companyid=X from browser URL via history.replaceState so IOMAD's top-right switcher never conflicts.
    // - Binds the scalable .trema-tenant-select dropdown to navigate while preserving the active tab hash.
    // - Injects a hidden trema_companyid input into #adminsettings so saving always targets the active entity.
    if (strpos($pagepath, '/admin/settings.php') !== false && optional_param('section', '', PARAM_ALPHANUMEXT) === 'themesettingtrema') {
        $editingcid = theme_trema_get_editing_companyid();
        $js = "
        (function() {
            function getActiveTabHash() {
                var activePane = document.querySelector('.tab-pane.active[id]');
                if (activePane && activePane.id) {
                    return '#' + activePane.id;
                }
                return window.location.hash || '';
            }
            // Strip trema_companyid and trema_reset_company from browser URL after server-side session update
            // so subsequent clicks on IOMAD's top-right company switcher are never overridden by stale URL params.
            if (window.history && window.history.replaceState && window.location.search.indexOf('trema_') !== -1) {
                try {
                    var cleanUrl = new URL(window.location.href);
                    cleanUrl.searchParams.delete('trema_companyid');
                    cleanUrl.searchParams.delete('trema_reset_company');
                    cleanUrl.searchParams.delete('sesskey');
                    window.history.replaceState({}, document.title, cleanUrl.toString());
                } catch (e) {}
            }
            // Purge stale TinyMCE autosave drafts for theme_trema settings so TinyMCE never overwrites defaultfooter or defaultfrontpagebody with an old blank draft.
            try {
                [window.localStorage, window.sessionStorage].forEach(function(store) {
                    if (!store) { return; }
                    for (var i = store.length - 1; i >= 0; i--) {
                        var k = store.key(i);
                        if (k && (k.indexOf('tiny_autosave') !== -1 || k.indexOf('theme_trema') !== -1)) {
                            store.removeItem(k);
                        }
                    }
                });
            } catch (e) {}
            function enhanceTenantSelector() {
                var form = document.getElementById('adminsettings');
                if (form) {
                    var hid = form.querySelector('input[name=\"trema_companyid\"]');
                    if (!hid) {
                        hid = document.createElement('input');
                        hid.type = 'hidden';
                        hid.name = 'trema_companyid';
                        form.appendChild(hid);
                    }
                    hid.value = '" . (int)$editingcid . "';
                }
                var selects = document.querySelectorAll('select.trema-tenant-select');
                selects.forEach(function(sel) {
                    if (!sel.dataset.bound) {
                        sel.dataset.bound = '1';
                        sel.addEventListener('change', function() {
                            if (this.value) {
                                window.location.href = this.value + getActiveTabHash();
                            }
                        });
                    }
                });
                var jumpBtns = document.querySelectorAll('a.trema-global-jump-btn');
                jumpBtns.forEach(function(btn) {
                    if (!btn.dataset.bound) {
                        btn.dataset.bound = '1';
                        btn.addEventListener('click', function(e) {
                            var h = getActiveTabHash();
                            if (h) {
                                e.preventDefault();
                                window.location.href = btn.getAttribute('href') + h;
                            }
                        });
                    }
                });
            }
            document.addEventListener('DOMContentLoaded', enhanceTenantSelector);
            setTimeout(enhanceTenantSelector, 100);
        })();
        ";
        $page->requires->js_amd_inline($js);
    }

    // Load profilefields JS if the setting is enabled.
    $enforcerequiredprofilefields = theme_trema_get_setting('enforcerequiredprofilefields', $companyid, false);
    if (!empty($enforcerequiredprofilefields)) {
        $isusercreationpage = (strpos($pagepath, '/user/editadvanced.php') !== false && $page->has_set_url() && $page->url->get_param('id') == -1);

        $profilefieldsdata = theme_trema_get_profilefields_data();
        if ($isusercreationpage && !empty($profilefieldsdata)) {
            $page->requires->js_call_amd('theme_trema/profilefields', 'init', $profilefieldsdata);
        }
    }
}

/**
 * Get profile fields data for the user creation page.
 *
 * @return array|null Array containing required profile fields
 */
function theme_trema_get_profilefields_data(): ?array {
    global $DB, $CFG;
    require_once("{$CFG->dirroot}/user/profile/lib.php");

    $requiredfields = [];
    $profilefields = $DB->get_records('user_info_field');

    foreach ($profilefields as $field) {
        if ($field->required) {
            $requiredfields[] = $field->shortname;
        }
    }

    return !empty($requiredfields) ? ['requiredfields' => $requiredfields] : null;
}
