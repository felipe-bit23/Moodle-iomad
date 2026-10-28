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
 * captions.js
 *
 * @package   mod_videoprogress
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(["jquery", "core/notification", "core/str"], function ($, Notification, Str) {
    /**
     * Asks the teacher to confirm caption removal before following the delete action.
     *
     * @param {Function} proceed Callback invoked after confirmation.
     * @return {void}
     */
    const confirmDelete = (proceed) => {
        Promise.all([
            Str.get_string('deletecaptionconfirm', 'videoprogress'),
            Str.get_string('confirmdelete', 'videoprogress'),
            Str.get_string('cancel', 'videoprogress')
        ]).then((strings) => Notification.confirm('', strings[0], strings[1], strings[2], proceed));
    };

    const init = () => {
        $(document).on('click', 'a[data-action="delete-caption"]', function (event) {
            event.preventDefault();
            const href = this.getAttribute('href');
            confirmDelete(() => {
                window.location.href = href;
            });
        });
        $(document).on('submit', 'form[data-action="delete-caption"]', function (event) {
            if (this.dataset.confirmed === '1') {
                return;
            }
            event.preventDefault();
            const form = this;
            confirmDelete(() => {
                form.dataset.confirmed = '1';
                form.submit();
            });
        });
    };
    return {init: init};
});
