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
 * Course renderer with IOMAD Multi-Tenant support.
 *
 * @package     theme_trema
 * @copyright   2019-2026 Trema - {@link https://trema.tech/}
 * @copyright   2023-2026 Trema - {@link https://www.tngconsulting.ca/}
 * @author      Rodrigo Mady
 * @author      Trevor Furtado
 * @author      Michael Milette
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace theme_trema\output\core;

use core_course_category;
use core_course_list_element;
use moodle_url;
use html_writer;
use coursecat_helper;
use stdClass;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/theme/trema/locallib.php');

/**
 * Class course_renderer
 * @package theme_trema
 */
class course_renderer extends \core_course_renderer {
    /**
     * Renders the list of courses for frontpage and /course
     *
     * @param coursecat_helper $chelper various display options
     * @param array $courses the list of courses to display
     * @param int|null $totalcount total number of courses
     * @return string
     */
    protected function coursecat_courses(coursecat_helper $chelper, $courses, $totalcount = null) {
        global $CFG;

        if ($totalcount === null) {
            $totalcount = count($courses);
        }
        if (!$totalcount) {
            return '';
        }
        if ($chelper->get_show_courses() == self::COURSECAT_SHOW_COURSES_AUTO) {
            if ($totalcount <= $CFG->courseswithsummarieslimit) {
                $chelper->set_show_courses(self::COURSECAT_SHOW_COURSES_EXPANDED);
            } else {
                $chelper->set_show_courses(self::COURSECAT_SHOW_COURSES_COLLAPSED);
            }
        }

        $paginationurl = $chelper->get_courses_display_option('paginationurl');
        $paginationallowall = $chelper->get_courses_display_option('paginationallowall');
        if ($totalcount > count($courses)) {
            if ($paginationurl) {
                $perpage = $chelper->get_courses_display_option('limit', $CFG->coursesperpage);
                $page = $chelper->get_courses_display_option('offset') / $perpage;
                $pagingbar = $this->paging_bar($totalcount, $page, $perpage, $paginationurl->out(false, ['perpage' => $perpage]));
                if ($paginationallowall) {
                    $pagingbar .= html_writer::tag(
                        'div',
                        html_writer::link($paginationurl->out(false, ['perpage' => 'all']), get_string('showall', '', $totalcount)),
                        ['class' => 'paging paging-showall']
                    );
                }
            } else if ($viewmoreurl = $chelper->get_courses_display_option('viewmoreurl')) {
                $viewmoretext = $chelper->get_courses_display_option('viewmoretext', new \lang_string('viewmore'));
                $morelink = html_writer::tag(
                    'div',
                    html_writer::link($viewmoreurl, $viewmoretext),
                    ['class' => 'paging paging-morelink']
                );
            }
        } else if (($totalcount > $CFG->coursesperpage) && $paginationurl && $paginationallowall) {
            $pagingbar = html_writer::tag(
                'div',
                html_writer::link(
                    $paginationurl->out(false, ['perpage' => $CFG->coursesperpage]),
                    get_string('showperpage', '', $CFG->coursesperpage)
                ),
                ['class' => 'paging paging-showperpage']
            );
        }

        $attributes = $chelper->get_and_erase_attributes('courses');
        $content = html_writer::start_tag('div', $attributes);

        if (!empty($pagingbar)) {
            $content .= $pagingbar;
        }

        $coursecount = 1;
        $content .= html_writer::start_tag('div', ['class' => ' row card-deck my-4']);
        $showhidden = theme_trema_get_setting('showehiddencategorycourses');
        foreach ($courses as $course) {
            if (empty($showhidden) && !$this->isvisiblecat($course)) {
                if (has_capability('moodle/course:update', \context_course::instance($course->id))) {
                    $content .= $this->coursecat_coursebox($chelper, $course, 'card mb-3 mr-3 course-card-view dimmed');
                    $coursecount++;
                }
            } else {
                $content .= $this->coursecat_coursebox($chelper, $course, 'card mb-3 mr-3 course-card-view');
                $coursecount++;
            }
        }

        $content .= html_writer::end_tag('div');
        if (!empty($pagingbar)) {
            $content .= $pagingbar;
        }

        if (!empty($morelink)) {
            $content .= $morelink;
        }

        $content .= html_writer::end_tag('div');
        return $content;
    }

    /**
     * Displays one course in the list of courses.
     *
     * @param coursecat_helper $chelper various display options
     * @param core_course_list_element|stdClass $course
     * @param string $additionalclasses additional classes to add to the main <div> tag
     * @return string
     */
    protected function coursecat_coursebox(coursecat_helper $chelper, $course, $additionalclasses = '') {
        if (!isset($this->strings->summary)) {
            $this->strings->summary = get_string('summary');
        }
        if ($chelper->get_show_courses() <= self::COURSECAT_SHOW_COURSES_COUNT) {
            return '';
        }
        if ($course instanceof stdClass) {
            $course = new core_course_list_element($course);
        }

        $content = html_writer::start_tag('div', ['class' => $additionalclasses]);
        $classes = '';
        if ($chelper->get_show_courses() < self::COURSECAT_SHOW_COURSES_EXPANDED) {
            $classes .= ' collapsed';
        }
        $content .= html_writer::start_tag(
            'div',
            ['class' => $classes, 'data-courseid' => $course->id, 'data-type' => self::COURSECAT_TYPE_COURSE]
        );
        if ($this->page->pagetype == 'enrol-index' && theme_trema_get_setting('courseenrolmentpageformat') == 'fullwidth') {
            $content .= parent::coursecat_coursebox_content($chelper, $course);
        } else {
            $content .= $this->coursecat_coursebox_content($chelper, $course);
        }
        $content .= html_writer::end_tag('div');
        $content .= html_writer::end_tag('div');
        return $content;
    }

    /**
     * Returns HTML to display course content (summary, course contacts and optionally category name)
     *
     * @param coursecat_helper $chelper various display options
     * @param stdClass|core_course_list_element $course
     * @return string
     */
    protected function coursecat_coursebox_content(coursecat_helper $chelper, $course) {
        global $CFG;
        if ($course instanceof stdClass) {
            $course = new core_course_list_element($course);
        }

        static $showcategories;
        if (!isset($showcategories)) {
            $showcategories = $chelper->get_show_courses() == self::COURSECAT_SHOW_COURSES_EXPANDED_WITH_CAT ||
                    (theme_trema_get_setting('showcategories') && $this->page->pagetype == 'site-index');
        }

        $coursename = $chelper->get_course_formatted_name($course);
        $courseurl = new moodle_url('/course/view.php', ['id' => $course->id]);
        $options = ['class' => 'aalink h5' . ($course->visible ? '' : ' dimmed')];
        $coursenamelink = html_writer::link($courseurl, $coursename, $options);

        $summarytype = theme_trema_get_setting('summarytype');
        $showcardcontact = theme_trema_get_setting('cardcontacts');
        $class = 'course-card-img';
        if (empty($summarytype) && empty($showcardcontact) && empty($showcategories)) {
            $class .= ' stretched-link';
        }
        $content = html_writer::start_tag(
            'a',
            [
                'href' => $courseurl,
                'class' => $class,
                'aria-hidden' => 'true',
                'tabindex' => '-1',
                'aria-label' => $coursename,
            ]
        );
        $content .= $this->get_course_summary_image($course);
        $content .= html_writer::end_tag('a');

        $content .= html_writer::start_tag('div', ['class' => 'card-body']);
        $content .= html_writer::tag('h3', $coursenamelink, ['class' => 'card-title m-1']);
        $content .= html_writer::end_tag('div');

        $content .= html_writer::start_tag('div', ['class' => 'enrolmenticon']);
        if ($icons = enrol_get_course_info_icons($course)) {
            foreach ($icons as $pixicon) {
                $content .= $this->render($pixicon);
            }
        }
        $content .= html_writer::end_tag('div');

        $content .= html_writer::start_tag('div', ['class' => 'pull-right']);
        $content .= html_writer::end_tag('div');

        if ($showcardcontact && $course->has_course_contacts()) {
            $content .= html_writer::start_tag('div', ['class' => 'teachers pt-2']);
            $content .= html_writer::start_tag('ul', ['class' => 'list-unstyled m-0 font-weight-light']);
            foreach ($course->get_course_contacts() as $userid => $coursecontact) {
                $name = $coursecontact['rolename'] . ': ' . html_writer::link(
                    new moodle_url('/user/view.php', ['id' => $userid, 'course' => SITEID]),
                    $coursecontact['username']
                );
                $content .= html_writer::tag('li', $name);
            }
            $content .= html_writer::end_tag('ul');
            $content .= html_writer::end_tag('div');
        }

        if ($showcategories) {
            if ($cat = core_course_category::get($course->category, IGNORE_MISSING)) {
                $content .= html_writer::start_tag('div', ['class' => 'coursecat small']);
                $content .= html_writer::tag('span', get_string('category') . ': ');
                $content .= html_writer::link(
                    new moodle_url('/course/index.php', ['categoryid' => $cat->id]),
                    $cat->get_formatted_name(),
                    ['class' => $cat->visible ? '' : 'dimmed']
                );
                $content .= html_writer::end_tag('div');
            }
        }

        $databs = $CFG->branch >= 500 ? 'bs-' : '';
        if (!empty($summarytype) && ($course->has_summary())) {
            if ($summarytype == 'popover') {
                $options = ['noclean' => true, 'para' => false];
                $content .= html_writer::start_tag('div', ['class' => 'card-see-more text-center']);
                $content .= html_writer::start_tag(
                    'div',
                    [
                        'class' => 'btn btn-secondary m-2',
                        'id' => "course-popover-{$course->id}",
                        'role' => 'button',
                        'tabindex' => '0',
                        "data-{$databs}region" => 'popover-region-toggle',
                        "data-{$databs}toggle" => 'popover',
                        "data-{$databs}placement" => 'right',
                        "data-{$databs}content" => $chelper->get_course_formatted_summary($course, $options),
                        "data-{$databs}html" => 'true',
                        "data-{$databs}trigger" => 'focus',
                    ]
                );
                $content .= get_string('summarybutton_text', 'theme_trema');
                $content .= html_writer::end_tag('div');
                $content .= html_writer::end_tag('div');
            } else if ($summarytype == 'modal') {
                $modal = [
                    'body' => $chelper->get_course_formatted_summary(
                        $course,
                        ['overflowdiv' => true, 'noclean' => true, 'para' => false]
                    ),
                    'title' => \format_string($course->fullname),
                    'uniqid' => $course->id,
                    'databs' => $databs,
                    'classes' => "modal-$course->id",
                    'courselink' => new moodle_url('/course/view.php', ['id' => $course->id]),
                ];
                $content .= $this->output->render_from_template('theme_trema/course_summary_modal', $modal);
            } else if ($summarytype == 'link') {
                $content .= html_writer::start_tag('div', ['class' => 'card-course-link text-center']);
                $content .= html_writer::tag(
                    'div',
                    html_writer::link(
                        new moodle_url('/course/view.php', ['id' => $course->id]),
                        get_string('summarycourselink_text', 'theme_trema'),
                        ['class' => 'btn btn-secondary m-2', 'role' => 'button', 'tabindex' => '0']
                    )
                );
                $content .= html_writer::end_tag('div');
            }
        }

        return $content;
    }

    /**
     * Returns the first course's summary issue
     *
     * @param stdClass $course the course object
     * @return string
     */
    protected function get_course_summary_image($course) {
        global $CFG;

        $contentimage = '';
        foreach ($course->get_course_overviewfiles() as $file) {
            $isimage = $file->is_valid_image();
            $url = new moodle_url(
                "$CFG->wwwroot/pluginfile.php/" . $file->get_contextid() . '/' . $file->get_component() . '/'
                . $file->get_filearea() . $file->get_filepath() . $file->get_filename()
            );
            if ($isimage) {
                $contentimage = html_writer::start_tag(
                    'div',
                    ['style' => "background-image:url('$url')", 'class' => 'card-img-top']
                );
                $contentimage .= html_writer::end_tag('div');
                break;
            }
        }

        if (empty($contentimage)) {
            $pattern = new \core_geopattern();
            $pattern->setColor($this->coursecolor($course->id));
            $pattern->patternbyid($course->id);
            $contentimage = html_writer::start_tag(
                'div',
                ['style' => "background-image:url('{$pattern->datauri()}')", 'class' => 'card-img-top']
            );
            $contentimage .= html_writer::end_tag('div');
        }

        return $contentimage;
    }

    /**
     * Generate a semi-random color based on the courseid number
     *
     * @param int $courseid
     * @return string $color, hexvalue color code.
     */
    protected function coursecolor($courseid) {
        $basecolors = [
            '#81ecec', '#74b9ff', '#a29bfe', '#dfe6e9', '#00b894', '#0984e3', '#b2bec3', '#fdcb6e', '#fd79a8', '#6c5ce7',
        ];
        $color = $basecolors[$courseid % 10];
        return $color;
    }

    /**
     * Check to see if course is in a visible category.
     *
     * @param object $course Moodle Course object.
     * @return boolean True if category and all parent categories are visible.
     */
    protected function isvisiblecat($course) {
        global $DB;
        static $coursecategories;

        if (!isset($coursecategories)) {
            $coursecategories = $DB->get_records('course_categories', [], '', 'id, path, visible');
        }

        $categories = array_reverse(explode('/', $coursecategories[$course->category]->path));
        foreach ($categories as $c) {
            if (!empty($c) && empty($coursecategories[$c]->visible)) {
                if (
                    !has_capability('moodle/category:viewhiddencategories', \context_coursecat::instance($c))
                    && !has_capability('moodle/category:viewhiddencategories', \context_system::instance())
                ) {
                    return false;
                }
            }
        }
        return true;
    }
}
