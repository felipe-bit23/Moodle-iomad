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
 * External link support material implementation.
 *
 * @package videoprogressmaterial_link
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace videoprogressmaterial_link;

use context_module;
use mod_videoprogress\material\plugin_base;
use moodle_url;
use MoodleQuickForm;
use stdClass;

/**
 * Provides an external reference link as support material.
 */
class plugin extends plugin_base {
    /**
     * get_name
     *
     * @return string
     * @throws \coding_exception
     */
    public function get_name(): string {
        return get_string("pluginname", "videoprogressmaterial_link");
    }

    /**
     * add_form_elements
     *
     * @param MoodleQuickForm $mform
     * @return void
     * @throws \coding_exception
     */
    public function add_form_elements(MoodleQuickForm $mform): void {
        $mform->addElement("text", "externalurl", get_string("externalurl", "videoprogressmaterial_link"), ["size" => 80]);
        $mform->setType("externalurl", PARAM_URL);
        $mform->addRule("externalurl", null, "required", null, "client");
        $mform->addHelpButton("externalurl", "externalurl", "videoprogressmaterial_link");
        $mform->addElement("advcheckbox", "opennewwindow", get_string("opennewwindow", "videoprogressmaterial_link"));
        $mform->setDefault("opennewwindow", 1);
    }

    /**
     * prepare_form_data
     *
     * @param stdClass $data
     * @param stdClass|null $material
     * @param context_module $context
     * @return stdClass
     */
    public function prepare_form_data(stdClass $data, stdClass|null $material, context_module $context): stdClass {
        $config = $this->decode_config($material);
        $data->externalurl = $config["url"] ?? '';
        $data->opennewwindow = !isset($config["opennewwindow"]) || !empty($config["opennewwindow"]) ? 1 : 0;
        return $data;
    }

    /**
     * validation
     *
     * @param array $data
     * @param array $files
     * @param stdClass|null $material
     * @param context_module $context
     * @return array
     * @throws \coding_exception
     */
    public function validation(array $data, array $files, stdClass|null $material, context_module $context): array {
        $url = trim((string)($data["externalurl"] ?? ''));
        if ($url === '') {
            return ["externalurl" => get_string("required")];
        }
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return ["externalurl" => get_string("invalidurl", "videoprogressmaterial_link")];
        }
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return ["externalurl" => get_string("invalidurl", "videoprogressmaterial_link")];
        }
        return [];
    }

    /**
     * save
     *
     * @param stdClass $material
     * @param stdClass $data
     * @param context_module $context
     * @return array
     * @throws \coding_exception
     */
    public function save(stdClass $material, stdClass $data, context_module $context): array {
        return [
            "url" => clean_param($data->externalurl, PARAM_URL),
            "opennewwindow" => !empty($data->opennewwindow),
        ];
    }

    /**
     * render_card
     *
     * @param stdClass $material
     * @param context_module $context
     * @param int $cmid
     * @return string
     * @throws \core\exception\moodle_exception
     */
    public function render_card(stdClass $material, context_module $context, int $cmid): string {
        global $OUTPUT;

        return $OUTPUT->render_from_template('videoprogressmaterial_link/card', [
            "name" => format_string($material->name),
            "viewurl" => (new moodle_url('/mod/videoprogress/material/view.php', [
                "id" => $cmid,
                "materialid" => $material->id,
            ]))->out(false),
        ]);
    }

    /**
     * render_full
     *
     * @param stdClass $material
     * @param context_module $context
     * @param int $cmid
     * @return string
     */
    public function render_full(stdClass $material, context_module $context, int $cmid): string {
        global $OUTPUT;

        $config = $this->decode_config($material);
        $url = (string)($config["url"] ?? '');
        $host = $url !== '' ? (string)parse_url($url, PHP_URL_HOST) : '';
        return $OUTPUT->render_from_template('videoprogressmaterial_link/view', [
            "name" => format_string($material->name),
            "hasurl" => $url !== '',
            "externalurl" => $url,
            "host" => $host,
            "opennewwindow" => !empty($config["opennewwindow"]),
        ]);
    }
}
