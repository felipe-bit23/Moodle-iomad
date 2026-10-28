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
 * point.js
 *
 * @package   mod_videoprogress
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(["jquery", "mod_videoprogress/player", "core/str"], function ($, Player, Str) {
    /**
     * Formats a player position using the same whole-second format used by the PHP helper.
     *
     * @param {number} value Position in seconds.
     * @return {string} MM:SS or HH:MM:SS.
     */
    const formatTime = (value) => {
        const total = Math.max(0, Math.round(Number(value) || 0));
        const hours = Math.floor(total / 3600);
        const minutes = Math.floor((total % 3600) / 60);
        const seconds = total % 60;
        const pad = (number) => String(number).padStart(2, '0');
        return hours > 0
            ? pad(hours) + ':' + pad(minutes) + ':' + pad(seconds)
            : pad(minutes) + ':' + pad(seconds);
    };

    /**
     * Parses the Moodle point time field into seconds so an existing point can be previewed.
     *
     * @param {string} value Timecode value.
     * @return {number|null} Parsed seconds or null.
     */
    const parseTime = (value) => {
        const match = String(value || '').trim().match(/^(?:(\d{1,3}):)?([0-5]?\d):([0-5]\d)(?:\.(\d{1,3}))?$/);
        if (!match) {
            return null;
        }
        const hours = Number(match[1] || 0);
        const minutes = Number(match[2] || 0);
        const seconds = Number(match[3] || 0);
        const milliseconds = match[4] ? Number('0.' + match[4].padEnd(3, '0')) : 0;
        return (hours * 3600) + (minutes * 60) + seconds + milliseconds;
    };

    /**
     * Initialises one point time picker.
     *
     * @param {HTMLElement} root Time picker root.
     */
    const initialisePicker = (root) => {
        let config = {};
        try {
            config = JSON.parse(root.dataset.config || '{}');
        } catch (error) {
            config = {};
        }

        const input = document.getElementById('id_timecode');
        const currentElement = root.querySelector('[data-region="point-current-time"]');
        const durationElement = root.querySelector('[data-region="point-duration"]');
        const selectButton = root.querySelector('[data-action="point-use-current-time"]');
        const errorElement = root.querySelector('[data-region="point-player-error"]');

        if (!input || !currentElement || !durationElement || !selectButton) {
            return;
        }

        Player.create(root, config).then((player) => {
            const update = (time) => {
                currentElement.textContent = formatTime(time);
                durationElement.textContent = formatTime(player.getDuration());
            };

            update(player.getCurrentTime());
            player.onTimeUpdate((time) => update(time));
            player.onSeek((time) => update(time));

            const savedTime = parseTime(input.value);
            if (savedTime !== null && savedTime > 0) {
                player.seek(savedTime);
                update(savedTime);
            }

            selectButton.addEventListener('click', () => {
                const current = player.getCurrentTime();
                input.value = formatTime(current);
                input.dispatchEvent(new Event('input', {bubbles: true}));
                input.dispatchEvent(new Event('change', {bubbles: true}));
                input.focus();
                update(current);
            });
        }).catch(() => {
            selectButton.disabled = true;
            if (!errorElement) {
                return;
            }
            Str.get_string('invalidplayer', 'videoprogress').then((message) => {
                errorElement.textContent = message;
                errorElement.classList.remove('d-none');
            });
        });
    };

    /**
     * Initialises every point time picker on the page.
     */
    const init = () => {
        document.querySelectorAll('[data-region="point-time-picker"]').forEach(initialisePicker);
    };

    return {init: init};
});
