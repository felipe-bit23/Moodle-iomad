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
 * note.js
 *
 * @package   videoprogresscontent_note
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(["jquery"], function ($) {
    const open = (element, api, item) => {
        const button = element.querySelector('[data-action="acknowledge"]');
        button.addEventListener('click', () => {
            button.disabled = true;
            api.complete({acknowledged: true}).then((result) => {
                if (result.completed) {
                    api.dismiss(true);
                } else {
                    button.disabled = false;
                }
            }).catch(() => {
                button.disabled = false;
            });
        }, {once: true});
        if (!item.pause) {
            window.setTimeout(() => api.dismiss(false), Number(item.data.displayduration || 8) * 1000);
        }
    };
    return {open: open};
});
