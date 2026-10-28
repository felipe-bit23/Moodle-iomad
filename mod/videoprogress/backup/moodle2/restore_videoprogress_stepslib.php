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
 * restore_videoprogress_stepslib.php
 *
 * @package   mod_videoprogress
 * @copyright 2026 Eduardo Kraus {@link https://eduardokraus.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Restores Video Progress records, user data, mappings, and protected files.
 */
class restore_videoprogress_activity_structure_step extends restore_activity_structure_step {
    /**
     * Defines the database records, files, and user data included in the backup structure.
     *
     * @return array Structured data produced by the operation.
     */
    protected function define_structure(): array {
        $activitypath = new restore_path_element("videoprogress", '/activity/videoprogress');
        $paths = [$activitypath];
        $this->add_subplugin_structure("videoprogresssource", $activitypath);
        $paths[] = new restore_path_element("videoprogress_caption", '/activity/videoprogress/captions/caption');
        $paths[] = new restore_path_element("videoprogress_material", '/activity/videoprogress/materials/material');
        $paths[] = new restore_path_element("videoprogress_objective", '/activity/videoprogress/objectives/objective');
        $paths[] = new restore_path_element("videoprogress_point", '/activity/videoprogress/points/point');
        $paths[] = new restore_path_element("videoprogress_pointitem", '/activity/videoprogress/points/point/items/item');
        if ($this->get_setting_value("userinfo")) {
            $paths[] = new restore_path_element(
                "videoprogress_interaction",
                '/activity/videoprogress/points/point/items/item/interactions/interaction'
            );
            $paths[] = new restore_path_element("videoprogress_progress", '/activity/videoprogress/progresses/progress');
            $paths[] = new restore_path_element("videoprogress_session", '/activity/videoprogress/sessions/session');
        }
        return $this->prepare_activity_structure($paths);
    }

    /**
     * Restores the main activity record and establishes its backup identifier mapping.
     *
     * @param array $data Validated input or tracking data.
     * @return void This method does not return a value.
     */
    protected function process_videoprogress(array $data): void {
        global $DB;
        $data = (object)$data;
        $oldid = $data->id;
        $data->course = $this->get_courseid();
        $data->aggregateviewmap = null;
        $data->aggregateusermap = null;
        $data->aggregateupdated = 0;
        $data->id = $DB->insert_record("videoprogress", $data);
        $this->apply_activity_instance($data->id);
        $this->set_mapping("videoprogress", $oldid, $data->id, true);
    }

    /**
     * Restores one backed-up caption record and remaps its dependent identifiers.
     *
     * @param array $data Validated input or tracking data.
     * @return void This method does not return a value.
     */
    protected function process_videoprogress_caption(array $data): void {
        global $DB;
        $data = (object)$data;
        $oldid = $data->id;
        $data->videoprogressid = $this->get_new_parentid("videoprogress");
        $data->createdby = $this->get_mappingid("user", $data->createdby, 0);
        $data->id = $DB->insert_record("videoprogress_captions", $data);
        $this->set_mapping("videoprogress_caption", $oldid, $data->id, true);
    }

    /**
     * Restores one backed-up material record and remaps its dependent identifiers.
     *
     * @param array $data Validated input or tracking data.
     * @return void This method does not return a value.
     */
    protected function process_videoprogress_material(array $data): void {
        global $DB;
        $data = (object)$data;
        $oldid = $data->id;
        $data->videoprogressid = $this->get_new_parentid("videoprogress");
        $data->id = $DB->insert_record("videoprogress_materials", $data);
        $this->set_mapping("videoprogress_material", $oldid, $data->id, true);
    }

    /**
     * Restores one backed-up learning objective and remaps its identifier.
     *
     * @param array $data Validated objective data.
     * @return void
     */
    protected function process_videoprogress_objective(array $data): void {
        global $DB;
        $data = (object)$data;
        $oldid = $data->id;
        $data->videoprogressid = $this->get_new_parentid("videoprogress");
        $data->id = $DB->insert_record("videoprogress_objectives", $data);
        $this->set_mapping("videoprogress_objective", $oldid, $data->id, true);
    }

    /**
     * Restores one backed-up point record and remaps its dependent identifiers.
     *
     * @param array $data Validated input or tracking data.
     * @return void This method does not return a value.
     */
    protected function process_videoprogress_point(array $data): void {
        global $DB;
        $data = (object)$data;
        $oldid = $data->id;
        $data->videoprogressid = $this->get_new_parentid("videoprogress");
        $data->id = $DB->insert_record("videoprogress_points", $data);
        $this->set_mapping("videoprogress_point", $oldid, $data->id);
    }

    /**
     * Restores one backed-up pointitem record and remaps its dependent identifiers.
     *
     * @param array $data Validated input or tracking data.
     * @return void This method does not return a value.
     */
    protected function process_videoprogress_pointitem(array $data): void {
        global $DB;
        $data = (object)$data;
        $oldid = $data->id;
        $data->pointid = $this->get_new_parentid("videoprogress_point");
        $data->id = $DB->insert_record("videoprogress_pointitems", $data);
        $this->set_mapping("videoprogress_pointitem", $oldid, $data->id, true);
    }

    /**
     * Restores one backed-up interaction record and remaps its dependent identifiers.
     *
     * @param array $data Validated input or tracking data.
     * @return void This method does not return a value.
     */
    protected function process_videoprogress_interaction(array $data): void {
        global $DB;
        $data = (object)$data;
        $data->itemid = $this->get_new_parentid("videoprogress_pointitem");
        $data->userid = $this->get_mappingid("user", $data->userid, 0);
        if ($data->userid) {
            $DB->insert_record("videoprogress_interactions", $data);
        }
    }

    /**
     * Restores one backed-up progress record and remaps its dependent identifiers.
     *
     * @param array $data Validated input or tracking data.
     * @return void This method does not return a value.
     */
    protected function process_videoprogress_progress(array $data): void {
        global $DB;
        $data = (object)$data;
        $oldid = $data->id;
        $data->videoprogressid = $this->get_new_parentid("videoprogress");
        $data->userid = $this->get_mappingid("user", $data->userid, 0);
        if (!$data->userid) {
            return;
        }
        $data->id = $DB->insert_record("videoprogress_progress", $data);
        $this->set_mapping("videoprogress_progress", $oldid, $data->id);
    }

    /**
     * Restores one backed-up session record and remaps its dependent identifiers.
     *
     * @param array $data Validated input or tracking data.
     * @return void This method does not return a value.
     */
    protected function process_videoprogress_session(array $data): void {
        global $DB;
        $data = (object)$data;
        $data->videoprogressid = $this->get_new_parentid("videoprogress");
        $data->userid = $this->get_mappingid("user", $data->userid, 0);
        if (!$data->userid) {
            return;
        }
        $DB->insert_record("videoprogress_sessions", $data);
    }

    /**
     * Restores the files associated with records after the database structure has been rebuilt.
     *
     * @return void This method does not return a value.
     */
    protected function after_execute(): void {
        $this->add_related_files("mod_videoprogress", "video", null);
        $this->add_related_files("mod_videoprogress", "poster", null);
        $this->add_related_files("mod_videoprogress", "caption", "videoprogress_caption");
        $this->add_related_files("videoprogressmaterial_pdf", "document", "videoprogress_material");
        $this->add_related_files("videoprogressmaterial_html", "content", "videoprogress_material");
        $this->add_related_files("videoprogresscontent_note", "message", "videoprogress_pointitem");
        $this->add_related_files("videoprogresscontent_quiz", "question", "videoprogress_pointitem");
    }
}
