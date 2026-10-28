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
 * manager.php
 *
 * @package   mod_videoprogress
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_videoprogress\material;

use coding_exception;
use context_module;
use core_component;
use dml_exception;
use dml_transaction_exception;
use moodle_exception;
use stdClass;

/**
 * Coordinates support material subplugins, persistence, rendering data, and deletion.
 */
class manager {
    /**
     * Discovers, instantiates, and sorts all installed subplugins of this type.
     *
     * @return array Structured data produced by the operation.
     */
    public function get_plugins(): array {
        $plugins = [];
        foreach (core_component::get_plugin_list("videoprogressmaterial") as $name => $path) {
            $classname = '\\videoprogressmaterial_' . $name . '\\plugin';
            if (class_exists($classname) && is_subclass_of($classname, plugin_base::class)) {
                $plugins[$name] = new $classname();
            }
        }
        uasort($plugins,
            static fn(plugin_base $left, plugin_base $right): int => strnatcasecmp($left->get_name(), $right->get_name()));
        return $plugins;
    }

    /**
     * Returns a validated installed subplugin instance by its short name.
     *
     * @param string $name Installed subplugin short name.
     * @return plugin_base The result produced by the operation.
     * @throws moodle_exception
     */
    public function get_plugin(string $name): plugin_base {
        $plugins = $this->get_plugins();
        if (!isset($plugins[$name])) {
            throw new moodle_exception("materialpluginmissing", "videoprogress", '', $name);
        }
        return $plugins[$name];
    }

    /**
     * Loads a support material and verifies that it belongs to the activity.
     *
     * @param int $materialid Support material identifier.
     * @param int $activityid Video Progress activity identifier.
     * @return stdClass The loaded, created, or updated database record.
     * @throws dml_exception
     */
    public function get_material(int $materialid, int $activityid): stdClass {
        global $DB;
        return $DB->get_record("videoprogress_materials", [
            "id" => $materialid,
            "videoprogressid" => $activityid,
        ], "*", MUST_EXIST);
    }

    /**
     * Prepares stored configuration and draft file areas for the edit form.
     *
     * @param stdClass|null $material Support material record.
     * @param context_module $context Module context used for permissions and File API access.
     * @param string $pluginname Installed subplugin short name.
     * @return stdClass The loaded, created, or updated database record.
     * @throws moodle_exception
     */
    public function prepare_form_data(stdClass|null $material, context_module $context, string $pluginname): stdClass {
        $data = $material ? clone $material : (object)[
            "name" => '',
            "enabled" => 1,
            "plugin" => $pluginname,
        ];
        return $this->get_plugin($pluginname)->prepare_form_data($data, $material, $context);
    }

    /**
     * Creates or updates a support material through its installed subplugin inside a transaction.
     *
     * @param int $activityid Video Progress activity identifier.
     * @param stdClass|null $material Support material record.
     * @param stdClass $data Validated input or tracking data.
     * @param context_module $context Module context used for permissions and File API access.
     * @return stdClass The loaded, created, or updated database record.
     * @throws coding_exception
     * @throws dml_transaction_exception
     * @throws dml_exception
     * @throws moodle_exception
     */
    public function save(int $activityid, stdClass|null $material, stdClass $data, context_module $context): stdClass {
        global $DB;

        $pluginname = $material ? $material->plugin : clean_param($data->plugin, PARAM_PLUGIN);
        $plugin = $this->get_plugin($pluginname);
        $transaction = $DB->start_delegated_transaction();
        $now = time();
        if ($material) {
            $record = clone $material;
            $record->name = $data->name;
            $record->enabled = empty($data->enabled) ? 0 : 1;
            $record->timemodified = $now;
            $DB->update_record("videoprogress_materials", $record);
        } else {
            $record = (object)[
                "videoprogressid" => $activityid,
                "plugin" => $pluginname,
                "name" => $data->name,
                "configdata" => '{}',
                "sortorder" => ((int)$DB->get_field_sql(
                        'SELECT MAX(sortorder) FROM {videoprogress_materials} WHERE videoprogressid = :activityid',
                        ["activityid" => $activityid]
                    )) + 10,
                "enabled" => empty($data->enabled) ? 0 : 1,
                "timecreated" => $now,
                "timemodified" => $now,
            ];
            $record->id = $DB->insert_record("videoprogress_materials", $record);
        }
        $record->configdata = json_encode($plugin->save($record, $data, $context), JSON_UNESCAPED_UNICODE);
        $record->timemodified = $now;
        $DB->update_record("videoprogress_materials", $record);
        $transaction->allow_commit();
        return $record;
    }

