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
 * content.js
 *
 * @package   mod_videoprogress
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(["jquery", "core/ajax", "core/str"], function ($, Ajax, Str) {
    /**
     * Coordinates synchronized timeline points, overlays, required interactions, and sidebar state.
     */
    class VideoContent {
        /**
         * Initialises adapter state, event handlers, and references used during playback.
         *
         * @param {*} root Activity root element.
         * @param {*} config Browser-safe player or interaction configuration.
         * @param {*} player Common player adapter.
         */
        constructor(root, config, player) {
            this.root = root;
            this.config = config || {};
            this.player = player;
            this.points = Array.isArray(this.config.points) ? this.config.points : [];
            this.triggered = new Set();
            this.active = null;
            this.playing = false;
            this.previousTime = Number(player.getCurrentTime() || 0);
        }

        /**
         * Initialises the player integration and returns the ready adapter or tracker.
         *
         * @return {*} The ready adapter, tracker, or readiness promise.
         */
        initialise() {
            if (!this.points.length) {
                return this;
            }
            this.player.onPlay(() => {
                this.playing = true;
                if (this.active && this.active.item.pause) {
                    this.player.pause();
                }
            });
            this.player.onPause(() => this.playing = false);
            this.player.onEnded(() => this.playing = false);
            this.player.onTimeUpdate((current) => this.update(Number(current)));
            this.player.onSeek((current) => this.update(Number(current)));
            this.root.querySelectorAll('[data-content-point]').forEach((element) => {
                element.addEventListener('click', () => this.player.seek(Number(element.dataset.time || 0)));
            });
            this.update(this.previousTime);
            return this;
        }

        /**
         * Updates synchronized content state for the current playback position.
         *
         * @param {*} current Current playback position in seconds.
         */
        update(current) {
            if (!Number.isFinite(current)) {
                return;
            }
            this.updateSidebar(current);
            if (!this.active) {
                const due = this.findDueItem(current, this.previousTime);
                if (due) {
                    this.open(due.point, due.item);
                }
            }
            this.previousTime = current;
        }

        /**
         * Finds the next required or newly reached interaction that should be displayed.
         *
         * @param {*} current Current playback position in seconds.
         * @param {*} previous Previous playback position in seconds.
         * @return {*} The due point and item, or null when none is due.
         */
        findDueItem(current, previous) {
            for (const point of this.points) {
                if (Number(point.time) > current + 0.5) {
                    continue;
                }
                for (const item of point.items || []) {
                    if (item.required && !item.completed && !this.triggered.has(Number(item.id))) {
                        return {point: point, item: item};
                    }
                }
            }
            if (current < previous) {
                return null;
            }
            for (const point of this.points) {
                if (Number(point.time) <= previous + 0.25 || Number(point.time) > current + 0.5) {
                    continue;
                }
                const item = (point.items || []).find((candidate) =>
                    !candidate.completed && !this.triggered.has(Number(candidate.id)));
                if (item) {
                    return {point: point, item: item};
                }
            }
            return null;
        }

        /**
         * Displays a synchronized content overlay and pauses playback when configured.
         *
         * @param {*} point Synchronized timeline point.
         * @param {*} item Synchronized content item.
         * @param {*} resumeOverride Whether playback must resume after chained interactions.
         */
        open(point, item, resumeOverride) {
            const element = this.root.querySelector('[data-content-item="' + Number(item.id) + '"]');
            if (!element) {
                this.triggered.add(Number(item.id));
                return;
            }
            const resume = Boolean(resumeOverride || (item.pause && this.playing));
            this.active = {point: point, item: item, element: element, resume: resume};
            element.hidden = false;
            if (item.pause) {
                this.player.pause();
            }
            require([item.module], (plugin) => plugin.open(element, this.api(), item));
        }

        /**
         * Builds the restricted callback API exposed to a content subplugin.
         *
         * @return {*} The restricted interaction callback API.
         */
        api() {
            return {
                complete: (response) => this.complete(response),
                dismiss: (completed) => this.dismiss(Boolean(completed)),
            };
        }

        /**
         * Sends the active interaction response to the authenticated Moodle AJAX endpoint.
         *
         * @param {*} response Raw interaction response.
         * @return {*} A promise resolved with the server interaction result.
         */
        complete(response) {
            const active = this.active;
            if (!active) {
                return Promise.reject(new Error('No active video content item'));
            }
            return Ajax.call([{
                methodname: 'mod_videoprogress_complete_point',
                args: {
                    cmid: Number(this.config.cmid),
                    itemid: Number(active.item.id),
                    response: JSON.stringify(response || {})
                }
            }])[0].then((result) => {
                const feedback = active.element.querySelector('[data-region="interaction-feedback"]');
                if (feedback) {
                    feedback.textContent = result.feedback || '';
                    feedback.className = 'videoprogress-content-overlay__feedback ' +
                        (result.completed ? 'is-correct' : 'is-incorrect');
                }
                if (result.completed) {
                    active.item.completed = true;
                }
                return result;
            }).catch((error) => {
                return Str.get_string('interactionerror', 'videoprogress').then((message) => {
                    const feedback = active.element.querySelector('[data-region="interaction-feedback"]');
                    if (feedback) {
                        feedback.textContent = message;
                        feedback.className = 'videoprogress-content-overlay__feedback is-incorrect';
                    }
                    throw error;
                });
            });
        }

        /**
         * Closes the active overlay, chains remaining point items, and resumes playback when appropriate.
         *
         * @param {*} completed Whether the interaction was completed.
         */
        dismiss(completed) {
            if (!this.active) {
                return;
            }
            const previous = this.active;
            previous.element.hidden = true;
            this.triggered.add(Number(previous.item.id));
            if (completed) {
                previous.item.completed = true;
            }
            this.active = null;
            this.updateSidebar(this.player.getCurrentTime());
            const next = (previous.point.items || []).find((item) =>
                !item.completed && !this.triggered.has(Number(item.id)));
            if (next) {
                this.open(previous.point, next, previous.resume);
            } else if (previous.resume) {
                this.player.play();
            }
        }

        /**
         * Marks the latest reached point as the current item in the navigation sidebar.
         *
         * @param {*} current Current playback position in seconds.
         */
        updateSidebar(current) {
            let activePoint = null;
            this.points.forEach((point) => {
                if (Number(point.time) <= current + 0.25) {
                    activePoint = point;
                }
            });
            this.root.querySelectorAll('[data-content-point]').forEach((element) => {
                const active = activePoint && Number(element.dataset.contentPoint) === Number(activePoint.id);
                element.classList.toggle('is-current', Boolean(active));
                if (active) {
                    element.setAttribute('aria-current', 'step');
                } else {
                    element.removeAttribute('aria-current');
                }
            });
        }
    }

    const initialise = (root, config, player) => new VideoContent(root, config, player).initialise();
    return {initialise: initialise};
});
