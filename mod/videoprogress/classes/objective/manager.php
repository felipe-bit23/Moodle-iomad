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

namespace mod_videoprogress\objective;

use coding_exception;
use context_module;
use core_component;
use dml_exception;
use dml_transaction_exception;
use moodle_exception;
use moodle_url;
use stdClass;

/**
 * Coordinates objective type subplugins, persistence, rendering, and deletion.
 */
class manager {
    /**
     * Discovers and sorts all installed learning objective type subplugins.
     *
     * @return array Installed plugins indexed by short name.
     */
    public function get_plugins(): array {
        $plugins = [];
        foreach (core_component::get_plugin_list("videoprogressobjective") as $name => $path) {
            $classname = '\\videoprogressobjective_' . $name . '\\plugin';
            if (class_exists($classname) && is_subclass_of($classname, plugin_base::class)) {
                $plugins[$name] = new $classname();
            }
        }
        uasort($plugins,
            static fn(plugin_base $left, plugin_base $right): int => strnatcasecmp($left->get_name(), $right->get_name()));
        return $plugins;
    }

    /**
     * Returns an installed objective type plugin by its validated short name.
     *
     * @param string $name Objective type short name.
     * @return plugin_base Objective type plugin.
     * @throws moodle_exception When the requested type is unavailable.
     */
    public function get_plugin(string $name): plugin_base {
        $plugins = $this->get_plugins();
        if (!isset($plugins[$name])) {
            throw new moodle_exception("objectivepluginmissing", "videoprogress", '', $name);
        }
        return $plugins[$name];
    }

    /**
     * Loads an objective and verifies that it belongs to the activity.
     *
     * @param int $objectiveid Objective identifier.
     * @param int $activityid Video Progress activity identifier.
     * @return stdClass Objective database record.
     * @throws dml_exception
     */
    public function get_objective(int $objectiveid, int $activityid): stdClass {
        global $DB;
        return $DB->get_record("videoprogress_objectives", [
            "id" => $objectiveid,
            "videoprogressid" => $activityid,
        ], "*", MUST_EXIST);
    }

    /**
     * Prepares base and type-specific values for the objective form.
     *
     * @param stdClass|null $objective Existing objective record.
     * @param context_module $context Activity context.
     * @param string $pluginname Objective type short name.
     * @return stdClass Prepared form data.
     * @throws moodle_exception
     */
    public function prepare_form_data(
        stdClass|null $objective,
        context_module $context,
        string $pluginname
    ): stdClass {
        $data = $objective ? clone $objective : (object)[
            "enabled" => 1,
            "plugin" => $pluginname,
        ];
        return $this->get_plugin($pluginname)->prepare_form_data($data, $objective, $context);
    }

