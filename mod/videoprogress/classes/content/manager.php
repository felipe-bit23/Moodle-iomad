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

namespace mod_videoprogress\content;

use coding_exception;
use context_module;
use core\lock\lock_config;
use core_component;
use dml_exception;
use dml_transaction_exception;
use invalid_parameter_exception;
use mod_videoprogress\segment_manager;
use moodle_exception;
use moodle_url;
use stdClass;

/**
 * Coordinates timeline points, synchronized content subplugins, interactions, and server-side blocking.
 */
class manager {
    /**
     * Discovers, instantiates, and sorts all installed subplugins of this type.
     *
     * @return array Structured data produced by the operation.
     */
    public function get_plugins(): array {
        $plugins = [];
        foreach (core_component::get_plugin_list("videoprogresscontent") as $name => $path) {
            $classname = '\\videoprogresscontent_' . $name . '\\plugin';
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
            throw new moodle_exception("contentpluginmissing", "videoprogress", '', $name);
        }
        return $plugins[$name];
    }

    /**
     * Loads a timeline point and verifies that it belongs to the activity.
     *
     * @param int $pointid Video timeline point identifier.
     * @param int $activityid Video Progress activity identifier.
     * @return stdClass The loaded, created, or updated database record.
     * @throws dml_exception
     */
    public function get_point(int $pointid, int $activityid): stdClass {
        global $DB;
        return $DB->get_record("videoprogress_points", [
            "id" => $pointid,
            "videoprogressid" => $activityid,
        ], "*", MUST_EXIST);
    }

    /**
     * Loads a synchronized content item and verifies that it belongs to the activity.
     *
     * @param int $itemid Synchronized content item identifier.
     * @param int $activityid Video Progress activity identifier.
     * @return stdClass The loaded, created, or updated database record.
     * @throws dml_exception
     */
    public function get_item(int $itemid, int $activityid): stdClass {
        global $DB;
        $sql = "SELECT i.*, p.videoprogressid, p.timepoint, p.title AS pointtitle,
                       p.enabled AS pointenabled
                  FROM {videoprogress_pointitems} i
                  JOIN {videoprogress_points} p ON p.id = i.pointid
                 WHERE i.id = :itemid AND p.videoprogressid = :activityid";
        return $DB->get_record_sql($sql, ["itemid" => $itemid, "activityid" => $activityid], MUST_EXIST);
    }

    /**
     * Creates or updates a named time point on the video timeline.
     *
     * @param int $activityid Video Progress activity identifier.
     * @param stdClass|null $point Video timeline point record.
     * @param stdClass $data Validated input or tracking data.
     * @return stdClass The loaded, created, or updated database record.
     * @throws dml_exception
     * @throws moodle_exception
     */
    public function save_point(int $activityid, stdClass|null $point, stdClass $data): stdClass {
        global $DB;
        $seconds = timecode::parse($data->timecode);
        if ($seconds === null) {
            throw new moodle_exception("invalidtimecode", "videoprogress");
        }
        $now = time();
        if ($point) {
            $point->title = $data->title;
            $point->timepoint = $seconds;
            $point->enabled = empty($data->enabled) ? 0 : 1;
            $point->timemodified = $now;
            $DB->update_record("videoprogress_points", $point);
            return $point;
        }
        $point = (object)[
            "videoprogressid" => $activityid,
            "timepoint" => $seconds,
            "title" => $data->title,
            "enabled" => empty($data->enabled) ? 0 : 1,
            "timecreated" => $now,
            "timemodified" => $now,
        ];
        $point->id = $DB->insert_record("videoprogress_points", $point);
        return $point;
    }

