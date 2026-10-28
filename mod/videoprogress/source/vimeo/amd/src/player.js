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
 * player.js
 *
 * @package   videoprogresssource_vimeo
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(["jquery"], function ($) {
    let apiPromise;
    const loadApi = (url) => {
        if (window.Vimeo && window.Vimeo.Player) {
            return Promise.resolve(window.Vimeo);
        }
        if (!apiPromise) {
            apiPromise = new Promise((resolve, reject) => {
                const script = document.createElement('script');
                script.src = url;
                script.onload = () => resolve(window.Vimeo);
                script.onerror = reject;
                document.head.appendChild(script);
            });
        }
        return apiPromise;
    };

    /**
     * Provides the common player adapter contract through the Vimeo Player API.
     */
    class VimeoAdapter {
        /**
         * Initialises adapter state, event handlers, and references used during playback.
         *
         * @param {*} root Activity root element.
         * @param {*} config Browser-safe player or interaction configuration.
         */
        constructor(root, config) {
            this.root = root;
            this.config = config;
            this.handlers = {};
            this.current = 0;
            this.duration = 0;
            this.rate = 1;
        }

        /**
         * Initialises the Vimeo integration and returns the ready adapter.
         *
         * @return {Promise<VimeoAdapter>} Ready Vimeo adapter.
         */
        initialise() {
            return loadApi(this.config.vimeoplayerurl).then((Vimeo) => {
                const options = {id: Number(this.config.vimeoid), responsive: true};
                if (this.config.vimeohash) {
                    options.h = this.config.vimeohash;
                }
                this.player = new Vimeo.Player('videoprogress-vimeo-player', options);
                this.player.on('play', () => this.emit('play'));
                this.player.on('pause', () => this.emit('pause'));
                this.player.on('ended', () => this.emit('ended'));
                this.player.on('timeupdate', (data) => {
                    this.current = data.seconds;
                    this.duration = data.duration;
                    this.emit('timeupdate', data.seconds);
                });
                this.player.on('seeked', (data) => {
                    this.current = data.seconds;
                    this.emit('seek', data.seconds);
                });
                this.player.on('playbackratechange', (data) => {
                    this.rate = data.playbackRate;
                    const maximum = Number(this.config.maxplaybackrate || 0);
                    if (maximum > 0 && this.rate > maximum) {
                        this.player.setPlaybackRate(maximum).catch(() => {
                        });
                        this.rate = maximum;
                    }
                    this.emit('ratechange', this.rate);
                });
                return Promise.all([this.player.ready(), this.player.getDuration()]).then((values) => {
                    this.duration = values[1];
                    return this;
                });
            });
        }

        /**
         * Starts or resumes playback through the Vimeo Player API.
         *
         * @return {Promise<void>} Vimeo playback request.
         */
        play() {
            return this.player.play();
        }

        /**
         * Pauses playback through the Vimeo Player API.
         *
         * @return {Promise<void>} Vimeo pause request.
         */
        pause() {
            return this.player.pause();
        }

        /**
         * Returns the current player position in seconds.
         *
         * @return {number} Current playback position.
         */
        getCurrentTime() {
            return Number(this.current || 0);
        }

        /**
         * Returns the Vimeo media duration in seconds.
         *
         * @return {number} Media duration.
         */
        getDuration() {
            return Number(this.duration || 0);
        }

        /**
         * Returns the current Vimeo playback speed.
         *
         * @return {number} Current playback rate.
         */
        getPlaybackRate() {
            return Number(this.rate || 1);
        }

        /**
         * Moves the Vimeo player to the requested position.
         *
         * @param {number} position Target video position in seconds.
         * @return {Promise<number>} Vimeo seek request.
         */
        seek(position) {
            return this.player.setCurrentTime(Math.max(0, Number(position)));
        }

        /**
         * Registers a callback for player play events.
         *
         * @param {Function} handler Callback invoked for the normalized event.
         */
        onPlay(handler) {
            this.on('play', handler);
        }

        /**
         * Registers a callback for player pause events.
         *
         * @param {Function} handler Callback invoked for the normalized event.
         */
        onPause(handler) {
            this.on('pause', handler);
        }

        /**
         * Registers a callback for periodic player position updates.
         *
         * @param {Function} handler Callback invoked for the normalized event.
         */
        onTimeUpdate(handler) {
            this.on('timeupdate', handler);
        }

        /**
         * Registers a callback for player seek events.
         *
         * @param {Function} handler Callback invoked for the normalized event.
         */
        onSeek(handler) {
            this.on('seek', handler);
        }

        /**
         * Registers a callback for the end-of-media event.
         *
         * @param {Function} handler Callback invoked for the normalized event.
         */
        onEnded(handler) {
            this.on('ended', handler);
        }

        /**
         * Registers a callback for playback-rate changes.
         *
         * @param {Function} handler Callback invoked for the normalized event.
         */
        onRateChange(handler) {
            this.on('ratechange', handler);
        }

        /**
         * Registers an internal callback for the selected normalized event.
         *
         * @param {string} name Normalized player event name.
         * @param {Function} handler Callback invoked for the normalized event.
         */
        on(name, handler) {
            this.handlers[name] = (this.handlers[name] || []).concat(handler);
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

    const create = (root, config) => (new VimeoAdapter(root, config)).initialise();
    return {create: create};
});
