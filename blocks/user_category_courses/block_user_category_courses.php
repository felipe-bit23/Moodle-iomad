<?php
// This file is part of Moodle - http://moodle.org/

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/lib.php');

use block_user_category_courses\helper;

class block_user_category_courses extends block_base {

    public function init() {
        $this->title = get_string('pluginname', 'block_user_category_courses');
    }

    public function instance_allow_config() {
        return true;
    }

    public function instance_allow_multiple() {
        return false;
    }

    public function specialization() {
        if (!empty($this->config->title)) {
            $this->title = format_string($this->config->title);
        } else {
            $this->title = get_string('pluginname', 'block_user_category_courses');
        }
    }

    public function applicable_formats() {
        return [
            'my' => true,
            'site' => true,
            'course' => false,
        ];
    }

    public function instance_config_save($data, $nolongerused = false) {
        $context = context_block::instance($this->instance->id);

        // Moodle Formslib quita el prefijo 'config_' al procesar $data
        $draftitemid = 0;
        if (!empty($data->categoryimages)) {
            $draftitemid = $data->categoryimages;
        } else if (!empty($data->config_categoryimages)) {
            $draftitemid = $data->config_categoryimages;
        }

        if ($draftitemid) {
            file_save_draft_area_files(
                $draftitemid,
                $context->id,
                'block_user_category_courses',
                'categoryimages',
                0,
                ['subdirs' => 0]
            );
        }

        parent::instance_config_save($data, $nolongerused);
    }

    public function get_content() {
        global $USER, $OUTPUT;

        if ($this->content !== null) {
            return $this->content;
        }

        $this->content = new stdClass();
        $this->content->text = '';
        $this->content->footer = '';

        if (!isloggedin() || isguestuser()) {
            return $this->content;
        }

        $fields = 'id, category, sortorder, shortname, fullname, idnumber, startdate, visible, groupmode, groupmodeforce, cacherev';
        $mycourses = enrol_get_my_courses($fields);

        if (empty($mycourses)) {
            $this->content->text = html_writer::div(
                get_string('nocourses', 'block_user_category_courses'),
                'alert alert-info my-2'
            );
            return $this->content;
        }

        $grouped = [];
        foreach ($mycourses as $course) {
            if ($course->id == SITEID) {
                continue;
            }
            $catid = (int)$course->category;
            if (!isset($grouped[$catid])) {
                $grouped[$catid] = [];
            }
            $grouped[$catid][] = $course;
        }

        $configuredImages = helper::get_configured_images($this->instance->id);
        $categoriesdata = [];

        foreach ($grouped as $catid => $courses) {
            $category = core_course_category::get($catid, IGNORE_MISSING);
            if (!$category) {
                continue;
            }

            $visual = helper::get_category_visual($category, $configuredImages);
            $pageurl = new moodle_url('/blocks/user_category_courses/view.php', [
                'id' => $catid,
                'binstanceid' => $this->instance->id,
            ]);

            $categoriesdata[] = [
                'catid'        => $catid,
                'name'         => format_string($category->name),
                'url'          => $pageurl->out(),
                'hasimage'     => $visual['hasimage'],
                'imageurl'     => $visual['imageurl'],
                'color'        => $visual['color'],
                'initials'     => $visual['initials'],
                'coursecount'  => count($courses),
            ];
        }

        $cols = !empty($this->config->columns) ? (int)$this->config->columns : 3;
        $colclass = 'col-md-6 col-lg-4';
        if ($cols === 2) {
            $colclass = 'col-md-6';
        } else if ($cols === 4) {
            $colclass = 'col-md-6 col-lg-3';
        }

        $templatecontext = [
            'hascategories' => !empty($categoriesdata),
            'categories'    => $categoriesdata,
            'colclass'      => $colclass,
        ];

        $this->content->text = $OUTPUT->render_from_template('block_user_category_courses/content', $templatecontext);

        return $this->content;
    }
}