    /**
     * Creates or updates an objective through its type subplugin in a transaction.
     *
     * @param int $activityid Video Progress activity identifier.
     * @param stdClass|null $objective Existing objective record.
     * @param stdClass $data Validated form data.
     * @param context_module $context Activity context.
     * @return stdClass Saved objective record.
     * @throws coding_exception
     * @throws dml_exception
     * @throws dml_transaction_exception
     * @throws moodle_exception
     */
    public function save(
        int $activityid,
        stdClass|null $objective,
        stdClass $data,
        context_module $context
    ): stdClass {
        global $DB;

        $pluginname = $objective ? $objective->plugin : clean_param($data->plugin, PARAM_PLUGIN);
        $plugin = $this->get_plugin($pluginname);
        $transaction = $DB->start_delegated_transaction();
        $now = time();
        if ($objective) {
            $record = clone $objective;
            $record->enabled = empty($data->enabled) ? 0 : 1;
            $record->timemodified = $now;
        } else {
            $record = (object)[
                "videoprogressid" => $activityid,
                "plugin" => $pluginname,
                "configdata" => '{}',
                "sortorder" => ((int)$DB->get_field_sql(
                    'SELECT MAX(sortorder) FROM {videoprogress_objectives} WHERE videoprogressid = :activityid',
                    ["activityid" => $activityid]
                )) + 10,
                "enabled" => empty($data->enabled) ? 0 : 1,
                "timecreated" => $now,
                "timemodified" => $now,
            ];
            $record->id = $DB->insert_record("videoprogress_objectives", $record);
        }
        $record->configdata = json_encode(
            $plugin->save($record, $data, $context),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        $DB->update_record("videoprogress_objectives", $record);
        $transaction->allow_commit();
        return $record;
    }

    /**
     * Renders every enabled objective for student and report pages.
     *
     * @param int $activityid Video Progress activity identifier.
     * @param context_module $context Activity context.
     * @param int $cmid Course module identifier.
     * @return array Rendered objective items.
     * @throws dml_exception
     */
    public function get_student_items(int $activityid, context_module $context, int $cmid): array {
        global $DB;

        $items = [];
        $records = $DB->get_records("videoprogress_objectives", [
            "videoprogressid" => $activityid,
            "enabled" => 1,
        ], 'sortorder,id');
        foreach ($records as $record) {
            try {
                $items[] = ["html" => $this->get_plugin($record->plugin)->render($record, $context, $cmid)];
            } catch (moodle_exception $exception) {
                continue;
            }
        }
        return $items;
    }

    /**
     * Builds records, states, summaries, and action URLs for the management template.
     *
     * @param int $activityid Video Progress activity identifier.
     * @param int $cmid Course module identifier.
     * @param context_module $context Activity context.
     * @return array Objective management items.
     * @throws \core\exception\moodle_exception
     * @throws coding_exception
     * @throws dml_exception
     * @throws \core\exception\moodle_exception
     */
    public function get_manage_data(int $activityid, int $cmid, context_module $context): array {
        global $DB;

        $items = [];
        $records = $DB->get_records("videoprogress_objectives", ["videoprogressid" => $activityid], 'sortorder,id');
        foreach ($records as $record) {
            $pluginavailable = true;
            try {
                $plugin = $this->get_plugin($record->plugin);
                $pluginname = $plugin->get_name();
                $summary = $plugin->get_summary($record, $context);
            } catch (moodle_exception $exception) {
                $pluginavailable = false;
                $pluginname = get_string("unavailableplugin", "videoprogress", $record->plugin);
                $summary = get_string("objectivepluginmissing", "videoprogress", $record->plugin);
            }
            $items[] = [
                "id" => $record->id,
                "summary" => $summary,
                "pluginname" => $pluginname,
                "disabled" => !$record->enabled,
                "pluginavailable" => $pluginavailable,
                "editurl" => (new moodle_url('/mod/videoprogress/objective/edit.php', [
                    "id" => $cmid,
                    "objectiveid" => $record->id,
                ]))->out(false),
                "deleteurl" => (new moodle_url('/mod/videoprogress/objective/delete.php', [
                    "id" => $cmid,
                    "objectiveid" => $record->id,
                ]))->out(false),
            ];
        }
        return $items;
    }

    /**
     * Deletes an objective and any resources owned by its type subplugin.
     *
     * @param stdClass $objective Objective database record.
     * @param context_module $context Activity context.
     * @return void
     * @throws dml_exception
     * @throws dml_transaction_exception
     */
    public function delete(stdClass $objective, context_module $context): void {
        global $DB;

        $transaction = $DB->start_delegated_transaction();
        try {
            $this->get_plugin($objective->plugin)->delete($objective, $context);
        } catch (moodle_exception $exception) { // phpcs:disable Generic.CodeAnalysis.EmptyStatement.DetectedCatch
            // Keep records removable when their objective type was uninstalled.
        }
        $DB->delete_records("videoprogress_objectives", ["id" => $objective->id]);
        $transaction->allow_commit();
    }

    /**
     * Deletes all objectives belonging to an activity.
     *
     * @param int $activityid Video Progress activity identifier.
     * @param context_module $context Activity context.
     * @return void
     * @throws dml_exception
     * @throws dml_transaction_exception
     */
    public function delete_all(int $activityid, context_module $context): void {
        global $DB;

        foreach ($DB->get_records("videoprogress_objectives", ["videoprogressid" => $activityid]) as $objective) {
            $this->delete($objective, $context);
        }
    }
}
