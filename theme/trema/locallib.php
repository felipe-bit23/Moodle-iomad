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
 * Local library and Multi-Tenant (IOMAD) engine for theme_trema.
 *
 * @package     theme_trema
 * @copyright   2022-2026 Trema - {@link https://trema.tech/}
 * @copyright   2023-2026 TNG Consulting Inc. - {@link https://www.tngconsulting.ca/}
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if (class_exists('admin_setting_heading') && !class_exists('theme_trema_admin_setting_html')) {
    /**
     * Custom admin setting class that outputs raw HTML banner content directly
     * without passing through Moodle's FORMAT_MARKDOWN parser (which turns indented HTML into <pre><code>
     * and strips <select> elements).
     */
    class theme_trema_admin_setting_html extends admin_setting_heading {
        /**
         * Returns raw HTML string for the tenant selector bar.
         *
         * @param mixed $data
         * @param string $query
         * @return string
         */
        public function output_html($data, $query = '') {
            return (string)$this->description;
        }
    }
}

/**
 * Checks whether the IOMAD company table exists in the database.
 * Does not cache false if $DB is not yet initialized (e.g. early config stage).
 *
 * @return bool
 */
function theme_trema_has_iomad(): bool {
    global $DB;
    static $hasiomad = null;
    if ($hasiomad !== null) {
        return $hasiomad;
    }
    if (empty($DB)) {
        return false;
    }
    try {
        $hasiomad = $DB->get_manager()->table_exists('company');
    } catch (\Throwable $e) {
        return false;
    }
    return $hasiomad;
}

/**
 * Returns all active (non-suspended) IOMAD companies ordered by name.
 *
 * @return array
 */
function theme_trema_get_all_companies(): array {
    global $DB;
    static $companies = null;
    if ($companies !== null) {
        return $companies;
    }
    if (!theme_trema_has_iomad()) {
        return [];
    }
    try {
        $companies = $DB->get_records('company', ['suspended' => 0], 'name ASC');
    } catch (\Throwable $e) {
        return [];
    }
    return $companies;
}

/**
 * Returns a single company record by ID.
 *
 * @param int $companyid
 * @return stdClass|null
 */
function theme_trema_get_company_record(int $companyid = 0): ?stdClass {
    if ($companyid <= 0) {
        return null;
    }
    $companies = theme_trema_get_all_companies();
    return $companies[$companyid] ?? null;
}

/**
 * Automatic self-healing of global defaults, accidental tenant overrides, and IOMAD theme flags.
 *
 * @param bool $force
 * @return void
 */
function theme_trema_auto_heal_config(bool $force = false): void {
    global $CFG;
    static $ran = false;

    if ($ran && !$force) {
        return;
    }
    $ran = true;

    try {
        $raw = get_config('theme_trema');
        if (!is_object($raw)) {
            $raw = new stdClass();
        }

        $companies = theme_trema_get_all_companies();

        // Canonical factory defaults for every non-file setting across all 9 tabs.
        // In Moodle's admin/settings.php, admin_settingpage::output_html() calls $setting->get_setting()
        // and does NOT fall back to get_defaultsetting() if a key is missing (NULL) in mdl_config_plugins.
        // Therefore, every setting for Global (0) and every company (_cX) MUST always exist in mdl_config_plugins
        // and must NEVER be deleted via unset_config().
        $canonicaldefaults = [
            // Colors & Fonts
            'primarycolor' => '#1c6ca3',
            'secondarycolor' => '#343a40',
            'bodybackgroundcolor' => '#f1f1f1',
            'headerbgcolor' => '$white',
            'loginbtnbgcolor' => 'primarycolor',
            'drawerbgcolor' => '$gray-200',
            'footerbgcolor' => '$gray-800',
            'sitefont' => 'Lato, sans-serif',
            'bannertitlesfont' => 'Lato, sans-serif',
            'h1font' => 'Lato, sans-serif',
            'hxfont' => 'Lato, sans-serif',
            'texttransform' => 'none',
            'bannertitletransform' => 'none',
            'bannertitlespacing' => 'normal',
            // General
            'unaddableblocks' => 'navigation,settings,course_list,section_links',
            'linkdecoration' => 'underline',
            'navfilter' => '1',
            'custommenualignment' => 'left',
            'showumlogoutlink' => '1',
            'enableadmindashboard' => '1',
            'dashboardbox1' => 'diskusage',
            'dashboardbox2' => 'courses',
            'dashboardbox3' => 'enrolments',
            'dashboardbox4' => 'security',
            'enabletremalines' => '1',
            'softness' => '1',
            // Frontpage Banner & Content
            'numberofimages' => '1',
            'frontpageenabledarkoverlay' => '1',
            'frontpagedraweropen' => '0',
            'frontpagebannercontentalign' => 'center',
            'bannerheight' => '100vh',
            'frontpagetitle' => 'Banner title',
            'frontpagesubtitle' => 'This is a banner subtitle, with multiple lines of text.',
            'frontpagebuttontext' => 'Learn more',
            'frontpagebuttonhref' => '#topofcontent',
            'frontpagebuttonclass' => 'btn-primary',
            'showfrontpagelinkstopages' => '1',
            // Frontpage Cards
            'frontpageenablecards' => '1',
            'frontpagecardstitle' => 'Card title',
            'frontpagecardssubtitle' => 'Card subtitle',
            'numberofcards' => '4',
            'cardicon1' => 'fa-paper-plane',
            'cardiconcolor1' => '#000000',
            'cardtitle1' => 'Card Title 1',
            'cardsubtitle1' => 'The description of this card goes here. Several lines of text can be placed in this space.',
            'cardlink1' => '',
            'cardicon2' => 'fa-paper-plane',
            'cardiconcolor2' => '#000000',
            'cardtitle2' => 'Card Title 2',
            'cardsubtitle2' => 'The description of this card goes here. Several lines of text can be placed in this space.',
            'cardlink2' => '',
            'cardicon3' => 'fa-paper-plane',
            'cardiconcolor3' => '#000000',
            'cardtitle3' => 'Card Title 3',
            'cardsubtitle3' => 'The description of this card goes here. Several lines of text can be placed in this space.',
            'cardlink3' => '',
            'cardicon4' => 'fa-paper-plane',
            'cardiconcolor4' => '#000000',
            'cardtitle4' => 'Card Title 4',
            'cardsubtitle4' => 'The description of this card goes here. Several lines of text can be placed in this space.',
            'cardlink4' => '',
            'cardicon5' => 'fa-paper-plane',
            'cardiconcolor5' => '#000000',
            'cardtitle5' => 'Card Title 5',
            'cardsubtitle5' => 'The description of this card goes here. Several lines of text can be placed in this space.',
            'cardlink5' => '',
            'cardicon6' => 'fa-paper-plane',
            'cardiconcolor6' => '#000000',
            'cardtitle6' => 'Card Title 6',
            'cardsubtitle6' => 'The description of this card goes here. Several lines of text can be placed in this space.',
            'cardlink6' => '',
            // Footer
            'enabletremafooter' => '1',
            'footeropacity' => '1',
            'defaultfooter' => '<div class="row">
	<div class="col-md-8">
		<h3 class="-align-center">Trema Solutions in Technology</h3>

		<p>Our mission is to create solutions in technology that boost the teaching and learning experience.</p>
	</div>
	<div class="col-md-4">
		<h3>Contact Us</h3>
		<ul class="labeled-icons">
			<li>
                <span class="fa fa-globe fa-2x"></span>
                <a href="https://trema.tech/" target="_blank" style="cursor: pointer;">
                    <p>https://trema.tech/</p>
                </a>
            </li>
			<li>
                <span class="fa fa-github fa-2x"></span>
                <a href="https://github.com/trema-tech/" target="_blank" style="cursor: pointer;">
                    <p>https://github.com/trema-tech/</p>
                </a>
            </li>
		</ul>
	</div>
</div>',
            'enablefooterinfo' => '1',
            'showbranding' => '1',
            // Course & Courselist
            'cardcontacts' => '1',
            'showcategories' => '0',
            'summarytype' => 'modal',
            'showehiddencategorycourses' => '1',
            'courseenrolmentpageformat' => 'fullwidth',
            'shownactivitynavigation' => '0',
            'showactivityicons' => '1',
            // Login & Profile & Advanced
            'loginpagestyle' => 'none',
            'loginboxcontentalign' => 'center',
            'loginpagecreatefirst' => '0',
            'loginshowloginform' => '1',
            'enforcerequiredprofilefields' => '0',
            'preset' => 'default.scss',
        ];

        // Keys that should be enabled ('1') by default and healed if accidentally zeroed out before v11.
        $defaultenabledcheckboxes = [
            'frontpageenablecards', 'enabletremafooter', 'enablefooterinfo', 'showbranding',
            'showactivityicons', 'enabletremalines', 'softness', 'showfrontpagelinkstopages',
            'showumlogoutlink', 'loginshowloginform', 'cardcontacts', 'showehiddencategorycourses',
            'enableadmindashboard', 'navfilter',
        ];

        $healedv11 = get_config('theme_trema', 'multitenant_v11_healed');
        if (empty($healedv11) || $force) {
            set_config('allowcompanythemes', 1);
            set_config('allowcoursethemes', 1);
            set_config('allowcategorythemes', 1);
            set_config('allowcohortthemes', 1);
            set_config('allowuserthemes', 1);

            // Restore visibility checkboxes that were accidentally zeroed out by earlier NULL form saves.
            foreach ($defaultenabledcheckboxes as $cbk) {
                set_config($cbk, '1', 'theme_trema');
                $raw->$cbk = '1';
                foreach ($companies as $comp) {
                    $cid = (int)$comp->id;
                    $ck = "{$cbk}_c{$cid}";
                    set_config($ck, '1', 'theme_trema');
                    $raw->$ck = '1';
                }
            }

            // Heal accidental 0 values on numberofimages, numberofcards, footeropacity, and Alegreya font.
            foreach ($companies as $comp) {
                $cid = (int)$comp->id;
                $sfx = "_c{$cid}";
                if (!isset($raw->{"numberofimages{$sfx}"}) || (int)$raw->{"numberofimages{$sfx}"} < 1) {
                    set_config("numberofimages{$sfx}", '1', 'theme_trema');
                    $raw->{"numberofimages{$sfx}"} = '1';
                }
                if (!isset($raw->{"numberofcards{$sfx}"}) || (int)$raw->{"numberofcards{$sfx}"} < 2) {
                    set_config("numberofcards{$sfx}", '4', 'theme_trema');
                    $raw->{"numberofcards{$sfx}"} = '4';
                }
                if (!isset($raw->{"footeropacity{$sfx}"}) || (string)$raw->{"footeropacity{$sfx}"} === '0' || trim((string)$raw->{"footeropacity{$sfx}"}) === '') {
                    set_config("footeropacity{$sfx}", '1', 'theme_trema');
                    $raw->{"footeropacity{$sfx}"} = '1';
                }
                foreach (['sitefont', 'bannertitlesfont', 'h1font', 'hxfont'] as $fontkey) {
                    if (isset($raw->{"{$fontkey}{$sfx}"}) && $raw->{"{$fontkey}{$sfx}"} === 'Alegreya, serif') {
                        set_config("{$fontkey}{$sfx}", 'Lato, sans-serif', 'theme_trema');
                        $raw->{"{$fontkey}{$sfx}"} = 'Lato, sans-serif';
                    }
                }
            }

            // Verify physical files in Global fileareas and migrate company backgroundimage_cX to frontpagebanner_cX if needed.
            $fs = get_file_storage();
            $syscontext = \context_system::instance();
            foreach (['backgroundimage', 'frontpagebanner', 'logo', 'compactlogo', 'favicon', 'loginbackgroundimage'] as $farea) {
                $gfiles = $fs->get_area_files($syscontext->id, 'theme_trema', $farea, 0, 'sortorder, filepath, filename', false);
                if (empty($gfiles) && !empty($raw->$farea)) {
                    unset_config($farea, 'theme_trema');
                    unset($raw->$farea);
                }
            }
            foreach ($companies as $comp) {
                $cid = (int)$comp->id;
                $bgarea = "backgroundimage_c{$cid}";
                $bannerarea = "frontpagebanner_c{$cid}";
                $bgfiles = $fs->get_area_files($syscontext->id, 'theme_trema', $bgarea, 0, 'sortorder, filepath, filename', false);
                $bfiles = $fs->get_area_files($syscontext->id, 'theme_trema', $bannerarea, 0, 'sortorder, filepath, filename', false);
                if (!empty($bgfiles) && empty($bfiles)) {
                    $bgfile = reset($bgfiles);
                    $filerecord = [
                        'contextid' => $syscontext->id,
                        'component' => 'theme_trema',
                        'filearea'  => $bannerarea,
                        'itemid'    => 0,
                        'filepath'  => $bgfile->get_filepath(),
                        'filename'  => $bgfile->get_filename(),
                    ];
                    $fs->create_file_from_storedfile($filerecord, $bgfile);
                    set_config($bannerarea, $bgfile->get_filepath() . $bgfile->get_filename(), 'theme_trema');
                }
            }

            if (!empty($CFG->dirroot)) {
                $formfile = $CFG->dirroot . '/blocks/iomad_company_admin/company_edit_form.php';
                if (file_exists($formfile) && is_writable($formfile)) {
                    $content = file_get_contents($formfile);
                    if ($content !== false && strpos($content, "'trema'") === false && strpos($content, "'iomadboost'") !== false) {
                        $patched = str_replace(
                            "['iomad', 'iomadboost', 'iomadbootstrap']",
                            "['iomad', 'iomadboost', 'iomadbootstrap', 'trema']",
                            $content
                        );
                        $patched = str_replace(
                            "array('iomad', 'iomadboost', 'iomadbootstrap')",
                            "array('iomad', 'iomadboost', 'iomadbootstrap', 'trema')",
                            $patched
                        );
                        if ($patched !== $content) {
                            @file_put_contents($formfile, $patched);
                        }
                    }
                }
            }

            set_config('multitenant_v11_healed', '1', 'theme_trema');
            $raw->multitenant_v11_healed = '1';
        }

        // Continuous Guard 1: Ensure every Global (0) canonical setting exists and is valid on every request.
        foreach ($canonicaldefaults as $gkey => $gval) {
            if (!property_exists($raw, $gkey) || $raw->$gkey === null || $raw->$gkey === false || ($raw->$gkey === '' && $gval !== '')) {
                set_config($gkey, $gval, 'theme_trema');
                $raw->$gkey = $gval;
            }
        }
        if ((int)($raw->numberofimages ?? 1) < 1) {
            set_config('numberofimages', '1', 'theme_trema');
            $raw->numberofimages = '1';
        }
        if ((int)($raw->numberofcards ?? 4) < 2) {
            set_config('numberofcards', '4', 'theme_trema');
            $raw->numberofcards = '4';
        }
        if ((string)($raw->footeropacity ?? '1') === '0' || trim((string)($raw->footeropacity ?? '')) === '') {
            set_config('footeropacity', '1', 'theme_trema');
            $raw->footeropacity = '1';
        }

        // Continuous Guard 2: Propagate Global (0) changes to companies using set_config() (NEVER unset_config()!).
        $internalkeys = [
            'version', 'global_defaults_snapshot', 'multitenant_v7_healed',
            'multitenant_v8_healed', 'multitenant_v11_healed',
            'frontpagetitledefault', 'frontpagesubtitledefault',
            'frontpagebuttontextdefault', 'cardtitledefault',
        ];
        $snapjson = isset($raw->global_defaults_snapshot) ? $raw->global_defaults_snapshot : '';
        $snapshot = !empty($snapjson) ? @json_decode($snapjson, true) : [];
        if (!is_array($snapshot)) {
            $snapshot = [];
        }
        $newsnapshot = $snapshot;
        $snapchanged = false;

        foreach ($canonicaldefaults as $gk => $defaultval) {
            $gvstr = isset($raw->$gk) ? (string)$raw->$gk : (string)$defaultval;
            if (array_key_exists($gk, $snapshot) && (string)$snapshot[$gk] !== $gvstr) {
                $oldgvstr = (string)$snapshot[$gk];
                // Global setting changed! Update any company that was using the previous Global value via set_config.
                foreach ($companies as $comp) {
                    $cid = (int)$comp->id;
                    $ck = "{$gk}_c{$cid}";
                    if (!property_exists($raw, $ck) || (string)$raw->$ck === $oldgvstr || $raw->$ck === '') {
                        set_config($ck, $gvstr, 'theme_trema');
                        $raw->$ck = $gvstr;
                    }
                }
                $newsnapshot[$gk] = $gvstr;
                $snapchanged = true;
            } else if (!array_key_exists($gk, $snapshot)) {
                $newsnapshot[$gk] = $gvstr;
                $snapchanged = true;
            }
        }
        if ($snapchanged) {
            set_config('global_defaults_snapshot', json_encode($newsnapshot), 'theme_trema');
        }

        // Continuous Guard 3: Ensure every company (_cX) has every canonical setting populated in mdl_config_plugins
        // so Moodle's admin/settings.php NEVER receives NULL from $setting->get_setting() and NEVER renders unchecked/blank inputs!
        foreach ($companies as $comp) {
            $cid = (int)$comp->id;
            $sfx = "_c{$cid}";
            foreach ($canonicaldefaults as $gk => $defaultval) {
                $ck = "{$gk}{$sfx}";
                $globalval = (isset($raw->$gk) && ($raw->$gk !== '' || $defaultval === '')) ? (string)$raw->$gk : (string)$defaultval;
                if (!property_exists($raw, $ck) || $raw->$ck === null || $raw->$ck === false || ($raw->$ck === '' && $defaultval !== '')) {
                    set_config($ck, $globalval, 'theme_trema');
                    $raw->$ck = $globalval;
                }
            }
            if ((int)($raw->{"numberofimages{$sfx}"} ?? 1) < 1) {
                $gnum = (int)($raw->numberofimages ?? 1) >= 1 ? (string)$raw->numberofimages : '1';
                set_config("numberofimages{$sfx}", $gnum, 'theme_trema');
                $raw->{"numberofimages{$sfx}"} = $gnum;
            }
            if ((int)($raw->{"numberofcards{$sfx}"} ?? 4) < 2) {
                $gcards = (int)($raw->numberofcards ?? 4) >= 2 ? (string)$raw->numberofcards : '4';
                set_config("numberofcards{$sfx}", $gcards, 'theme_trema');
                $raw->{"numberofcards{$sfx}"} = $gcards;
            }
            if ((string)($raw->{"footeropacity{$sfx}"} ?? '1') === '0') {
                set_config("footeropacity{$sfx}", '1', 'theme_trema');
                $raw->{"footeropacity{$sfx}"} = '1';
            }
        }
    } catch (\Throwable $e) {
        // Ignore during early bootstrap.
    }
}

/**
 * Resolves the currently active IOMAD company ID for the frontend request.
 *
 * Priority:
 * 1. HTTP Hostname matching mdl_company.hostname (supports wildcard subdomains even before login)
 * 2. URL parameters (?id=X&code=Y on login page)
 * 3. Active IOMAD session ($SESSION->currenteditingcompany)
 * 4. IOMAD API (iomad::get_my_companyid) for logged-in users
 * 5. Direct lookup in mdl_company_users for logged-in users
 *
 * @param bool $refresh Whether to re-evaluate instead of using static cache.
 * @return int Company ID, or 0 for global/main site.
 */
function theme_trema_get_active_companyid(bool $refresh = false): int {
    global $DB, $SESSION, $USER, $CFG;
    static $resolvedid = null;

    if (!$refresh && $resolvedid !== null) {
        return $resolvedid;
    }

    if (!theme_trema_has_iomad()) {
        $resolvedid = 0;
        return 0;
    }

    theme_trema_auto_heal_config();

    // 1. Check by HTTP_HOST (subdomain / custom hostname configured in IOMAD company).
    $host = !empty($_SERVER['HTTP_HOST']) ? strtolower(trim(explode(':', $_SERVER['HTTP_HOST'])[0])) : '';
    if (!empty($host)) {
        foreach (theme_trema_get_all_companies() as $comp) {
            if (!empty($comp->hostname)) {
                $comphost = strtolower(trim(preg_replace('#^https?://#i', '', $comp->hostname), '/ '));
                if ($comphost === $host) {
                    $resolvedid = (int)$comp->id;
                    return $resolvedid;
                }
            }
        }
    }

    // 2. Check URL parameters (?id=X&code=Y used by IOMAD login).
    if (function_exists('optional_param')) {
        try {
            $paramid = optional_param('id', 0, PARAM_INT);
            $paramcode = optional_param('code', '', PARAM_ALPHANUMEXT);
            if ($paramid > 0 && !empty($paramcode)) {
                $comp = theme_trema_get_company_record($paramid);
                if ($comp && strtolower($comp->shortname) === strtolower($paramcode)) {
                    $resolvedid = (int)$comp->id;
                    return $resolvedid;
                }
            }
        } catch (\Throwable $e) {
            // Ignore if params not ready.
        }
    }

    // 3. Check IOMAD session variable ($SESSION->currenteditingcompany).
    if (!empty($SESSION->currenteditingcompany)) {
        $comp = theme_trema_get_company_record((int)$SESSION->currenteditingcompany);
        if ($comp) {
            $resolvedid = (int)$comp->id;
            return $resolvedid;
        }
    }

    // 4. Check logged-in user's company via IOMAD API or mdl_company_users.
    if (function_exists('isloggedin') && isloggedin() && !isguestuser() && !empty($USER->id)) {
        if (!empty($CFG->dirroot) && file_exists($CFG->dirroot . '/local/iomad/lib/iomad.php')) {
            require_once($CFG->dirroot . '/local/iomad/lib/iomad.php');
        }
        if (class_exists('\iomad')) {
            try {
                $cid = (int)\iomad::get_my_companyid(\context_system::instance(), false);
                if ($cid > 0 && theme_trema_get_company_record($cid)) {
                    $resolvedid = $cid;
                    return $resolvedid;
                }
            } catch (\Throwable $e) {
                // Fallback to direct DB lookup below.
            }
        }
        try {
            $usercomp = $DB->get_records('company_users', ['userid' => $USER->id], 'id ASC', 'companyid', 0, 1);
            if (!empty($usercomp)) {
                $first = reset($usercomp);
                if (!empty($first->companyid) && theme_trema_get_company_record((int)$first->companyid)) {
                    $resolvedid = (int)$first->companyid;
                    return $resolvedid;
                }
            }
        } catch (\Throwable $e) {
            // Ignore.
        }
    }

    $resolvedid = 0;
    return 0;
}

/**
 * Resolves which company ID is currently being edited in Site Administration > Appearance > Themes > Trema.
 * Synchronizes bidirectionally with IOMAD's top-right company switcher while allowing the admin
 * to select Global (0 - Main Site) without IOMAD overwriting it back to the user's company.
 *
 * @return int Company ID being edited, or 0 for Global (Main Site) defaults.
 */
function theme_trema_get_editing_companyid(): int {
    global $SESSION;

    if (!theme_trema_has_iomad()) {
        return 0;
    }

    theme_trema_auto_heal_config();

    // During Moodle upgrade / new settings screen, always use Global (0) so base settings are registered cleanly.
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    if (strpos($script, 'upgradesettings.php') !== false || strpos($script, 'upgrade.php') !== false) {
        return 0;
    }

    $currentiomad = !empty($SESSION->currenteditingcompany) ? (int)$SESSION->currenteditingcompany : 0;

    // 1. If a settings form is being submitted via POST, detect the exact company suffix (_cX or Global 0)
    // directly from the posted trema_companyid or s_theme_trema_* keys so saving NEVER mismatches the rendered form!
    if (!empty($_POST)) {
        if (isset($_POST['trema_companyid']) && is_numeric($_POST['trema_companyid'])) {
            $explicitcid = (int)$_POST['trema_companyid'];
            if ($explicitcid === 0) {
                $SESSION->trema_editing_companyid = 0;
                $SESSION->trema_last_iomad_company = $currentiomad;
                return 0;
            }
            if ($explicitcid > 0 && theme_trema_get_company_record($explicitcid)) {
                $SESSION->trema_editing_companyid = $explicitcid;
                $SESSION->currenteditingcompany = $explicitcid;
                $SESSION->trema_last_iomad_company = $explicitcid;
                return $explicitcid;
            }
        }

        $hasglobalpost = false;
        foreach (array_keys($_POST) as $postkey) {
            if (strpos($postkey, 's_theme_trema_') === 0) {
                if (preg_match('/_c(\d+)$/', $postkey, $m)) {
                    $postcid = (int)$m[1];
                    if ($postcid > 0 && theme_trema_get_company_record($postcid)) {
                        $SESSION->trema_editing_companyid = $postcid;
                        $SESSION->currenteditingcompany = $postcid;
                        $SESSION->trema_last_iomad_company = $postcid;
                        return $postcid;
                    }
                } else {
                    $hasglobalpost = true;
                }
            }
        }
        if ($hasglobalpost) {
            $SESSION->trema_editing_companyid = 0;
            $SESSION->trema_last_iomad_company = $currentiomad;
            return 0;
        }
    }

    try {
        $reqcompanyid = optional_param('trema_companyid', -1, PARAM_INT);
        if ($reqcompanyid === 0) {
            // Admin explicitly chose "Sitio Principal (Global)".
            // Record $currentiomad in trema_last_iomad_company so IOMAD's navbar keeping $SESSION->currenteditingcompany
            // does NOT trick subsequent requests into thinking the admin switched companies in the navbar.
            $SESSION->trema_editing_companyid = 0;
            $SESSION->trema_last_iomad_company = $currentiomad;
        } else if ($reqcompanyid > 0 && theme_trema_get_company_record($reqcompanyid)) {
            $SESSION->trema_editing_companyid = $reqcompanyid;
            $SESSION->currenteditingcompany = $reqcompanyid;
            $SESSION->trema_last_iomad_company = $reqcompanyid;
        } else {
            // No explicit trema_companyid in URL: check if the admin switched companies in IOMAD's top-right dropdown!
            $lastiomad = isset($SESSION->trema_last_iomad_company) ? (int)$SESSION->trema_last_iomad_company : -1;
            if (!isset($SESSION->trema_editing_companyid)) {
                $SESSION->trema_editing_companyid = $currentiomad;
                $SESSION->trema_last_iomad_company = $currentiomad;
            } else if ($currentiomad > 0 && $currentiomad !== $lastiomad) {
                // The admin selected a different company in IOMAD's top-right switcher!
                $SESSION->trema_editing_companyid = $currentiomad;
                $SESSION->trema_last_iomad_company = $currentiomad;
            }
        }

        // Handle 1-click Reset of a company's overrides back to Global defaults.
        $resetcomp = optional_param('trema_reset_company', 0, PARAM_INT);
        if ($resetcomp === 1 && !empty($SESSION->trema_editing_companyid) && confirm_sesskey()) {
            $cid = (int)$SESSION->trema_editing_companyid;
            $sfx = "_c{$cid}";
            $sfxlen = strlen($sfx);
            $raw = get_config('theme_trema');
            if (is_object($raw)) {
                foreach (get_object_vars($raw) as $k => $v) {
                    if (substr($k, -$sfxlen) === $sfx) {
                        $basek = substr($k, 0, -$sfxlen);
                        if (isset($raw->$basek)) {
                            set_config($k, $raw->$basek, 'theme_trema');
                        } else {
                            unset_config($k, 'theme_trema');
                        }
                    }
                }
            }
            theme_trema_auto_heal_config(true);
            if (function_exists('theme_reset_all_caches')) {
                theme_reset_all_caches();
            }
        }
    } catch (\Throwable $e) {
        // Ignore if optional_param not ready.
    }

    $editingid = isset($SESSION->trema_editing_companyid)
        ? (int)$SESSION->trema_editing_companyid
        : 0;

    if ($editingid > 0 && !theme_trema_get_company_record($editingid)) {
        $editingid = 0;
        $SESSION->trema_editing_companyid = 0;
    }

    return $editingid;
}

/**
 * Returns the setting suffix for a given company ID (e.g. '_c3' or '').
 *
 * @param int|null $companyid
 * @return string
 */
function theme_trema_get_setting_suffix(?int $companyid = null): string {
    if ($companyid === null) {
        $companyid = theme_trema_get_active_companyid();
    }
    return ($companyid > 0) ? "_c{$companyid}" : '';
}

/**
 * Returns the Global (companyid = 0) value of a setting to use as the default in settings/*.php
 * when editing a specific company, so company forms inherit the site's current Global settings
 * instead of showing empty boxes or reverting to factory defaults.
 *
 * @param string $settingname Base setting name (without _cX suffix)
 * @param mixed $factorydefault Factory fallback value
 * @param int $editingcompanyid Currently edited company ID
 * @return mixed
 */
function theme_trema_get_global_default(string $settingname, $factorydefault, int $editingcompanyid = 0) {
    if ($editingcompanyid <= 0) {
        return $factorydefault;
    }
    $val = get_config('theme_trema', $settingname);
    if ($val !== false && $val !== null && trim((string)$val) !== '') {
        return $val;
    }
    return $factorydefault;
}

/**
 * Renders the Enterprise Multi-Tenant Company Selector panel for the Trema admin settings page.
 * Uses clean FontAwesome icons (zero emojis) and a single scalable <select> dropdown so the UI
 * remains crisp and uncluttered whether there are 2 companies or 200 companies.
 *
 * @param int $editingcompanyid
 * @return string HTML panel
 */
function theme_trema_render_tenant_settings_selector(int $editingcompanyid = 0): string {
    if (!theme_trema_has_iomad()) {
        return '';
    }

    $companies = theme_trema_get_all_companies();
    if (empty($companies)) {
        return '';
    }

    $baseurl = new \moodle_url('/admin/settings.php', ['section' => 'themesettingtrema']);
    $globalurl = (new \moodle_url($baseurl, ['trema_companyid' => 0]))->out(false);

    $optionshtml = '<option value="' . s($globalurl) . '" data-companyid="0"' . ($editingcompanyid === 0 ? ' selected="selected"' : '') . '>'
        . 'Sitio Principal (Global — Valores por defecto)'
        . '</option>';

    $currentname = 'Sitio Principal (Global)';
    foreach ($companies as $comp) {
        $compid = (int)$comp->id;
        $compurl = (new \moodle_url($baseurl, ['trema_companyid' => $compid]))->out(false);
        $isselected = ($editingcompanyid === $compid);
        $hostinfo = !empty($comp->hostname) ? " ({$comp->hostname})" : " [{$comp->shortname}]";
        $label = format_string($comp->name) . $hostinfo;
        if ($isselected) {
            $currentname = $label;
        }
        $optionshtml .= '<option value="' . s($compurl) . '" data-companyid="' . $compid . '"' . ($isselected ? ' selected="selected"' : '') . '>'
            . s($label)
            . '</option>';
    }

    $actionbuttons = '';
    if ($editingcompanyid > 0) {
        $actionbuttons .= '<a href="' . s($globalurl) . '" class="btn btn-outline-secondary btn-sm mr-2 me-2 trema-global-jump-btn">'
            . '<i class="fa fa-globe mr-1 me-1" aria-hidden="true"></i>Ir a Sitio Principal'
            . '</a>';
        if (function_exists('sesskey')) {
            $reseturl = (new \moodle_url($baseurl, [
                'trema_companyid' => $editingcompanyid,
                'trema_reset_company' => 1,
                'sesskey' => sesskey(),
            ]))->out(false);
            $actionbuttons .= '<a href="' . s($reseturl) . '" class="btn btn-outline-danger btn-sm" title="Eliminar personalizaciones de esta empresa y volver a heredar del Sitio Principal">'
                . '<i class="fa fa-undo mr-1 me-1" aria-hidden="true"></i>Restablecer a valores globales'
                . '</a>';
        }
    }

    if ($editingcompanyid > 0) {
        $badge = '<span class="badge badge-primary bg-primary text-white px-2 py-1">'
            . '<i class="fa fa-building-o mr-1 me-1" aria-hidden="true"></i>Compañía activa: ' . s($currentname)
            . '</span>';
        $statusmsg = 'Personalizando los ajustes exclusivos de <strong>' . s($currentname) . '</strong>. Los campos que no modifiques heredarán los valores del Sitio Principal (Global).';
    } else {
        $badge = '<span class="badge badge-success bg-success text-white px-2 py-1">'
            . '<i class="fa fa-globe mr-1 me-1" aria-hidden="true"></i>Sitio Principal (Global)'
            . '</span>';
        $statusmsg = 'Configurando los valores generales del <strong>Sitio Principal (Global)</strong>. Estos ajustes se aplican al portal raíz y sirven como base por defecto para todas las compañías.';
    }

    // Single-line / zero-indentation HTML so Markdown never treats it as a code block.
    $html = '<div class="trema-tenant-selector-bar card bg-light border shadow-sm mb-4 p-3" style="border-left: 4px solid #0f6cbf !important;">';
    $html .= '<div class="d-flex flex-wrap align-items-center justify-content-between mb-2 pb-2 border-bottom">';
    $html .= '<div class="d-flex align-items-center mb-1">';
    $html .= '<i class="fa fa-sliders text-primary mr-2 me-2" style="font-size: 1.15rem;" aria-hidden="true"></i>';
    $html .= '<span class="font-weight-bold fw-bold text-dark" style="font-size: 1.05rem;">' . get_string('multitenant_selector_title', 'theme_trema') . '</span>';
    $html .= '</div>';
    $html .= '<div>' . $badge . '</div>';
    $html .= '</div>';
    $html .= '<div class="small text-muted mb-3">' . $statusmsg . '</div>';
    $html .= '<div class="d-flex flex-wrap align-items-center justify-content-between">';
    $html .= '<div class="d-flex flex-wrap align-items-center mb-2 mb-md-0">';
    $html .= '<label class="mr-2 me-2 mb-0 font-weight-bold fw-bold text-secondary small text-uppercase">' . get_string('multitenant_select_label', 'theme_trema') . '</label>';
    $html .= '<select class="custom-select form-select form-select-sm trema-tenant-select" style="min-width: 280px; max-width: 420px;">' . $optionshtml . '</select>';
    $html .= '</div>';
    if (!empty($actionbuttons)) {
        $html .= '<div class="d-flex flex-wrap align-items-center">' . $actionbuttons . '</div>';
    }
    $html .= '</div>';
    $html .= '</div>';

    return $html;
}

/**
 * Retrieves a single theme_trema setting resolved for the given (or active) company,
 * falling back to IOMAD company colors (from Edit Company > Appearance), then to Global theme_trema
 * configuration, and finally to $default (factory default).
 *
 * @param string $name Base setting name (without _cX suffix)
 * @param int|null $companyid Company ID, or null to auto-detect active company
 * @param mixed $default Default value if not found or empty
 * @return mixed
 */
function theme_trema_get_setting(string $name, ?int $companyid = null, $default = false) {
    if ($companyid === null) {
        $companyid = theme_trema_get_active_companyid();
    }

    $allconfig = get_config('theme_trema');
    if (!is_object($allconfig)) {
        $allconfig = new stdClass();
    }

    if ($companyid > 0) {
        $compkey = "{$name}_c{$companyid}";
        if (property_exists($allconfig, $compkey) && $allconfig->$compkey !== null && $allconfig->$compkey !== false && trim((string)$allconfig->$compkey) !== '') {
            return $allconfig->$compkey;
        }
        // Fallback to IOMAD Edit Company -> Appearance fields (aligned with theme_iomad).
        $company = theme_trema_get_company_record($companyid);
        if ($company) {
            if ($name === 'primarycolor' && !empty($company->linkcolor)) {
                return $company->linkcolor;
            }
            if ($name === 'headerbgcolor' && !empty($company->headingcolor)) {
                return $company->headingcolor;
            }
            if (($name === 'bodybackgroundcolor' || $name === 'drawerbgcolor') && !empty($company->maincolor)) {
                return $company->maincolor;
            }
            if ($name === 'custommenuitems' && !empty($company->custommenuitems)) {
                return $company->custommenuitems;
            }
        }
    }

    if (property_exists($allconfig, $name) && $allconfig->$name !== null && $allconfig->$name !== false && trim((string)$allconfig->$name) !== '') {
        return $allconfig->$name;
    }

    return $default;
}

/**
 * Returns a complete stdClass of all theme_trema settings merged for the active company.
 * Empty company values ('') automatically inherit from Global and factory defaults.
 *
 * @param int|null $companyid
 * @return stdClass
 */
function theme_trema_get_plugin_settings(?int $companyid = null): stdClass {
    if ($companyid === null) {
        $companyid = theme_trema_get_active_companyid();
    }

    $raw = get_config('theme_trema');
    if (!is_object($raw)) {
        $raw = new stdClass();
    }

    $resolved = clone $raw;

    // Ensure essential visibility & structural defaults are always present if missing or empty in Global.
    $essentialdefaults = [
        'enabletremalines' => '1',
        'enabletremafooter' => '1',
        'enablefooterinfo' => '1',
        'showbranding' => '1',
        'showactivityicons' => '1',
        'loginshowloginform' => '1',
        'showumlogoutlink' => '1',
        'showfrontpagelinkstopages' => '1',
        'frontpageenabledarkoverlay' => '1',
        'frontpageenablecards' => '1',
        'numberofcards' => '4',
        'frontpagecardstitle' => get_string('cardtitle', 'theme_trema'),
        'frontpagecardssubtitle' => get_string('cardsubtitle', 'theme_trema'),
        'numberofimages' => '1',
        'softness' => '1',
        'sitefont' => 'Lato, sans-serif',
        'h1font' => 'Lato, sans-serif',
        'hxfont' => 'Lato, sans-serif',
        'bannertitlesfont' => 'Lato, sans-serif',
        'frontpagetitle' => get_string('frontpagetitle_default', 'theme_trema'),
        'frontpagesubtitle' => get_string('frontpagesubtitle_default', 'theme_trema'),
        'frontpagebuttontext' => get_string('frontpagebuttontext_default', 'theme_trema'),
        'frontpagebuttonhref' => '#topofcontent',
        'frontpagebuttonclass' => 'btn-primary',
        'defaultfooter' => get_string('defaultfooter_default', 'theme_trema'),
    ];
    foreach ($essentialdefaults as $ekey => $eval) {
        if (!isset($resolved->$ekey) || trim((string)$resolved->$ekey) === '') {
            $resolved->$ekey = $eval;
        }
    }

    if ($companyid > 0) {
        $suffix = "_c{$companyid}";
        $suffixlen = strlen($suffix);

        foreach (get_object_vars($raw) as $key => $val) {
            if (substr($key, -$suffixlen) === $suffix) {
                $basekey = substr($key, 0, -$suffixlen);
                if ($val !== null && $val !== false && trim((string)$val) !== '') {
                    $resolved->$basekey = $val;
                }
            }
        }
        // Fallback to IOMAD Edit Company -> Appearance fields (aligned with theme_iomad/classes/output/core_renderer.php).
        $company = theme_trema_get_company_record($companyid);
        if ($company) {
            if (empty($raw->{"primarycolor{$suffix}"}) && !empty($company->linkcolor)) {
                $resolved->primarycolor = $company->linkcolor;
            }
            if (empty($raw->{"headerbgcolor{$suffix}"}) && !empty($company->headingcolor)) {
                $resolved->headerbgcolor = $company->headingcolor;
            }
            if (empty($raw->{"bodybackgroundcolor{$suffix}"}) && !empty($company->maincolor)) {
                $resolved->bodybackgroundcolor = $company->maincolor;
            }
            if (empty($raw->{"drawerbgcolor{$suffix}"}) && !empty($company->maincolor)) {
                $resolved->drawerbgcolor = $company->maincolor;
            }
            if (empty($raw->{"custommenuitems{$suffix}"}) && !empty($company->custommenuitems)) {
                $resolved->custommenuitems = $company->custommenuitems;
            }
        }
    }

    return $resolved;
}

/**
 * Returns the display site name for the active company (or Main Site / Global).
 *
 * @param bool $fullname True for fullname, false for shortname
 * @param int|null $companyid
 * @return string
 */
function theme_trema_get_tenant_sitename(bool $fullname = false, ?int $companyid = null): string {
    global $SITE;

    if ($companyid === null) {
        $companyid = theme_trema_get_active_companyid();
    }

    $context = \context_system::instance();

    if ($companyid > 0) {
        $compcustom = get_config('theme_trema', "tenantsitename_c{$companyid}");
        if ($compcustom !== false && $compcustom !== null && trim((string)$compcustom) !== '') {
            return \format_string($compcustom, true, ['context' => $context, 'escape' => false]);
        }
        $company = theme_trema_get_company_record($companyid);
        if ($company) {
            $name = $fullname ? ($company->name ?: $company->shortname) : ($company->shortname ?: $company->name);
            if (!empty($name)) {
                return \format_string($name, true, ['context' => $context, 'escape' => false]);
            }
        }
    } else {
        $globalcustom = get_config('theme_trema', 'tenantsitename');
        if ($globalcustom !== false && $globalcustom !== null && trim((string)$globalcustom) !== '') {
            return \format_string($globalcustom, true, ['context' => $context, 'escape' => false]);
        }
    }

    $defaultname = $fullname ? ($SITE->fullname ?? '') : ($SITE->shortname ?? '');
    return \format_string($defaultname, true, ['context' => $context, 'escape' => false]);
}

/**
 * Return an array of objects containing all frontpage cards settings for the active company.
 *
 * @param int|null $companyid
 * @return array|bool
 */
function theme_trema_get_cards_settings(?int $companyid = null) {
    $pluginsettings = theme_trema_get_plugin_settings($companyid);
    $cardssettings = [];

    $numberofcards = $pluginsettings->numberofcards ?? 4;
    if (!empty($pluginsettings->frontpageenablecards) && $numberofcards > 1) {
        for ($i = 1; $i <= $numberofcards; $i++) {
            $cardsettings = new stdClass();
            $cardsettings->cardicon = $pluginsettings->{'cardicon' . $i} ?? 'fa-paper-plane';
            $cardsettings->cardiconcolor = $pluginsettings->{'cardiconcolor' . $i} ?? '#000000';
            $cardsettings->cardtitle = \format_string($pluginsettings->{'cardtitle' . $i} ?? get_string('cardtitle_default', 'theme_trema') . ' ' . $i);
            $cardsettings->cardsubtitle = \format_string($pluginsettings->{'cardsubtitle' . $i} ?? get_string('cardsubtitle_default', 'theme_trema'));
            $cardsettings->cardlink = $pluginsettings->{'cardlink' . $i} ?? '';

            $cardssettings[] = $cardsettings;
        }
        return $cardssettings;
    }
    return false;
}

/**
 * Get the URL of a file strictly from the company-specific theme_trema filearea (without falling back to global).
 *
 * @param string $setting
 * @param string $filearea
 * @param int $companyid
 * @return string|bool
 */
function theme_trema_company_only_file_url(string $setting, string $filearea, int $companyid) {
    global $CFG;

    if ($companyid <= 0) {
        return false;
    }

    $component = 'theme_trema';
    $itemid = theme_get_revision();
    $syscontext = \context_system::instance();
    $compfilearea = "{$filearea}_c{$companyid}";

    // Verify that the file physically exists in Moodle file storage for this company's filearea.
    $fs = get_file_storage();
    $files = $fs->get_area_files($syscontext->id, $component, $compfilearea, 0, 'sortorder, filepath, filename', false);
    if (!empty($files)) {
        $file = reset($files);
        $filepath = $file->get_filepath() . $file->get_filename();
        $url = \moodle_url::make_file_url(
            "$CFG->wwwroot/pluginfile.php",
            "/$syscontext->id/$component/$compfilearea/$itemid" . $filepath
        );
        return $url->out(false);
    }

    return false;
}

/**
 * Get the URL of files from theme settings, supporting company-specific fileareas with fallback to global.
 * Returns a full valid URL (with scheme) compatible with both CSS url() and moodle_url.
 *
 * @param string $setting
 * @param string $filearea
 * @param theme_config|null $theme
 * @param int|null $companyid
 * @return string|bool
 */
function theme_trema_setting_file_url($setting, $filearea, $theme = null, ?int $companyid = null) {
    global $CFG;

    if ($companyid === null) {
        $companyid = theme_trema_get_active_companyid();
    }

    // 1. Check company-specific filearea first if a tenant is active.
    if ($companyid > 0) {
        $compurl = theme_trema_company_only_file_url($setting, $filearea, $companyid);
        if (!empty($compurl)) {
            return $compurl;
        }
    }

    // 2. Fallback to global setting / filearea (verified against physical file storage so 404s never occur).
    $component = 'theme_trema';
    $itemid = theme_get_revision();
    $syscontext = \context_system::instance();

    $fs = get_file_storage();
    $files = $fs->get_area_files($syscontext->id, $component, $filearea, 0, 'sortorder, filepath, filename', false);
    if (!empty($files)) {
        $file = reset($files);
        $filepath = $file->get_filepath() . $file->get_filename();
        $url = \moodle_url::make_file_url(
            "$CFG->wwwroot/pluginfile.php",
            "/$syscontext->id/$component/$filearea/$itemid" . $filepath
        );
        return $url->out(false);
    }

    return false;
}

/**
 * Resolves a color setting reference (such as 'primarycolor', '$white', '$gray-800', or a hex code)
 * into a valid CSS color value.
 *
 * @param string $val
 * @param stdClass $settings
 * @param string $fallback
 * @return string
 */
function theme_trema_resolve_css_color(string $val, stdClass $settings, string $fallback = '#ffffff'): string {
    $val = trim($val);
    if ($val === '') {
        return $fallback;
    }
    $map = [
        '$white' => '#ffffff',
        'white' => '#ffffff',
        '$black' => '#000000',
        'black' => '#000000',
        '$gray-200' => '#e9ecef',
        '$gray-800' => '#343a40',
        'primarycolor' => !empty($settings->primarycolor) ? $settings->primarycolor : '#1c6ca3',
        'secondarycolor' => !empty($settings->secondarycolor) ? $settings->secondarycolor : '#343a40',
        'bodybackgroundcolor' => !empty($settings->bodybackgroundcolor) ? $settings->bodybackgroundcolor : '#f1f1f1',
    ];
    $resolved = $map[$val] ?? $val;
    return !empty($resolved) ? $resolved : $fallback;
}

/**
 * Calculates WCAG contrast foreground color (#ffffff or #212529) for a given hex background color.
 *
 * @param string $hexcolor
 * @return string
 */
function theme_trema_get_contrast_color(string $hexcolor): string {
    $hex = ltrim(trim($hexcolor), '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    if (strlen($hex) !== 6 || !ctype_xdigit($hex)) {
        return '#ffffff';
    }
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));
    $yiq = (($r * 299) + ($g * 587) + ($b * 114)) / 1000;
    return ($yiq >= 150) ? '#212529' : '#ffffff';
}