    /**
     * Prepares a synchronized content item and its file areas for editing.
     *
     * @param stdClass|null $item Synchronized content item record.
     * @param context_module $context Module context used for permissions and File API access.
     * @param string $pluginname Installed subplugin short name.
     * @param int $pointid Video timeline point identifier.
     * @return stdClass The loaded, created, or updated database record.
     * @throws moodle_exception
     */
    public function prepare_item_form_data(stdClass|null $item, context_module $context,
                                           string $pluginname, int $pointid): stdClass {
        $data = $item ? clone $item : (object)[
            "pointid" => $pointid,
            "plugin" => $pluginname,
            "enabled" => 1,
        ];
        return $this->get_plugin($pluginname)->prepare_form_data($data, $item, $context);
    }

    /**
     * Creates or updates a synchronized content item through its selected subplugin.
     *
     * @param stdClass $point Video timeline point record.
     * @param stdClass|null $item Synchronized content item record.
     * @param stdClass $data Validated input or tracking data.
     * @param context_module $context Module context used for permissions and File API access.
     * @return stdClass The loaded, created, or updated database record.
     * @throws coding_exception
     * @throws dml_transaction_exception
     * @throws dml_exception
     * @throws moodle_exception
     */
    public function save_item(stdClass $point, stdClass|null $item, stdClass $data,
                              context_module $context): stdClass {
        global $DB;
        $pluginname = $item ? $item->plugin : clean_param($data->plugin, PARAM_PLUGIN);
        $plugin = $this->get_plugin($pluginname);
        $transaction = $DB->start_delegated_transaction();
        $now = time();
        if ($item) {
            $record = clone $item;
            $record->enabled = empty($data->enabled) ? 0 : 1;
            $record->timemodified = $now;
            $DB->update_record("videoprogress_pointitems", $record);
        } else {
            $record = (object)[
                "pointid" => $point->id,
                "plugin" => $pluginname,
                "configdata" => '{}',
                "sortorder" => ((int)$DB->get_field_sql(
                        'SELECT MAX(sortorder) FROM {videoprogress_pointitems} WHERE pointid = :pointid',
                        ["pointid" => $point->id]
                    )) + 10,
                "enabled" => empty($data->enabled) ? 0 : 1,
                "timecreated" => $now,
                "timemodified" => $now,
            ];
            $record->id = $DB->insert_record("videoprogress_pointitems", $record);
        }
        $record->configdata = json_encode($plugin->save($record, $data, $context), JSON_UNESCAPED_UNICODE);
        $record->timemodified = $now;
        $DB->update_record("videoprogress_pointitems", $record);
        $transaction->allow_commit();
        return $record;
    }

