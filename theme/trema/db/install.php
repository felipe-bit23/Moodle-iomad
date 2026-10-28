<?php
defined('MOODLE_INTERNAL') || die();

function xmldb_theme_trema_install() {
    global $DB, $CFG;

    require_once($CFG->dirroot . '/theme/trema/locallib.php');
    theme_trema_auto_heal_config(true);

    $usertours = $DB->get_records('tool_usertours_tours');
    if (!empty($usertours) && is_iterable($usertours)) {
        foreach ($usertours as $usertour) {
            $configdata = json_decode($usertour->configdata);
            if (isset($configdata->filtervalues->theme) && in_array('boost', $configdata->filtervalues->theme)) {
                $configdata->filtervalues->theme[] = 'trema';
            }
            $updatedata = new stdClass();
            $updatedata->id = $usertour->id;
            $updatedata->configdata = json_encode($configdata);
            $DB->update_record('tool_usertours_tours', $updatedata);
        }
    }

    return true;
}