/**
 * Generates dynamic CSS overrides for both Global (companyid = 0) and active Tenant companies (companyid > 0)
 * so that Trema's banner, footer, colors, fonts, and IOMAD Appearance settings always render cleanly
 * even before a Moodle cache purge.
 *
 * @param int|null $companyid
 * @return string CSS rules wrapped in <style> tag.
 */
function theme_trema_get_tenant_custom_css(?int $companyid = null): string {
    global $OUTPUT;

    if ($companyid === null) {
        $companyid = theme_trema_get_active_companyid();
    }

    $settings = theme_trema_get_plugin_settings($companyid);
    $company = ($companyid > 0) ? theme_trema_get_company_record($companyid) : null;
    $bodyselector = ($companyid > 0) ? "body.company-id-{$companyid}" : "body";

    $primary = !empty($settings->primarycolor) ? $settings->primarycolor : '#1c6ca3';
    $secondary = !empty($settings->secondarycolor) ? $settings->secondarycolor : '#343a40';
    $bodybg = !empty($settings->bodybackgroundcolor) ? $settings->bodybackgroundcolor : '#f1f1f1';
    $headerbg = theme_trema_resolve_css_color($settings->headerbgcolor ?? '$white', $settings, '#ffffff');
    $headertextcolor = theme_trema_get_contrast_color($headerbg);
    $loginbtnbg = theme_trema_resolve_css_color($settings->loginbtnbgcolor ?? 'primarycolor', $settings, $primary);
    $drawerbg = theme_trema_resolve_css_color($settings->drawerbgcolor ?? '$gray-200', $settings, '#e9ecef');
    $footerbg = theme_trema_resolve_css_color($settings->footerbgcolor ?? '$gray-800', $settings, '#343a40');
    $footertextcolor = theme_trema_get_contrast_color($footerbg);
    $footeropacity = isset($settings->footeropacity) && $settings->footeropacity !== '' && (string)$settings->footeropacity !== '0' ? $settings->footeropacity : '1';
    $sitefont = !empty($settings->sitefont) ? $settings->sitefont : 'Lato, sans-serif';
    $h1font = !empty($settings->h1font) ? $settings->h1font : 'Lato, sans-serif';
    $hxfont = !empty($settings->hxfont) ? $settings->hxfont : 'Lato, sans-serif';
    $bannerfont = !empty($settings->bannertitlesfont) ? $settings->bannertitlesfont : 'Lato, sans-serif';
    $bannerheight = !empty($settings->bannerheight) ? $settings->bannerheight : '100vh';
    $banneralign = !empty($settings->frontpagebannercontentalign) ? $settings->frontpagebannercontentalign : 'center';
    $bannertitletransform = !empty($settings->bannertitletransform) ? $settings->bannertitletransform : 'none';
    $bannertitlespacing = !empty($settings->bannertitlespacing) ? $settings->bannertitlespacing : 'normal';
    $texttransform = !empty($settings->texttransform) ? $settings->texttransform : 'none';
    $linkdecoration = !empty($settings->linkdecoration) ? $settings->linkdecoration : 'underline';
    $softness = (!isset($settings->softness) || !empty($settings->softness)) ? '0.4rem' : '0';

    $css = "@import url('https://fonts.googleapis.com/css2?family=Lato:ital,wght@0,300;0,400;0,700;0,900;1,400&family=Montserrat:wght@300;400;500;600;700&family=Open+Sans:wght@300;400;600;700&family=Poppins:wght@300;400;500;600;700&family=Roboto:wght@300;400;500;700&display=swap');\n";
    $css .= ":root {\n";
    $css .= "    --primary: {$primary};\n";
    $css .= "    --secondary: {$secondary};\n";
    $css .= "    --bs-primary: {$primary};\n";
    $css .= "    --bs-secondary: {$secondary};\n";
    $css .= "}\n";

    $css .= "{$bodyselector} {\n";
    $css .= "    background-color: {$bodybg} !important;\n";
    $css .= "    font-family: {$sitefont};\n";
    $css .= "}\n";

    $css .= "{$bodyselector} h1 { font-family: {$h1font}; text-transform: {$texttransform}; }\n";
    $css .= "{$bodyselector} h2, {$bodyselector} h3, {$bodyselector} h4, {$bodyselector} h5, {$bodyselector} h6 { font-family: {$hxfont}; text-transform: {$texttransform}; }\n";

    // Frontpage Banner structural & visual guarantee (prevents black uncentered text if SCSS cache is stale).
    $css .= "{$bodyselector}#page-site-index #page { padding: 0; }\n";
    $css .= "{$bodyselector}#page-site-index #page-content #region-main { background: #ffffff; padding: 2rem 3rem; }\n";
    $css .= "{$bodyselector} #frontpage-banner, {$bodyselector} #carouselTrema .frontpage-banner {\n";
    $css .= "    position: relative;\n";
    $css .= "    min-height: calc({$bannerheight} - 4em);\n";
    $css .= "    background-position: center center;\n";
    $css .= "    background-size: cover;\n";
    $css .= "    color: #ffffff;\n";
    $css .= "}\n";
    $css .= "{$bodyselector} #frontpage-banner #frontpage-banner-content, {$bodyselector} #carouselTrema .frontpage-banner-content {\n";
    $css .= "    position: absolute;\n";
    $css .= "    top: 50%;\n";
    $css .= "    left: 50%;\n";
    $css .= "    transform: translate(-50%, -50%);\n";
    $css .= "    text-align: {$banneralign};\n";
    $css .= "    color: #ffffff;\n";
    $css .= "    z-index: 2;\n";
    $css .= "}\n";
    $css .= "{$bodyselector} #frontpage-banner-content h2, {$bodyselector} .frontpage-banner-content h2 {\n";
    $css .= "    color: #ffffff !important;\n";
    $css .= "    border-bottom: solid 1px rgba(255, 255, 255, 0.5);\n";
    $css .= "    font-size: 2.5rem;\n";
    $css .= "    font-weight: bold;\n";
    $css .= "    line-height: 3rem;\n";
    $css .= "    font-family: {$bannerfont};\n";
    $css .= "    text-transform: {$bannertitletransform};\n";
    $css .= "    letter-spacing: {$bannertitlespacing};\n";
    $css .= "}\n";
    $css .= "{$bodyselector} #frontpage-banner-content .h3, {$bodyselector} .frontpage-banner-content .h3 {\n";
    $css .= "    color: #ffffff !important;\n";
    $css .= "    font-weight: 300;\n";
    $css .= "    font-size: 1.5rem;\n";
    $css .= "    line-height: 2rem;\n";
    $css .= "    display: block;\n";
    $css .= "}\n";
    $css .= "{$bodyselector} #frontpage-cards { background: #ffffff; max-width: 1280px; margin: 0 auto; }\n";

    // Primary & Link color overrides (scoped outside #page-footer so dark footers keep white links).
    $css .= "{$bodyselector} .btn-primary, {$bodyselector} .bg-primary, {$bodyselector} .badge-primary {\n";
    $css .= "    background-color: {$primary} !important;\n";
    $css .= "    border-color: {$primary} !important;\n";
    $css .= "    color: #ffffff !important;\n";
    $css .= "}\n";
    $css .= "{$bodyselector} .btn-outline-primary {\n";
    $css .= "    color: {$primary} !important;\n";
    $css .= "    border-color: {$primary} !important;\n";
    $css .= "}\n";
    $css .= "{$bodyselector} #page-content a:not(.btn):not(.nav-link):not(.dropdown-item):not(.navbar-brand) {\n";
    $css .= "    color: {$primary};\n";
    $css .= "    text-decoration: {$linkdecoration};\n";
    $css .= "}\n";
    $css .= "{$bodyselector} .text-primary { color: {$primary} !important; }\n";
    $css .= "{$bodyselector} .border-primary { border-color: {$primary} !important; }\n";
    $css .= "{$bodyselector} .page-item.active .page-link, {$bodyselector} .nav-pills .nav-link.active {\n";
    $css .= "    background-color: {$primary} !important;\n";
    $css .= "    border-color: {$primary} !important;\n";
    $css .= "}\n";
    $css .= "{$bodyselector} .moremenu .nav-link.active {\n";
    $css .= "    border-bottom-color: {$primary} !important;\n";
    $css .= "}\n";

    // Secondary color overrides.
    $css .= "{$bodyselector} .btn-secondary, {$bodyselector} .bg-secondary {\n";
    $css .= "    background-color: {$secondary} !important;\n";
    $css .= "    border-color: {$secondary} !important;\n";
    $css .= "}\n";

    // Softness (border-radius).
    $css .= "{$bodyselector} .card, {$bodyselector} .btn, {$bodyselector} .form-control {\n";
    $css .= "    border-radius: {$softness};\n";
    $css .= "}\n";

    // Header (.navbar), drawer (#nav-drawer, .drawer), login button backgrounds.
    $css .= "{$bodyselector} .navbar, {$bodyselector} #page-wrapper .navbar {\n";
    $css .= "    background-color: {$headerbg} !important;\n";
    $css .= "    color: {$headertextcolor};\n";
    $css .= "}\n";
    $css .= "{$bodyselector} .usermenu .login a.btn {\n";
    $css .= "    background-color: {$loginbtnbg} !important;\n";
    $css .= "    border-color: {$loginbtnbg} !important;\n";
    $css .= "}\n";
    $css .= "{$bodyselector} .drawer, {$bodyselector} #nav-drawer {\n";
    $css .= "    background-color: {$drawerbg} !important;\n";
    $css .= "}\n";

    // Footer (#page-footer) full styling guarantee (contrast text, no bullets on .labeled-icons, clean spacing).
    $css .= "{$bodyselector} footer#page-footer, {$bodyselector} #page.drawers footer#page-footer {\n";
    $css .= "    background-color: {$footerbg} !important;\n";
    $css .= "    color: {$footertextcolor} !important;\n";
    $css .= "    opacity: {$footeropacity};\n";
    $css .= "    padding: 1.5rem;\n";
    $css .= "    max-width: none;\n";
    $css .= "}\n";
    $css .= "{$bodyselector} footer#page-footer h2, {$bodyselector} footer#page-footer h3, {$bodyselector} footer#page-footer h4,\n";
    $css .= "{$bodyselector} footer#page-footer h5, {$bodyselector} footer#page-footer h6,\n";
    $css .= "{$bodyselector} footer#page-footer p, {$bodyselector} footer#page-footer li,\n";
    $css .= "{$bodyselector} footer#page-footer .copyleft, {$bodyselector} footer#page-footer .smalltext {\n";
    $css .= "    color: {$footertextcolor} !important;\n";
    $css .= "}\n";
    $css .= "{$bodyselector} footer#page-footer a:not(.btn):not(.button) {\n";
    $css .= "    color: {$footertextcolor} !important;\n";
    $css .= "    text-decoration: {$linkdecoration};\n";
    $css .= "}\n";
    $css .= "{$bodyselector} footer#page-footer a:not(.btn):not(.button):hover {\n";
    $css .= "    text-decoration: underline;\n";
    $css .= "}\n";
    $css .= "{$bodyselector} footer#page-footer ul.labeled-icons {\n";
    $css .= "    list-style: none !important;\n";
    $css .= "    padding-left: 0 !important;\n";
    $css .= "}\n";
    $css .= "{$bodyselector} footer#page-footer ul.labeled-icons li {\n";
    $css .= "    margin-bottom: 1.25em;\n";
    $css .= "    position: relative;\n";
    $css .= "}\n";
    $css .= "{$bodyselector} footer#page-footer ul.labeled-icons li span {\n";
    $css .= "    width: 1.5em;\n";
    $css .= "    display: inline-block;\n";
    $css .= "}\n";
    $css .= "{$bodyselector} footer#page-footer ul.labeled-icons li a,\n";
    $css .= "{$bodyselector} footer#page-footer ul.labeled-icons li p {\n";
    $css .= "    display: inline;\n";
    $css .= "}\n";
    $css .= "{$bodyselector} footer#page-footer .copyleft {\n";
    $css .= "    font-size: 0.9em;\n";
    $css .= "    margin: 2.5em 0 0 0;\n";
    $css .= "    padding: 0;\n";
    $css .= "    text-align: center;\n";
    $css .= "}\n";
    $css .= "{$bodyselector} footer#page-footer .copyleft span {\n";
    $css .= "    display: inline-block;\n";
    $css .= "    transform: rotate(180deg);\n";
    $css .= "}\n";
    $css .= "{$bodyselector} footer#page-footer .footer-content-popover.container {\n";
    $css .= "    display: none;\n";
    $css .= "}\n";

    // Visibility toggles per tenant: ONLY hide when explicitly set to '0' (defaults are '1' = show).
    if (isset($settings->enabletremalines) && (string)$settings->enabletremalines === '0') {
        $css .= "{$bodyselector} #page-wrapper::before, {$bodyselector} #page-footer::before { content: none !important; display: none !important; }\n";
    }
    if (isset($settings->showactivityicons) && (string)$settings->showactivityicons === '0') {
        $css .= "{$bodyselector} .page-header-image, {$bodyselector} .activityiconcontainer.courseicon { display: none !important; }\n";
    }
    if (isset($settings->loginshowloginform) && (string)$settings->loginshowloginform === '0') {
        $css .= "{$bodyselector} #login, {$bodyselector} .loginform .login-form { display: none !important; }\n";
    }
    if (isset($settings->showumlogoutlink) && (string)$settings->showumlogoutlink === '0') {
        $css .= "{$bodyselector} #carousel-item-main a:last-of-type, {$bodyselector} #carousel-item-main .dropdown-divider:last-of-type { display: none !important; }\n";
    }
    if (isset($settings->showfrontpagelinkstopages) && (string)$settings->showfrontpagelinkstopages === '0') {
        $css .= "{$bodyselector}#page-site-index:not(.editing) #page-content .modtype_page { display: none !important; }\n";
    }
    if (isset($settings->showbranding) && (string)$settings->showbranding === '0') {
        $css .= "{$bodyselector} .sitelink, {$bodyselector} .footer-section.p-3:not(.border-bottom), {$bodyselector} #page-footer .copyleft { display: none !important; }\n";
    }

    // Single frontpage banner image override:
    // Priority for a tenant company ($companyid > 0):
    // 1. Company's own frontpagebanner_cX
    // 2. Company's own backgroundimage_cX (if the admin uploaded the banner in General -> Background image)
    // 3. Global frontpagebanner (0)
    // 4. Global backgroundimage (0)
    // 5. Default Trema forest banner
    $overlay = (isset($settings->frontpageenabledarkoverlay) && (string)$settings->frontpageenabledarkoverlay === '0')
        ? ''
        : "url('" . $OUTPUT->image_url('frontpage/overlay', 'theme') . "'), ";

    $resolvedbanner = false;
    $useoverlay = true;
    if ($companyid > 0) {
        $compbanner = theme_trema_company_only_file_url('frontpagebanner', 'frontpagebanner', $companyid);
        $compbg = theme_trema_company_only_file_url('backgroundimage', 'backgroundimage', $companyid);
        if (!empty($compbanner)) {
            $resolvedbanner = $compbanner;
            $useoverlay = true;
        } else if (!empty($compbg)) {
            $resolvedbanner = $compbg;
            $useoverlay = false;
        }
    }
    if (empty($resolvedbanner)) {
        $globalbanner = theme_trema_setting_file_url('frontpagebanner', 'frontpagebanner', null, 0);
        $globalbg = theme_trema_setting_file_url('backgroundimage', 'backgroundimage', null, 0);
        if (!empty($globalbanner)) {
            $resolvedbanner = $globalbanner;
            $useoverlay = true;
        } else if (!empty($globalbg)) {
            $resolvedbanner = $globalbg;
            $useoverlay = false;
        }
    }

    if (!empty($resolvedbanner)) {
        $ov = $useoverlay ? $overlay : '';
        $css .= "{$bodyselector} #frontpage-banner {\n";
        $css .= "    background-image: {$ov}url('{$resolvedbanner}') !important;\n";
        $css .= "}\n";
    } else {
        $defaultbanner = $OUTPUT->image_url('frontpage/banner', 'theme');
        $css .= "{$bodyselector} #frontpage-banner {\n";
        $css .= "    background-image: {$overlay}url('{$defaultbanner}') !important;\n";
        $css .= "}\n";
    }

    // Ensure body does not repeat a background image across inner course/admin sections.
    $css .= "{$bodyselector} { background-image: none !important; }\n";

    // Login page background image override.
    if (($settings->loginpagestyle ?? 'none') === 'image') {
        $loginbgurl = theme_trema_setting_file_url('loginbackgroundimage', 'loginbackgroundimage', null, $companyid);
        if (!empty($loginbgurl)) {
            $css .= "{$bodyselector}.pagelayout-login #page-wrapper, {$bodyselector}.pagelayout-login #page {\n";
            $css .= "    background-image: url('{$loginbgurl}') !important;\n";
            $css .= "    background-size: cover;\n";
            $css .= "    background-position: center;\n";
            $css .= "}\n";
        }
    } else {
        $css .= "{$bodyselector}.pagelayout-login #page-wrapper { background-image: none !important; }\n";
    }

    // Custom CSS from theme_trema settings (Global and/or Company) and IOMAD company customcss.
    $globalscss = get_config('theme_trema', 'scss');
    if (!empty($globalscss)) {
        $css .= "\n/* Global Custom CSS */\n" . $globalscss . "\n";
    }
    if ($companyid > 0) {
        $compcustomscsspre = get_config('theme_trema', "scsspre_c{$companyid}");
        if (!empty($compcustomscsspre)) {
            $css .= "\n/* Company Pre-CSS */\n" . $compcustomscsspre . "\n";
        }
        $compcustomscss = get_config('theme_trema', "scss_c{$companyid}");
        if (!empty($compcustomscss)) {
            $css .= "\n/* Company Custom CSS */\n" . $compcustomscss . "\n";
        }
        if ($company && !empty($company->customcss)) {
            $css .= "\n/* IOMAD Company Custom CSS */\n" . $company->customcss . "\n";
        }
    }

    return "<style id=\"theme-trema-tenant-css-{$companyid}\">\n{$css}\n</style>\n";
}