    /**
     * Builds sidebar points, overlays, and browser-safe interaction configuration for a student.
     *
     * @param int $activityid Video Progress activity identifier.
     * @param int $userid Target user identifier.
     * @param context_module $context Module context used for permissions and File API access.
     * @param stdClass $progress Persisted student progress record.
     * @return array Structured data produced by the operation.
     * @throws coding_exception
     * @throws dml_exception
     */
    public function get_student_data(int $activityid, int $userid, context_module $context,
                                     stdClass $progress): array {
        global $DB;
        $pointrecords = $DB->get_records("videoprogress_points", [
            "videoprogressid" => $activityid,
            "enabled" => 1,
        ], 'timepoint,id');
        if (!$pointrecords) {
            return ["points" => [], "overlays" => [], "client" => ["points" => []]];
        }
        $items = $DB->get_records_sql(
            "SELECT i.*
               FROM {videoprogress_pointitems} i
               JOIN {videoprogress_points} p ON p.id = i.pointid
              WHERE p.videoprogressid = :activityid AND p.enabled = 1 AND i.enabled = 1
           ORDER BY p.timepoint, p.id, i.sortorder, i.id",
            ["activityid" => $activityid]
        );
        $interactions = [];
        if ($items) {
            [$insql, $params] = $DB->get_in_or_equal(array_keys($items), SQL_PARAMS_NAMED, "item");
            $params["userid"] = $userid;
            $interactions = $DB->get_records_select(
                "videoprogress_interactions",
                "userid = :userid AND itemid {$insql}",
                $params
            );
        }
        $byitem = [];
        foreach ($interactions as $interaction) {
            $byitem[$interaction->itemid] = $interaction;
        }
        $grouped = [];
        foreach ($items as $item) {
            $grouped[$item->pointid][] = $item;
        }
        $segments = segment_manager::decode($progress->watchedsegments ?? '');
        $points = [];
        $clientpoints = [];
        $overlays = [];
        foreach ($pointrecords as $point) {
            $clientitems = [];
            $requiredcomplete = true;
            $hasrequired = false;
            $displayitems = [];
            foreach ($grouped[$point->id] ?? [] as $item) {
                try {
                    $plugin = $this->get_plugin($item->plugin);
                } catch (moodle_exception $exception) {
                    continue;
                }
                $config = $this->decode_config($item->configdata);
                $completed = !empty($byitem[$item->id]->completed);
                $required = $plugin->is_required($config);
                $hasrequired = $hasrequired || $required;
                if ($required && !$completed) {
                    $requiredcomplete = false;
                }
                $displayitems[] = [
                    "name" => $plugin->get_name(),
                    "completed" => $completed,
                    "required" => $required,
                ];
                $clientitems[] = [
                    "id" => (int)$item->id,
                    "plugin" => $item->plugin,
                    "module" => $plugin->get_amd_module(),
                    "required" => $required,
                    "pause" => $plugin->pauses_video($config),
                    "completed" => $completed,
                    "data" => $plugin->get_client_data($config),
                ];
                $overlays[] = ["html" => $plugin->render_overlay($item, $point, $context)];
            }
            $watched = segment_manager::contains_position($segments, (float)$point->timepoint, 1.0);
            $complete = $watched && (!$hasrequired || $requiredcomplete);
            $points[] = [
                "id" => (int)$point->id,
                "title" => format_string($point->title),
                "timecode" => timecode::format((float)$point->timepoint),
                "timepoint" => (float)$point->timepoint,
                "items" => $displayitems,
                "hasitems" => (bool)$displayitems,
                "completed" => $complete,
                "pending" => !$complete,
            ];
            $clientpoints[] = [
                "id" => (int)$point->id,
                "time" => (float)$point->timepoint,
                "items" => $clientitems,
            ];
        }
        return [
            "points" => $points,
            "overlays" => $overlays,
            "client" => ["points" => $clientpoints],
        ];
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
        $points = [];
        $items = $DB->get_records_sql(
            "SELECT i.*
               FROM {videoprogress_pointitems} i
               JOIN {videoprogress_points} p ON p.id = i.pointid
              WHERE p.videoprogressid = :activityid
           ORDER BY i.sortorder, i.id",
            ["activityid" => $activityid]
        );
        $grouped = [];
        foreach ($items as $item) {
            $grouped[$item->pointid][] = $item;
        }
        foreach ($DB->get_records("videoprogress_points", ["videoprogressid" => $activityid], 'timepoint,id') as $point) {
            $pointitems = [];
            foreach ($grouped[$point->id] ?? [] as $item) {
                try {
                    $pluginname = $this->get_plugin($item->plugin)->get_name();
                } catch (moodle_exception $exception) {
                    $pluginname = get_string("unavailableplugin", "videoprogress", $item->plugin);
                }
                $pointitems[] = [
                    "name" => $pluginname,
                    "enabled" => (bool)$item->enabled,
                    "disabled" => !$item->enabled,
                    "editurl" => (new moodle_url('/mod/videoprogress/content/item.php', [
                        "id" => $cmid, "itemid" => $item->id,
                    ]))->out(false),
                    "deleteurl" => (new moodle_url('/mod/videoprogress/content/delete.php', [
                        "id" => $cmid, "itemid" => $item->id, "type" => "item",
                    ]))->out(false),
                ];
            }
            $points[] = [
                "id" => (int)$point->id,
                "title" => format_string($point->title),
                "timecode" => timecode::format((float)$point->timepoint),
                "enabled" => (bool)$point->enabled,
                "disabled" => !$point->enabled,
                "items" => $pointitems,
                "hasitems" => (bool)$pointitems,
                "editurl" => (new moodle_url('/mod/videoprogress/content/point.php', [
                    "id" => $cmid, "pointid" => $point->id,
                ]))->out(false),
                "deleteurl" => (new moodle_url('/mod/videoprogress/content/delete.php', [
                    "id" => $cmid, "pointid" => $point->id, "type" => "point",
                ]))->out(false),
                "addurls" => $this->plugin_add_urls($cmid, $point->id),
            ];
        }
        return $points;
    }

