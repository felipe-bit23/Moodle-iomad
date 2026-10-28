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
 * quiz.js
 *
 * @package   videoprogresscontent_quiz
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(["jquery"], function ($) {
    const open = (element, api) => {
        const buttons = Array.from(element.querySelectorAll('[data-answer]'));
        buttons.forEach((button) => button.addEventListener('click', () => {
            buttons.forEach((candidate) => candidate.disabled = true);
            api.complete({answer: Number(button.dataset.answer)}).then((result) => {
                if (result.completed) {
                    button.classList.add('is-correct');
                    window.setTimeout(() => api.dismiss(true), 900);
                } else {
                    button.classList.add('is-incorrect');
                    buttons.forEach((candidate) => candidate.disabled = false);
                }
            }).catch(() => buttons.forEach((candidate) => candidate.disabled = false));
        }));
    };
    return {open: open};
});
