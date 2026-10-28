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
 * html5.js
 *
 * @package   mod_videoprogress
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(["jquery"], function ($) {
    /**
     * Provides the common player adapter contract for HTML5 video and native HLS playback.
     */
    class Html5Adapter {
        /**
         * Initialises adapter state, event handlers, and references used during playback.
         *
         * @param {*} root Activity root element.
         * @param {*} config Browser-safe player or interaction configuration.
         */
        constructor(root, config) {
            this.root = root;
            this.config = config;
            this.video = root.querySelector('[data-region="html5-player"]');
            this.handlers = {};
            this.lastTime = 0;
        }

        /**
         * Initialises the player integration and returns the ready adapter or tracker.
         *
         * @return {*} The ready adapter, tracker, or readiness promise.
         */
        initialise() {
            if (!this.video) {
                return Promise.reject(new Error('HTML5 player element is missing'));
            }
            if (this.config.disabledownload) {
                this.video.setAttribute('controlsList', 'nodownload');
            }
            if (this.config.disablepip) {
                this.video.disablePictureInPicture = true;
            }
            if (this.config.disablecontextmenu) {
                this.video.addEventListener('contextmenu', (event) => event.preventDefault());
            }
            this.video.addEventListener('play', () => this.emit('play'));
            this.video.addEventListener('pause', () => this.emit('pause'));
            this.video.addEventListener('ended', () => this.emit('ended'));
            this.video.addEventListener('timeupdate', () => {
                const current = this.getCurrentTime();
                this.emit('timeupdate', current);
                this.lastTime = current;
            });
            this.video.addEventListener('seeking', () => this.emit('seek', this.getCurrentTime(), this.lastTime));
            this.video.addEventListener('ratechange', () => {
                const maximum = Number(this.config.maxplaybackrate || 0);
                if (maximum > 0 && this.video.playbackRate > maximum) {
                    this.video.playbackRate = maximum;
                }
                this.emit('ratechange', this.getPlaybackRate());
            });
            return new Promise((resolve) => {
                if (this.video.readyState >= 1) {
                    resolve(this);
                } else {
                    this.video.addEventListener('loadedmetadata', () => resolve(this), {once: true});
                }
            });
        }

        /**
         * Starts or resumes playback through the source player API.
         */
        play() {
            return this.video.play();
        }

        /**
         * Pauses playback through the source player API.
         */
        pause() {
            this.video.pause();
        }

        /**
         * Returns the current player position in seconds.
         *
         * @return {*} Current playback position in seconds.
         */
        getCurrentTime() {
            return Number(this.video.currentTime || 0);
        }

        /**
         * Returns the best known media duration in seconds.
         *
         * @return {*} Media duration in seconds.
         */
        getDuration() {
            return Number.isFinite(this.video.duration) ? Number(this.video.duration) : 0;
        }

        /**
         * Returns the current playback speed.
         *
         * @return {*} Current playback speed.
         */
        getPlaybackRate() {
            return Number(this.video.playbackRate || 1);
        }

        /**
         * Moves the player to the requested position.
         *
         * @param {*} position Target video position in seconds.
         */
        seek(position) {
            this.video.currentTime = Math.max(0, Math.min(this.getDuration(), Number(position)));
        }

        /**
         * Registers a callback for player play events.
         *
         * @param {Function} handler Callback invoked for the normalized player event.
         */
        onPlay(handler) {
            this.on('play', handler);
        }

        /**
         * Registers a callback for player pause events.
         *
         * @param {Function} handler Callback invoked for the normalized player event.
         */
        onPause(handler) {
            this.on('pause', handler);
        }

        /**
         * Registers a callback for periodic player position updates.
         *
         * @param {Function} handler Callback invoked for the normalized player event.
         */
        onTimeUpdate(handler) {
            this.on('timeupdate', handler);
        }

        /**
         * Registers a callback for player seek events.
         *
         * @param {Function} handler Callback invoked for the normalized player event.
         */
        onSeek(handler) {
            this.on('seek', handler);
        }

        /**
         * Registers a callback for the end-of-media event.
         *
         * @param {Function} handler Callback invoked for the normalized player event.
         */
        onEnded(handler) {
            this.on('ended', handler);
        }

        /**
         * Registers a callback for playback-rate changes.
         *
         * @param {Function} handler Callback invoked for the normalized player event.
         */
        onRateChange(handler) {
            this.on('ratechange', handler);
        }

        /**
         * Registers an internal callback for the selected normalized player event.
         *
         * @param {string} name Normalized player event name.
         * @param {Function} handler Callback invoked for the normalized player event.
         */
        on(name, handler) {
            this.handlers[name] = this.handlers[name] || [];
            this.handlers[name].push(handler);
        }

        /**
         * Emits a normalized player event to every registered callback.
         *
         * @param {string} name Normalized player event name.
         * @param {...*} args Optional event payload values.
         */
        emit(name, ...args) {
            (this.handlers[name] || []).forEach((handler) => handler(...args));
        }
    }

    return Html5Adapter;
});