    /**
     * Finds the earliest required interaction that prevents tracking beyond its time point.
     *
     * @param int $activityid Video Progress activity identifier.
     * @param int $userid Target user identifier.
     * @param float $position Video position in seconds.
     * @return stdClass|null The loaded, created, or updated database record.
     * @throws dml_exception
     */
    public function get_blocking_item(int $activityid, int $userid, float $position): stdClass|null {
        global $DB;
        $sql = "SELECT i.*, p.timepoint, p.videoprogressid, x.completed
                  FROM {videoprogress_pointitems} i
                  JOIN {videoprogress_points} p ON p.id = i.pointid
             LEFT JOIN {videoprogress_interactions} x ON x.itemid = i.id AND x.userid = :userid
                 WHERE p.videoprogressid = :activityid AND p.enabled = 1 AND i.enabled = 1
                       AND p.timepoint <= :position AND (x.id IS NULL OR x.completed = 0)
              ORDER BY p.timepoint, p.id, i.sortorder, i.id";
        foreach ($DB->get_records_sql($sql, [
            "userid" => $userid,
            "activityid" => $activityid,
            "position" => $position + 0.5,
        ]) as $item) {
            try {
                $plugin = $this->get_plugin($item->plugin);
                if ($plugin->is_required($this->decode_config($item->configdata))) {
                    return $item;
                }
            } catch (moodle_exception $exception) {
                continue;
            }
        }
        return null;
    }

    /**
     * Validates and stores a student response for a synchronized video interaction.
     *
     * @param stdClass $activity Activity configuration record.
     * @param int $userid Target user identifier.
     * @param int $itemid Synchronized content item identifier.
     * @param string $rawresponse Raw JSON response submitted by the browser.
     * @return array Structured data produced by the operation.
     * @throws invalid_parameter_exception
     * @throws coding_exception
     * @throws dml_exception
     * @throws moodle_exception
     */
    public function complete_item(stdClass $activity, int $userid, int $itemid, string $rawresponse): array {
        global $DB;
        $item = $this->get_item($itemid, $activity->id);
        if (empty($item->enabled) || empty($item->pointenabled)) {
            throw new moodle_exception("interactiondisabled", "videoprogress");
        }
        $response = json_decode($rawresponse, true);
        if (!is_array($response)) {
            throw new invalid_parameter_exception(get_string("invalidinteractionresponse", "videoprogress"));
        }
        $progress = $DB->get_record("videoprogress_progress", [
            "videoprogressid" => $activity->id,
            "userid" => $userid,
        ]);
        $segments = segment_manager::decode($progress->watchedsegments ?? '');
        $reached = $progress && ((float)$progress->lastposition >= (float)$item->timepoint - 15 ||
                segment_manager::contains_position($segments, (float)$item->timepoint, 15));
        if (!$reached) {
            throw new moodle_exception("pointnotreached", "videoprogress");
        }
        $plugin = $this->get_plugin($item->plugin);
        $result = $plugin->validate_response($this->decode_config($item->configdata), $response);
        $factory = lock_config::get_lock_factory("mod_videoprogress");
        $lock = $factory->get_lock('interaction:' . $item->id . ':' . $userid, 10);
        if (!$lock) {
            throw new moodle_exception("interactionlocktimeout", "videoprogress");
        }
        try {
            $now = time();
            $record = $DB->get_record("videoprogress_interactions", [
                "itemid" => $item->id,
                "userid" => $userid,
            ]);
            if (!$record) {
                $record = (object)[
                    "itemid" => $item->id,
                    "userid" => $userid,
                    "completed" => 0,
                    "attempts" => 0,
                    "lastresponse" => '',
                    "timecompleted" => 0,
                    "timecreated" => $now,
                    "timemodified" => $now,
                ];
            }
            $record->attempts++;
            $record->lastresponse = substr(json_encode($response, JSON_UNESCAPED_UNICODE), 0, 2000);
            $record->timemodified = $now;
            if (!empty($result["completed"])) {
                $record->completed = 1;
                $record->timecompleted = $record->timecompleted ?: $now;
            }
            if (empty($record->id)) {
                $record->id = $DB->insert_record("videoprogress_interactions", $record);
            } else {
                $DB->update_record("videoprogress_interactions", $record);
            }
        } finally {
            $lock->release();
        }
        return [
            "completed" => (bool)$record->completed,
            "feedback" => (string)($result["feedback"] ?? ''),
            "attempts" => $record->attempts,
        ];
    }

