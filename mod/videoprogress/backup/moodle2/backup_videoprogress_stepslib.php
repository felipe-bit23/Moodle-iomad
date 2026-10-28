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
 * backup_videoprogress_stepslib.php
 *
 * @package   mod_videoprogress
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Builds the complete Moodle backup structure for Video Progress records and files.
 */
class backup_videoprogress_activity_structure_step extends backup_activity_structure_step {
    /**
     * Defines the database records, files, and user data included in the backup structure.
     *
     * @return backup_nested_element The result produced by the operation.
     */
    protected function define_structure(): backup_nested_element {
        $userinfo = $this->get_setting_value("userinfo");
        $activity = new backup_nested_element("videoprogress", ["id"], [
            "name", "intro", "introformat", "videosource", "videourl", "sourceconfig", "resumeplayback",
            "allowseek", "maxplaybackrate", "disabledownload", "disablepip", "disablecontextmenu",
            "completionpercent", "requireconfirmation", "grade", "timecreated", "timemodified",
        ]);
        $captions = new backup_nested_element("captions");
        $caption = new backup_nested_element("caption", ["id"], [
            "language", "label", "isdefault", "status", "source", "sourceurl", "createdby", "timecreated", "timemodified",
        ]);
        $materials = new backup_nested_element("materials");
        $material = new backup_nested_element("material", ["id"], [
            "plugin", "name", "configdata", "sortorder", "enabled", "timecreated", "timemodified",
        ]);
        $objectives = new backup_nested_element("objectives");
        $objective = new backup_nested_element("objective", ["id"], [
            "plugin", "configdata", "sortorder", "enabled", "timecreated", "timemodified",
        ]);
        $points = new backup_nested_element("points");
        $point = new backup_nested_element("point", ["id"], [
            "timepoint", "title", "enabled", "timecreated", "timemodified",
        ]);
        $items = new backup_nested_element("items");
        $item = new backup_nested_element("item", ["id"], [
            "plugin", "configdata", "sortorder", "enabled", "timecreated", "timemodified",
        ]);
        $interactions = new backup_nested_element("interactions");
        $interaction = new backup_nested_element("interaction", ["id"], [
            "userid", "completed", "attempts", "lastresponse", "timecompleted", "timecreated", "timemodified",
        ]);
        $progresses = new backup_nested_element("progresses");
        $progress = new backup_nested_element("progress", ["id"], [
            "userid", "duration", "lastposition", "uniquewatched", "totalwatchtime", "percent",
            "watchedsegments", "viewmap", "completed", "confirmation", "confirmationtime", "sequence",
            "timecreated", "timemodified",
        ]);
        $sessions = new backup_nested_element("sessions");
        $session = new backup_nested_element("session", ["id"], [
            "userid", "sessionkey", "timestart", "timeend", "watchtime", "startposition", "endposition",
            "lastposition", "sequence", "lastheartbeat", "lastclienttime", "playerstate", "timecreated", "timemodified",
        ]);
        $activity->add_child($captions);
        $captions->add_child($caption);
        $activity->add_child($materials);
        $materials->add_child($material);
        $activity->add_child($objectives);
        $objectives->add_child($objective);
        $activity->add_child($points);
        $points->add_child($point);
        $point->add_child($items);
        $items->add_child($item);
        $item->add_child($interactions);
        $interactions->add_child($interaction);
        $activity->add_child($progresses);
        $progresses->add_child($progress);
        $activity->add_child($sessions);
        $sessions->add_child($session);
        $activity->set_source_table("videoprogress", ["id" => backup::VAR_ACTIVITYID]);
        $this->add_subplugin_structure("videoprogresssource", $activity, true);
        $caption->set_source_table("videoprogress_captions", ["videoprogressid" => backup::VAR_PARENTID]);
        $material->set_source_table("videoprogress_materials", ["videoprogressid" => backup::VAR_PARENTID]);
        $objective->set_source_table("videoprogress_objectives", ["videoprogressid" => backup::VAR_PARENTID]);
        $point->set_source_table("videoprogress_points", ["videoprogressid" => backup::VAR_PARENTID]);
        $item->set_source_table("videoprogress_pointitems", ["pointid" => backup::VAR_PARENTID]);
        if ($userinfo) {
            $interaction->set_source_table("videoprogress_interactions", ["itemid" => backup::VAR_PARENTID]);
            $progress->set_source_table("videoprogress_progress", ["videoprogressid" => backup::VAR_PARENTID]);
            $session->set_source_table("videoprogress_sessions", ["videoprogressid" => backup::VAR_PARENTID]);
        }
        $caption->annotate_ids("user", "createdby");
        $progress->annotate_ids("user", "userid");
        $session->annotate_ids("user", "userid");
        $interaction->annotate_ids("user", "userid");
        $activity->annotate_files("mod_videoprogress", "video", null);
        $activity->annotate_files("mod_videoprogress", "poster", null);
        $caption->annotate_files("mod_videoprogress", "caption", "id");
        $material->annotate_files("videoprogressmaterial_pdf", "document", "id");
        $material->annotate_files("videoprogressmaterial_html", "content", "id");
        $item->annotate_files("videoprogresscontent_note", "message", "id");
        $item->annotate_files("videoprogresscontent_quiz", "question", "id");
        return $this->prepare_activity_structure($activity);
    }
}
