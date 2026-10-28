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

namespace mod_videoprogress\source;

use coding_exception;
use context_module;
use core_collator;
use core_component;
use moodle_exception;
use MoodleQuickForm;
use stdClass;

/**
 * Discovers video source subplugins and delegates forms, persistence, files, and player configuration.
 */
class manager {
    /** @var plugin_base[]|null Cached source plugin instances indexed by short name. */
    private array|null $plugins = null;

    /**
     * Returns every installed and valid video source subplugin ordered by display name.
     *
     * @return plugin_base[] Source plugins indexed by short name.
     * @throws coding_exception
     */
    public function get_plugins(): array {
        if ($this->plugins !== null) {
            return $this->plugins;
        }
        $this->plugins = [];
        foreach (array_keys(core_component::get_plugin_list("videoprogresssource")) as $name) {
            $class = '\\videoprogresssource_' . $name . '\\plugin';
            if (!class_exists($class) || !is_subclass_of($class, plugin_base::class)) {
                debugging(get_string("invalidsourceplugin", "videoprogress", $name), DEBUG_DEVELOPER);
                continue;
            }
            $this->plugins[$name] = new $class();
        }
        $groups = [];
        foreach ($this->plugins as $name => $plugin) {
            $groups[$plugin->get_sort_order()][$name] = $plugin->get_name();
        }
        ksort($groups, SORT_NUMERIC);
        $sortedplugins = [];
        foreach ($groups as $displaynames) {
            core_collator::asort($displaynames);
            foreach (array_keys($displaynames) as $name) {
                $sortedplugins[$name] = $this->plugins[$name];
            }
        }
        $this->plugins = $sortedplugins;
        return $this->plugins;
    }

    /**
     * Returns one installed source plugin or throws a localized exception when unavailable.
     *
     * @param string $name Source plugin short name.
     * @return plugin_base Resolved source plugin.
     * @throws moodle_exception
     * @throws coding_exception
     */
    public function get_plugin(string $name): plugin_base {
        $plugins = $this->get_plugins();
        if (!isset($plugins[$name])) {
            throw new moodle_exception("sourcepluginmissing", "videoprogress", '', $name);
        }
        return $plugins[$name];
    }

    /**
     * Returns localized source options for the activity form selector.
     *
     * @return array Source short names mapped to localized names.
     * @throws coding_exception
     */
    public function get_options(): array {
        $options = [];
        foreach ($this->get_plugins() as $name => $plugin) {
            $options[$name] = $plugin->get_name();
        }
        return $options;
    }

    /**
     * Returns the first available source according to subplugin-defined ordering.
     *
     * @return string Default source short name.
     * @throws coding_exception
     */
    public function get_default_source(): string {
        return (string)(array_key_first($this->get_plugins()) ?? '');
    }

    /**
     * Adds every installed source's fields and conditional visibility rules to the activity form.
     *
     * @param MoodleQuickForm $mform Activity form receiving source fields.
     * @param string $sourcefield Name of the source selector field.
     * @return void
     * @throws coding_exception
     */
    public function add_form_elements(MoodleQuickForm $mform, string $sourcefield): void {
        foreach ($this->get_plugins() as $plugin) {
            $plugin->add_form_elements($mform, $sourcefield);
        }
    }

    /**
     * Delegates form validation exclusively to the selected source plugin.
     *
     * @param array $data Submitted activity form values.
     * @param array $files Submitted activity form files.
     * @return array Field names mapped to localized validation errors.
     * @throws coding_exception
     */
    public function validation(array $data, array $files): array {
        $source = clean_param((string)($data["videosource"] ?? ''), PARAM_PLUGIN);
        try {
            return $this->get_plugin($source)->validation($data, $files);
        } catch (moodle_exception $exception) {
            return ["videosource" => $exception->getMessage()];
        }
    }

    /**
     * Normalizes the selected source configuration before the activity record is stored.
     *
     * @param stdClass $data Submitted activity data.
     * @return void
     * @throws coding_exception
     * @throws moodle_exception
     */
    public function normalise_record(stdClass $data): void {
        $plugin = $this->get_plugin(clean_param((string)$data->videosource, PARAM_PLUGIN));
        $config = $plugin->build_config($data);
        $data->sourceconfig = json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $data->videourl = $plugin->get_legacy_value($config);
    }

    /**
     * Prepares edit-form values using the source currently stored by the activity.
     *
     * @param array $defaultvalues Values passed to the activity form.
     * @param context_module $context Module context used for File API access.
     * @return void
     * @throws coding_exception
     * @throws moodle_exception
     */
    public function prepare_form_data(array &$defaultvalues, context_module $context): void {
        $source = clean_param((string)($defaultvalues["videosource"] ?? ''), PARAM_PLUGIN);
        if ($source !== '') {
            $this->get_plugin($source)->prepare_form_data($defaultvalues, $context);
        }
    }

    /**
     * Saves selected-source files and removes files belonging to a source that was replaced.
     *
     * @param stdClass $data Saved activity data.
     * @param context_module $context Module context used for File API access.
     * @param string|null $previoussource Previously selected source short name.
     * @return void
     * @throws coding_exception
     * @throws moodle_exception
     */
    public function save_files(stdClass $data, context_module $context, string|null $previoussource = null): void {
        $source = clean_param((string)$data->videosource, PARAM_PLUGIN);
        if ($previoussource && $previoussource !== $source) {
            $plugins = $this->get_plugins();
            if (isset($plugins[$previoussource])) {
                $plugins[$previoussource]->delete_files($context);
            }
        }
        $this->get_plugin($source)->save_files($data, $context);
    }

    /**
     * Removes files owned by every installed source when an activity is deleted.
     *
     * @param context_module $context Module context whose source files must be removed.
     * @return void
     * @throws coding_exception
     */
    public function delete_files(context_module $context): void {
        foreach ($this->get_plugins() as $plugin) {
            $plugin->delete_files($context);
        }
    }

    /**
     * Returns source names that rely on provider-managed posters rather than uploaded images.
     *
     * @return array Source short names without custom poster support.
     * @throws coding_exception
     */
    public function get_sources_without_poster(): array {
        return array_keys(array_filter(
            $this->get_plugins(),
            static fn(plugin_base $plugin): bool => !$plugin->supports_poster()
        ));
    }

    /**
     * Returns source names that rely on provider-managed captions rather than uploaded tracks.
     *
     * @return array Source short names without uploaded caption support.
     * @throws coding_exception
     */
    public function get_sources_without_uploaded_captions(): array {
        return array_keys(array_filter(
            $this->get_plugins(),
            static fn(plugin_base $plugin): bool => !$plugin->supports_uploaded_captions()
        ));
    }

    /**
     * Builds source-specific player data and identifies its Mustache and AMD implementations.
     *
     * @param stdClass $activity Activity configuration record.
     * @param context_module $context Module context used for File API access.
     * @return array Source player configuration.
     * @throws coding_exception
     * @throws moodle_exception
     */
    public function get_player_config(stdClass $activity, context_module $context): array {
        $plugin = $this->get_plugin(clean_param((string)$activity->videosource, PARAM_PLUGIN));
        return $plugin->get_player_config($activity, $context) + [
                "source" => $activity->videosource,
                "adaptermodule" => $plugin->get_amd_module(),
                "sourcetemplate" => $plugin->get_player_template(),
            ];
    }
}