    /**
     * Deletes a synchronized content item, its files, and all student interactions.
     *
     * @param stdClass $item Synchronized content item record.
     * @param context_module $context Module context used for permissions and File API access.
     * @return void This method does not return a value.
     * @throws dml_exception
     * @throws dml_transaction_exception
     */
    public function delete_item(stdClass $item, context_module $context): void {
        global $DB;
        $transaction = $DB->start_delegated_transaction();
        try {
            $this->get_plugin($item->plugin)->delete($item, $context);
        } catch (moodle_exception $exception) { // phpcs:disable Generic.CodeAnalysis.EmptyStatement.DetectedCatch
            // The base item must remain removable when its subplugin was uninstalled.
        }
        $DB->delete_records("videoprogress_interactions", ["itemid" => $item->id]);
        $DB->delete_records("videoprogress_pointitems", ["id" => $item->id]);
        $transaction->allow_commit();
    }

    /**
     * Deletes a timeline point together with every content item attached to it.
     *
     * @param stdClass $point Video timeline point record.
     * @param context_module $context Module context used for permissions and File API access.
     * @return void This method does not return a value.
     * @throws dml_exception
     * @throws dml_transaction_exception
     */
    public function delete_point(stdClass $point, context_module $context): void {
        global $DB;
        foreach ($DB->get_records("videoprogress_pointitems", ["pointid" => $point->id]) as $item) {
            $this->delete_item($item, $context);
        }
        $DB->delete_records("videoprogress_points", ["id" => $point->id]);
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
        foreach ($DB->get_records("videoprogress_points", ["videoprogressid" => $activityid]) as $point) {
            $this->delete_point($point, $context);
        }
    }

    /**
     * Builds one add-action URL for every installed synchronized content subplugin.
     *
     * @param int $cmid Course module identifier.
     * @param int $pointid Video timeline point identifier.
     * @return array Structured data produced by the operation.
     * @throws \core\exception\moodle_exception
     */
    private function plugin_add_urls(int $cmid, int $pointid): array {
        $urls = [];
        foreach ($this->get_plugins() as $name => $plugin) {
            $urls[] = [
                "name" => $plugin->get_name(),
                "url" => (new moodle_url('/mod/videoprogress/content/item.php', [
                    "id" => $cmid, "pointid" => $pointid, "plugin" => $name,
                ]))->out(false),
            ];
        }
        return $urls;
    }

    /**
     * Decodes a subplugin configuration JSON value into a safe associative array.
     *
     * @param string|null $json json value used by the operation.
     * @return array Structured data produced by the operation.
     */
    private function decode_config(string|null $json): array {
        $config = json_decode($json ?? '', true);
        return is_array($config) ? $config : [];
    }
}
