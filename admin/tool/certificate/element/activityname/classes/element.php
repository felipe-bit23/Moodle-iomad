<?php
// This file is part of the tool_certificate plugin for Moodle - http://moodle.org/

namespace certificateelement_activityname;

defined('MOODLE_INTERNAL') || die();

/**
 * Course certificate activity name certificate element.
 *
 * @package    certificateelement_activityname
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class element extends \tool_certificate\element {

    /**
     * Renders the form elements when adding a certificate element.
     *
     * @param \MoodleQuickForm $mform the edit_form instance
     */
    public function render_form_elements($mform) {
        $options = [
            'auto_restriction' => get_string('source_auto_restriction', 'certificateelement_activityname'),
            'by_id'            => get_string('source_by_id', 'certificateelement_activityname'),
            'certificate'      => get_string('source_certificate', 'certificateelement_activityname'),
        ];

        $mform->addElement('select', 'activitysource', get_string('activitysource', 'certificateelement_activityname'), $options);
        $mform->setType('activitysource', PARAM_ALPHANUMEXT);
        $mform->setDefault('activitysource', 'auto_restriction');
        $mform->addHelpButton('activitysource', 'activitysource', 'certificateelement_activityname');

        $mform->addElement('text', 'activityid', get_string('activityid', 'certificateelement_activityname'));
        $mform->setType('activityid', PARAM_RAW_TRIMMED);
        $mform->setDefault('activityid', '');
        $mform->addHelpButton('activityid', 'activityid', 'certificateelement_activityname');
        $mform->hideIf('activityid', 'activitysource', 'neq', 'by_id');

        parent::render_form_elements($mform);
    }

    /**
     * Prepare data to pass to moodleform::set_data()
     *
     * @return \stdClass|array
     */
    public function prepare_data_for_form() {
        $record = parent::prepare_data_for_form();
        $rawdata = $this->get_data();
        if (!empty($rawdata)) {
            $decoded = json_decode($rawdata, true);
            if (is_array($decoded)) {
                $record->activitysource = $decoded['activitysource'] ?? 'auto_restriction';
                $record->activityid     = $decoded['activityid'] ?? '';
            } else {
                $record->activitysource = $rawdata;
                $record->activityid     = '';
            }
        } else {
            $record->activitysource = 'auto_restriction';
            $record->activityid     = '';
        }
        return $record;
    }

    /**
     * Handles saving the form elements created by this element.
     *
     * @param \stdClass $data
     */
    public function save_form_data(\stdClass $data) {
        $config = [
            'activitysource' => $data->activitysource ?? 'auto_restriction',
            'activityid'     => trim($data->activityid ?? ''),
        ];
        $data->data = json_encode($config);
        parent::save_form_data($data);
    }

    /**
     * Resolves the activity name for an issue or course.
     *
     * @param \stdClass|null $issue
     * @return string
     */
    protected function get_activity_name_value($issue = null) {
        global $DB;

        $rawdata = $this->get_data();
        $config = [];
        if (!empty($rawdata)) {
            $decoded = json_decode($rawdata, true);
            if (is_array($decoded)) {
                $config = $decoded;
            } else {
                $config['activitysource'] = $rawdata;
            }
        }
        $source = $config['activitysource'] ?? 'auto_restriction';
        $activityid = trim($config['activityid'] ?? '');

        if ($issue && !empty($issue->courseid)) {
            $course = get_course($issue->courseid);
            $templateid = $this->get_template()->get_id();

            // Find the coursecertificate cm for this course and template.
            $cc_cms = get_coursemodules_in_course('coursecertificate', $issue->courseid, 'm.template, m.name');
            $cc_cm = null;
            foreach ($cc_cms as $cmrec) {
                if ($cmrec->template == $templateid) {
                    $cc_cm = $cmrec;
                    break;
                }
            }

            // Mode 1: by_id (User entered specific cm ID or ID Number).
            if ($source === 'by_id' && !empty($activityid)) {
                try {
                    $modinfo = get_fast_modinfo($course);
                    // Check by CM ID (numeric)
                    if (is_numeric($activityid) && isset($modinfo->cms[(int)$activityid])) {
                        return $modinfo->cms[(int)$activityid]->name;
                    }
                    // Check by idnumber or CM ID match across all course modules
                    foreach ($modinfo->get_cms() as $cm) {
                        if ((string)$cm->id === $activityid || (string)$cm->idnumber === $activityid) {
                            return $cm->name;
                        }
                    }
                } catch (\Throwable $e) {
                }

                // Database fallback if get_fast_modinfo was not ready
                $cmrec = null;
                if (is_numeric($activityid)) {
                    $cmrec = $DB->get_record('course_modules', ['id' => (int)$activityid, 'course' => $course->id]);
                }
                if (!$cmrec) {
                    $cmrec = $DB->get_record('course_modules', ['idnumber' => $activityid, 'course' => $course->id]);
                }
                if ($cmrec) {
                    $module = $DB->get_record('modules', ['id' => $cmrec->module]);
                    if ($module) {
                        $instancename = $DB->get_field($module->name, 'name', ['id' => $cmrec->instance]);
                        if (!empty($instancename)) {
                            return $instancename;
                        }
                    }
                }
            }

            // Mode 2: auto_restriction (detect from availability restrictions).
            if ($source === 'auto_restriction') {
                if ($cc_cm && !empty($cc_cm->availability)) {
                    $avail = json_decode($cc_cm->availability, true);
                    if (is_array($avail)) {
                        $find_req = function($node) use (&$find_req, $course) {
                            if (!is_array($node)) {
                                return '';
                            }
                            if (isset($node['type']) && $node['type'] === 'completion' && !empty($node['cm'])) {
                                try {
                                    $modinfo = get_fast_modinfo($course);
                                    if (isset($modinfo->cms[$node['cm']])) {
                                        return $modinfo->cms[$node['cm']]->name;
                                    }
                                } catch (\Throwable $e) {
                                }
                            }
                            if (isset($node['type']) && $node['type'] === 'grade' && !empty($node['id'])) {
                                global $DB;
                                $gi = $DB->get_record('grade_items', ['id' => $node['id'], 'courseid' => $course->id]);
                                if ($gi && !empty($gi->itemname)) {
                                    return $gi->itemname;
                                }
                            }
                            if (!empty($node['c']) && is_array($node['c'])) {
                                foreach ($node['c'] as $child) {
                                    $res = $find_req($child);
                                    if (!empty($res)) {
                                        return $res;
                                    }
                                }
                            }
                            return '';
                        };
                        $req_name = $find_req($avail);
                        if (!empty($req_name)) {
                            return $req_name;
                        }
                    }
                }

                // Fallback to issue data if helper stored it
                if ($issue && !empty($issue->data)) {
                    $issuedata = is_array($issue->data) ? $issue->data : json_decode($issue->data, true);
                    if (!empty($issuedata['courseactivityname'])) {
                        return $issuedata['courseactivityname'];
                    }
                }
                if ($cc_cm) {
                    return $cc_cm->name;
                }
            }

            // Mode 3: certificate (Title of certificate module itself).
            if ($source === 'certificate') {
                if ($cc_cm) {
                    return $cc_cm->name;
                }
                if ($issue && !empty($issue->data)) {
                    $issuedata = is_array($issue->data) ? $issue->data : json_decode($issue->data, true);
                    if (!empty($issuedata['courseactivityname'])) {
                        return $issuedata['courseactivityname'];
                    }
                }
            }
        }

        // Generic fallback to issue data if available.
        if ($issue && !empty($issue->data)) {
            $issuedata = is_array($issue->data) ? $issue->data : json_decode($issue->data, true);
            if (!empty($issuedata['courseactivityname'])) {
                return $issuedata['courseactivityname'];
            }
        }

        return get_string('previewactivityname', 'certificateelement_activityname');
    }

    /**
     * Handles rendering the element on the pdf.
     *
     * @param \pdf $pdf the pdf object
     * @param bool $preview true if it is a preview, false otherwise
     * @param \stdClass $user the user we are rendering this for
     * @param \stdClass $issue the issue we are rendering
     */
    public function render($pdf, $preview, $user, $issue) {
        if ($preview) {
            $text = get_string('previewactivityname', 'certificateelement_activityname');
        } else {
            $text = $this->get_activity_name_value($issue);
        }
        $context = !empty($issue->courseid) ? \context_course::instance($issue->courseid) : \context_system::instance();
        $text = format_string($text, true, ['context' => $context]);
        \tool_certificate\element_helper::render_content($pdf, $this, $text);
    }

    /**
     * Render the element in html for drag-and-drop template designer.
     *
     * @return string the html
     */
    public function render_html() {
        $text = get_string('previewactivityname', 'certificateelement_activityname');
        $text = format_string($text, true, ['context' => \context_system::instance()]);
        return \tool_certificate\element_helper::render_html_content($this, $text);
    }
}
