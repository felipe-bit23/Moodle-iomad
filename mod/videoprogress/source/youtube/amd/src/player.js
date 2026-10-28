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
 * @package   videoprogresssource_youtube
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define(["jquery"], function ($) {
    let apiPromise;

    const loadApi = () => {
        if (window.YT && window.YT.Player) {
            return Promise.resolve(window.YT);
        }
        if (apiPromise) {
            return apiPromise;
        }
        apiPromise = new Promise((resolve, reject) => {
            const previous = window.onYouTubeIframeAPIReady;
            window.onYouTubeIframeAPIReady = () => {
                if (typeof previous === 'function') {
                    previous();
                }
                resolve(window.YT);
            };
            const script = document.createElement('script');
            script.src = 'https://www.youtube.com/iframe_api';
            script.onerror = reject;
            document.head.appendChild(script);
        });
        return apiPromise;
    };

    /**
     * Provides the common player adapter contract through the YouTube IFrame Player API.
     */
    class YoutubeAdapter {
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
            this.lastTime = 0;
            this.playing = false;
        }

        /**
         * Initialises the YouTube integration and returns the ready adapter.
         *
         * @return {Promise<YoutubeAdapter>} Ready YouTube adapter.
         */
        initialise() {
            return loadApi().then((YT) => new Promise((resolve) => {
                this.player = new YT.Player('videoprogress-youtube-player', {
                    host: this.config.youtubehost,
                    videoId: this.config.youtubeid,
                    playerVars: {playsinline: 1, rel: 0, modestbranding: 1},
                    events: {
                        onReady: () => {
                            this.startTimer();
                            resolve(this);
                        },
                        onStateChange: (event) => this.stateChanged(event.data, YT),
                        onPlaybackRateChange: () => this.rateChanged(),
                    },
                });
            }));
        }

        /**
         * Translates a YouTube state change into normalized adapter events.
         *
         * @param {*} state YouTube player state code.
         * @param {Object} YT YouTube IFrame Player API namespace.
         */
        stateChanged(state, YT) {
            if (state === YT.PlayerState.PLAYING) {
                this.playing = true;
                this.emit('play');
            } else if (state === YT.PlayerState.PAUSED) {
                this.playing = false;
                this.emit('pause');
            } else if (state === YT.PlayerState.ENDED) {
                this.playing = false;
                this.emit('ended');
            }
        }

        /**
         * Starts periodic YouTube position sampling while playback is active.
         */
        startTimer() {
            this.timer = window.setInterval(() => {
                const current = this.getCurrentTime();
                if (this.playing && Math.abs(current - this.lastTime) > Math.max(3, this.getPlaybackRate() * 3)) {
                    this.emit('seek', current, this.lastTime);
                }
                if (this.playing) {
                    this.emit('timeupdate', current);
                }
                this.lastTime = current;
            }, 500);
        }

        /**
         * Applies and emits a validated YouTube playback-rate change.
         */
        rateChanged() {
            const maximum = Number(this.config.maxplaybackrate || 0);
            if (maximum > 0 && this.getPlaybackRate() > maximum) {
                const available = this.player.getAvailablePlaybackRates().filter((rate) => rate <= maximum);
                this.player.setPlaybackRate(available.pop() || 1);
            }
            this.emit('ratechange', this.getPlaybackRate());
        }

        /**
         * Starts or resumes playback through the YouTube Player API.
         *
         * @return {Promise<void>} Resolved playback request.
         */
        play() {
            this.player.playVideo();
            return Promise.resolve();
        }

        /**
         * Pauses playback through the YouTube Player API.
         */
        pause() {
            this.player.pauseVideo();
        }

        /**
         * Returns the current player position in seconds.
         *
         * @return {number} Current playback position.
         */
        getCurrentTime() {
            return Number(this.player.getCurrentTime() || 0);
        }

        /**
         * Returns the YouTube media duration in seconds.
         *
         * @return {number} Media duration.
         */
        getDuration() {
            return Number(this.player.getDuration() || 0);
        }

        /**
         * Returns the current YouTube playback speed.
         *
         * @return {number} Current playback rate.
         */
        getPlaybackRate() {
            return Number(this.player.getPlaybackRate() || 1);
        }

        /**
         * Moves the YouTube player to the requested position.
         *
         * @param {number} position Target video position in seconds.
         */
        seek(position) {
            this.player.seekTo(Math.max(0, Number(position)), true);
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

    const create = (root, config) => (new YoutubeAdapter(root, config)).initialise();
    return {create: create};
});