    /**
     * Renders installed support material cards for the student activity page.
     *
     * @param int $activityid Video Progress activity identifier.
     * @param context_module $context Module context used for permissions and File API access.
     * @param int $cmid Course module identifier.
     * @return array Structured data produced by the operation.
     * @throws dml_exception
     */
    public function get_student_cards(int $activityid, context_module $context, int $cmid): array {
        global $DB;
        $cards = [];
        $records = $DB->get_records("videoprogress_materials", [
            "videoprogressid" => $activityid,
            "enabled" => 1,
        ], 'sortorder,id');
        foreach ($records as $record) {
            try {
                $cards[] = ["html" => $this->get_plugin($record->plugin)->render_card($record, $context, $cmid)];
            } catch (moodle_exception $exception) {
                continue;
            }
        }
        return $cards;
    }

    /**
     * Builds the records, states, and action URLs used by the management template.
     *
     * @param int $activityid Video Progress activity identifier.
     * @param int $cmid Course module identifier.
     * @return array Structured data produced by the operation.
     * @throws \core\exception\moodle_exception
     * @throws coding_exception
     * @throws dml_exception
     */
    public function get_manage_data(int $activityid, int $cmid): array {
        global $DB;
        $items = [];
        foreach ($DB->get_records("videoprogress_materials", ["videoprogressid" => $activityid], 'sortorder,id') as $record) {
            $pluginavailable = true;
            try {
                $pluginname = $this->get_plugin($record->plugin)->get_name();
            } catch (moodle_exception $exception) {
                $pluginavailable = false;
                $pluginname = get_string("unavailableplugin", "videoprogress", $record->plugin);
            }
            $items[] = [
                "id" => $record->id,
                "name" => format_string($record->name),
                "pluginname" => $pluginname,
                "enabled" => (bool)$record->enabled,
                "disabled" => !$record->enabled,
                "pluginavailable" => $pluginavailable,
                "editurl" => (new \moodle_url('/mod/videoprogress/material/edit.php', [
                    "id" => $cmid, "materialid" => $record->id,
                ]))->out(false),
                "deleteurl" => (new \moodle_url('/mod/videoprogress/material/delete.php', [
                    "id" => $cmid, "materialid" => $record->id,
                ]))->out(false),
            ];
        }
        return $items;
    }

    /**
     * Deletes files owned by this support material subplugin record.
     *
     * @param stdClass $material Support material record.
     * @param context_module $context Module context used for permissions and File API access.
     * @return void This method does not return a value.
     * @throws dml_exception
     * @throws dml_transaction_exception
     */
    public function delete(stdClass $material, context_module $context): void {
        global $DB;
        $transaction = $DB->start_delegated_transaction();
        try {
            $this->get_plugin($material->plugin)->delete($material, $context);
        } catch (moodle_exception $exception) { // phpcs:disable Generic.CodeAnalysis.EmptyStatement.DetectedCatch
            // The base record must remain removable when its subplugin was uninstalled.
        }
        $DB->delete_records("videoprogress_materials", ["id" => $material->id]);
        $transaction->allow_commit();
    }

    /**
     * Deletes every record of this resource type that belongs to the activity.
     *
     * @param int $activityid Video Progress activity identifier.
     * @param context_module $context Module context used for permissions and File API access.
     * @return void This method does not return a value.
     * @throws dml_exception
     * @throws dml_transaction_exception
     */
    public function delete_all(int $activityid, context_module $context): void {
        global $DB;
        foreach ($DB->get_records("videoprogress_materials", ["videoprogressid" => $activityid]) as $material) {
            $this->delete($material, $context);
        }
    }
}
