<?php
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
 * videoprogresssource_nextcloud.php
 *
 * @package   videoprogresssource_nextcloud
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

$string['invalidcontenttype'] = 'The Nextcloud share is not a video. The server must return a Content-Type starting with video/ or an HLS playlist.';
$string['invalidmedia'] = 'The Nextcloud share could not be reached or did not return a video file.';
$string['invalidurl'] = 'Enter a public Nextcloud share URL in the form https://example.com/s/ShareToken or https://example.com/index.php/s/ShareToken.';
$string['nextcloudurl'] = 'Nextcloud Share URL';
$string['nextcloudurl_help'] = 'Paste a public Nextcloud share link such as https://cloud.example.com/s/ShareToken. Video Progress converts it to a download URL and checks that the shared file is a video before saving.';
$string['pluginname'] = 'Nextcloud';
$string['privacy:metadata'] = 'The Nextcloud subplugin does not store personal data separately from the Video Progress activity.';
