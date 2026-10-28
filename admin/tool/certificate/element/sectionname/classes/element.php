<?php
// This file is part of the tool_certificate plugin for Moodle - http://moodle.org/

namespace certificateelement_sectionname;

defined('MOODLE_INTERNAL') || die();

/**
 * Course section name certificate element.
 *
 * @package    certificateelement_sectionname
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
        parent::render_form_elements($mform);
    }

    /**
     * Handles saving the form elements created by this element.
     *
     * @param \stdClass $data
     */
    public function save_form_data(\stdClass $data) {
        parent::save_form_data($data);
    }

    /**
     * Resolves the section name for an issue or course.
     *
     * @param \stdClass|null $issue
     * @return string
     */
    protected function get_section_name_value($issue = null) {
        global $DB;

        if ($issue && !empty($issue->data)) {
            $issuedata = is_array($issue->data) ? $issue->data : json_decode($issue->data, true);
            if (!empty($issuedata['coursesectionname'])) {
                return $issuedata['coursesectionname'];
            }
        }

        if ($issue && !empty($issue->courseid)) {
            $course = get_course($issue->courseid);
            $templateid = $this->get_template()->get_id();
            $cms = get_coursemodules_in_course('coursecertificate', $course->id, 'm.template, m.name');
            foreach ($cms as $cmrec) {
                if ($cmrec->template == $templateid) {
                    $section = $DB->get_record('course_sections', ['id' => $cmrec->section]);
                    if ($section) {
                        return get_section_name($course, $section);
                    }
                }
            }
        }

        return get_string('previewsectionname', 'certificateelement_sectionname');
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
            $text = get_string('previewsectionname', 'certificateelement_sectionname');
        } else {
            $text = $this->get_section_name_value($issue);
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
        $text = get_string('previewsectionname', 'certificateelement_sectionname');
        $text = format_string($text, true, ['context' => \context_system::instance()]);
        return \tool_certificate\element_helper::render_html_content($this, $text);
    }
}
