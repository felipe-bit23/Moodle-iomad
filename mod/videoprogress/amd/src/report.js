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
 * report.js
 *
 * @package   mod_videoprogress
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(["jquery", "core/str"], function ($, Str) {
    const loadCss = (url) => {
        if (!url || document.querySelector('link[data-videoprogress-datatables]')) {
            return;
        }
        const link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = url;
        link.dataset.videoprogressDatatables = '1';
        document.head.appendChild(link);
    };

    const loadScript = (url) => new Promise((resolve) => {
        if ($.fn.DataTable || !url) {
            resolve();
            return;
        }
        const script = document.createElement('script');
        script.src = url;
        script.onload = resolve;
        script.onerror = resolve;
        document.head.appendChild(script);
    });

    const init = () => {
        document.querySelectorAll('[data-region="report"]').forEach((root) => {
            let config = {};
            try {
                config = JSON.parse(root.dataset.datatableConfig || '{}');
            } catch (error) {
                config = {};
            }
            loadCss(config.cssurl);
            Promise.all([
                loadScript(config.jsurl),
                Str.get_strings([
                    {key: 'datatableempty', component: 'videoprogress'},
                    {key: 'datatableloading', component: 'videoprogress'}
                ])
            ]).then((values) => {
                if (!$.fn.DataTable) {
                    return;
                }
                $(root).find('[data-region="students-table"]').DataTable({
                    paging: false,
                    searching: false,
                    info: false,
                    order: [],
                    autoWidth: false,
                    language: {emptyTable: values[1][0], loadingRecords: values[1][1]}
                });
            });
        });
    };

    return {init: init};
});
