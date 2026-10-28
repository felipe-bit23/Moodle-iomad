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
 * tracker.js
 *
 * @package   mod_videoprogress
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define([
    "jquery",
    "core/ajax",
    "core/notification",
    "core/str",
    "mod_videoprogress/player",
    "mod_videoprogress/content"
], function ($, Ajax, Notification, Str, Player, VideoContent) {
    const HEARTBEAT_SECONDS = 10;

    /**
     * Collects raw playback intervals and synchronizes server-authoritative progress without per-second requests.
     */
    class Tracker {
        /**
         * Initialises adapter state, event handlers, and references used during playback.
         *
         * @param {*} root Activity root element.
         * @param {*} config Browser-safe player or interaction configuration.
         */
        constructor(root, config) {
            this.root = root;
            this.config = config;
            this.sequence = 0;
            this.sessionkey = this.randomKey();
            this.playing = false;
            this.pendingStart = null;
            this.pendingEnd = null;
            this.lastTime = Number(config.lastposition || 0);
            this.segments = Array.isArray(config.segments) ? config.segments : [];
            this.queueKey = 'mod_videoprogress_queue_' + config.cmid;
            this.memoryQueue = [];
            this.storageAvailable = true;
            this.sending = false;
        }

        /**
         * Initialises the player integration and returns the ready adapter or tracker.
         *
         * @return {*} The ready adapter, tracker, or readiness promise.
         */
        initialise() {
            return Player.create(this.root, this.config.player).then((player) => {
                this.player = player;
                this.bindEvents();
                this.content = VideoContent.initialise(this.root, this.config.content || {}, this.player);
                this.applyResume();
                this.drainQueue();
                this.timer = window.setInterval(() => {
                    if (this.playing) {
                        this.flush('playing');
                    }
                }, HEARTBEAT_SECONDS * 1000);
                return this;
            }).catch((error) => {
                this.showMessage('invalidplayer');
                throw error;
            });
        }

        /**
         * Connects tracker collection, persistence, visibility, and connectivity event handlers.
         */
        bindEvents() {
            this.player.onPlay(() => {
                this.playing = true;
                this.lastTime = this.player.getCurrentTime();
                this.pendingStart = this.lastTime;
                this.pendingEnd = this.lastTime;
            });
            this.player.onTimeUpdate((current) => this.timeUpdate(Number(current)));
            this.player.onPause(() => {
                this.playing = false;
                this.flush('paused');
            });
            this.player.onEnded(() => {
                this.playing = false;
                this.flush('ended');
            });
            this.player.onSeek((current, previous) => this.seek(Number(current), Number(previous || this.lastTime)));
            document.addEventListener('visibilitychange', () => {
                if (document.hidden) {
                    this.flush('hidden');
                } else {
                    this.drainQueue();
                }
            });
            window.addEventListener('pagehide', () => this.flush('closed'));
            window.addEventListener('online', () => this.drainQueue());
        }

        /**
         * Extends the pending continuous watched interval with a plausible time update.
         *
         * @param {*} current Current playback position in seconds.
         */
        timeUpdate(current) {
            if (!this.playing || !Number.isFinite(current)) {
                this.lastTime = current;
                return;
            }
            const rate = Math.max(0.25, this.player.getPlaybackRate());
            const delta = current - this.lastTime;
            if (delta >= 0 && delta <= Math.max(3, rate * 3)) {
                if (this.pendingStart === null) {
                    this.pendingStart = this.lastTime;
                }
                this.pendingEnd = current;
            }
            this.lastTime = current;
        }

        /**
         * Moves the player to the requested position.
         *
         * @param {*} current Current playback position in seconds.
         * @param {*} previous Previous playback position in seconds.
         */
        seek(current, previous) {
            if (!this.config.allowseek && Math.abs(current - previous) > 0.25 && !this.isWatched(current)) {
                this.player.seek(previous);
                this.showMessage('seekblocked');
                return;
            }
            this.flush('seeking');
            this.lastTime = current;
            this.pendingStart = this.playing ? current : null;
            this.pendingEnd = this.pendingStart;
        }

        /**
         * Creates and queues one raw tracking update for the current playback state.
         *
         * @param {*} state YouTube player state code.
         */
        flush(state) {
            if (!this.player) {
                return;
            }
            const current = this.player.getCurrentTime();
            const start = this.pendingStart === null ? current : this.pendingStart;
            const end = this.pendingEnd === null ? start : this.pendingEnd;
            this.pendingStart = this.playing ? current : null;
            this.pendingEnd = this.pendingStart;
            const payload = {
                cmid: Number(this.config.cmid),
                currentposition: Number(current || 0),
                duration: Number(this.player.getDuration() || 0),
                playbackrate: Number(this.player.getPlaybackRate() || 1),
                segmentstart: Number(start || 0),
                segmentend: Number(Math.max(start, end) || 0),
                sequence: ++this.sequence,
                sessionkey: this.sessionkey,
                clienttime: Math.floor(Date.now() / 1000),
                playerstate: state
            };
            if (payload.duration <= 0) {
                return;
            }
            this.enqueue(payload);
            this.drainQueue();
        }

        /**
         * Stores a tracking update in the retry queue.
         *
         * @param {*} payload Optional event payload.
         */
        enqueue(payload) {
            const queue = this.readQueue();
            queue.push(payload);
            this.writeQueue(queue);
        }

        /**
         * Sends queued tracking updates sequentially and removes acknowledged entries.
         */
        drainQueue() {
            if (this.sending || !navigator.onLine) {
                return;
            }
            const queue = this.readQueue();
            if (!queue.length) {
                return;
            }
            const pending = queue[0];
            this.sending = true;
            Ajax.call([{
                methodname: 'mod_videoprogress_update_progress',
                args: pending
            }])[0].then((response) => {
                const currentQueue = this.readQueue();
                const acknowledged = currentQueue.findIndex((item) =>
                    item.sessionkey === pending.sessionkey && Number(item.sequence) === Number(pending.sequence));
                if (acknowledged !== -1) {
                    currentQueue.splice(acknowledged, 1);
                }
                this.writeQueue(currentQueue);
                this.applyResponse(response);
                this.sending = false;
                this.drainQueue();
            }).catch(() => {
                this.sending = false;
                this.showMessage('pendingupdates');
            });
        }

        /**
         * Applies server-authoritative segments, position, percentage, and completion state.
         *
         * @param {*} response Raw interaction response.
         */
        applyResponse(response) {
            try {
                this.segments = JSON.parse(response.segments || '[]');
            } catch (error) {
                this.segments = [];
            }
            if (response.reason === 'seekblocked' || response.reason === 'interactionrequired') {
                this.player.seek(response.correctposition);
                this.showMessage(response.reason);
            }
            const percentage = Math.round(Number(response.percent || 0));
            const watched = Number(response.uniquewatched || 0);
            const percent = this.root.querySelector('[data-region="percent"]');
            const bar = this.root.querySelector('[data-region="progress-bar"]');
            const progressbar = bar ? bar.parentElement : null;
            const progressText = this.root.querySelector('[data-region="progress-text"]');
            const watchedDuration = this.root.querySelector('[data-region="watched-duration"]');
            if (percent) {
                percent.textContent = percentage + '%';
            }
            if (bar) {
                bar.style.width = Number(response.percent || 0) + '%';
            }
            if (progressbar) {
                progressbar.setAttribute('aria-valuenow', percentage);
            }
            if (watched > 0) {
                if (progressText) {
                    progressText.classList.remove('d-none');
                    Str.get_string('watchedpercent', 'videoprogress', percentage).then((message) => {
                        progressText.textContent = message;
                    });
                }
                if (watchedDuration) {
                    const duration = this.player ? Number(this.player.getDuration() || 0) : 0;
                    watchedDuration.classList.remove('d-none');
                    Str.get_string('watchedofduration', 'videoprogress', {
                        uniquewatched: this.formatTime(watched),
                        duration: this.formatTime(duration)
                    }).then((message) => {
                        watchedDuration.textContent = message;
                    });
                }
            }
            if (response.completed) {
                this.showMessage('activitycompleted', 'success');
            }
        }

        /**
         * Applies the configured resume policy to the common player adapter.
         */
        applyResume() {
            const position = Number(this.config.lastposition || 0);
            if (position <= 1 || Number(this.config.resumeplayback) === 0) {
                return;
            }
            if (Number(this.config.resumeplayback) === 1) {
                this.player.seek(position);
                return;
            }
            Promise.all([
                Str.get_string('resumequestion', 'videoprogress', this.formatTime(position)),
                Str.get_string('resumeyes', 'videoprogress'),
                Str.get_string('resumeno', 'videoprogress')
            ]).then((strings) => Notification.confirm('', strings[0], strings[1], strings[2],
                () => this.player.seek(position), () => this.player.seek(0)));
        }

        /**
         * Checks whether a position belongs to a server-confirmed watched interval.
         *
         * @param {*} position Target video position in seconds.
         * @return {*} Whether the position is already confirmed as watched.
         */
        isWatched(position) {
            return this.segments.some((segment) => position >= Number(segment[0]) - 0.25 && position <= Number(segment[1]) + 0.25);
        }

        /**
         * Reads pending tracking updates from local storage with an in-memory fallback.
         *
         * @return {*} Pending tracking updates.
         */
        readQueue() {
            if (!this.storageAvailable) {
                return this.memoryQueue.slice();
            }
            try {
                const queue = JSON.parse(window.localStorage.getItem(this.queueKey) || '[]');
                return Array.isArray(queue) && queue.length ? queue : this.memoryQueue.slice();
            } catch (error) {
                this.storageAvailable = false;
                return this.memoryQueue.slice();
            }
        }

        /**
         * Persists pending tracking updates locally until the server acknowledges them.
         *
         * @param {*} queue Pending tracking update collection.
         */
        writeQueue(queue) {
            this.memoryQueue = queue.slice();
            try {
                window.localStorage.setItem(this.queueKey, JSON.stringify(queue));
                this.memoryQueue = [];
                this.storageAvailable = true;
            } catch (error) {
                this.storageAvailable = false;
                // The in-memory queue remains active when browser storage is unavailable.
            }
        }

        /**
         * Loads and displays a localized tracking status message.
         *
         * @param {*} key Localized language string identifier.
         * @param {*} type Bootstrap alert type.
         */
        showMessage(key, type) {
            Str.get_string(key, 'videoprogress').then((message) => {
                const element = this.root.querySelector('[data-region="tracking-message"]');
                if (!element) {
                    return;
                }
                element.textContent = message;
                element.className = 'alert alert-' + (type || 'warning');
                window.setTimeout(() => element.classList.add('d-none'), 5000);
            });
        }

        /**
         * Creates a cryptographically random key for the current playback session.
         *
         * @return {*} Random hexadecimal session key.
         */
        randomKey() {
            const bytes = new Uint8Array(24);
            window.crypto.getRandomValues(bytes);
            return Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
        }

        /**
         * Formats a number of seconds as a player timecode.
         *
         * @param {number} seconds Duration value in seconds.
         * @return {*} Formatted video timecode.
         */
        formatTime(seconds) {
            const value = Math.max(0, Math.round(seconds));
            const hours = Math.floor(value / 3600);
            const minutes = Math.floor((value % 3600) / 60);
            const remaining = value % 60;
            return (hours ? String(hours).padStart(2, '0') + ':' : '') +
                String(minutes).padStart(2, '0') + ':' + String(remaining).padStart(2, '0');
        }
    }

    const init = () => {
        document.querySelectorAll('[data-region="videoprogress"]').forEach((root) => {
            try {
                const config = JSON.parse(root.dataset.config || '{}');
                new Tracker(root, config).initialise();
            } catch (error) {
                Notification.exception(error);
            }
        });
    };

    return {init: init};
});
