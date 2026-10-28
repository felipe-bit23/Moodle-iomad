<?php
// This file is part of Moodle - http://moodle.org/

defined('MOODLE_INTERNAL') || die();

use block_iomad_category_courses\helper;

class block_iomad_category_courses extends block_base {

    public function init() {
        $this->title = get_string('pluginname', 'block_iomad_category_courses');
    }

    public function specialization() {
        if (!empty($this->config->title)) {
            $this->title = format_string($this->config->title);
        }
    }

    public function instance_allow_multiple() {
        return false;
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
                'block_iomad_category_courses',
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
                get_string('nocourses', 'block_iomad_category_courses'),
                'alert alert-info my-2'
            );
            return $this->content;
        }

        // 1. Detectar compañía IOMAD del usuario y su categoría
        $company = helper::get_user_iomad_company($USER->id);
        $companycatid = $company ? (int)$company->category : 0;
        $companyname = $company ? format_string($company->name) : '';

        // Subcategorías de la empresa (si la opción de desglose está activa)
        $breakdown = !isset($this->config->breakdown_subcats) || !empty($this->config->breakdown_subcats);
        $companySubcatIds = ($companycatid > 0) ? helper::get_all_subcategory_ids($companycatid) : [];

        // Títulos configurables
        $sharedTitle = !empty($this->config->sharedtitle) ? format_string($this->config->sharedtitle) : get_string('default_sharedtitle', 'block_iomad_category_courses');
        $companyRootTitle = !empty($this->config->companycoursetitle) ? format_string($this->config->companycoursetitle) : get_string('default_companycoursetitle', 'block_iomad_category_courses');

        // 2. Agrupación inteligente
        // Estructura: $sections[key] = ['id' => X, 'name' => Y, 'isshared' => bool, 'courses' => []]
        $sections = [];

        foreach ($mycourses as $course) {
            if ($course->id == SITEID) {
                continue;
            }
            $catid = (int)$course->category;

            // Caso A: El usuario tiene compañía en IOMAD
            if ($companycatid > 0) {
                if ($catid === $companycatid) {
                    // Curso en la raíz de la empresa
                    $key = 'company_root';
                    if (!isset($sections[$key])) {
                        $sections[$key] = [
                            'id'       => $companycatid,
                            'name'     => $companyRootTitle,
                            'isshared' => false,
                            'courses'  => [],
                        ];
                    }
                    $sections[$key]['courses'][] = $course;
                } else if (in_array($catid, $companySubcatIds)) {
                    // Curso en una subcategoría temática de la empresa
                    $key = 'subcat_' . $catid;
                    if (!isset($sections[$key])) {
                        $catObj = core_course_category::get($catid, IGNORE_MISSING);
                        $catName = $catObj ? format_string($catObj->name) : ('Categoría ' . $catid);
                        $sections[$key] = [
                            'id'       => $catid,
                            'name'     => $catName,
                            'isshared' => false,
                            'courses'  => [],
                        ];
                    }
                    $sections[$key]['courses'][] = $course;
                } else {
                    // ¡Curso externo / compartido de otra categoría! -> Enmascarar
                    $key = 'shared_courses';
                    if (!isset($sections[$key])) {
                        $sections[$key] = [
                            'id'       => -1, // ID virtual
                            'name'     => $sharedTitle,
                            'isshared' => true,
                            'courses'  => [],
                        ];
                    }
                    $sections[$key]['courses'][] = $course;
                }
            } else {
                // Caso B: Usuario sin compañía IOMAD (comportamiento estándar)
                $key = 'cat_' . $catid;
                if (!isset($sections[$key])) {
                    $catObj = core_course_category::get($catid, IGNORE_MISSING);
                    $catName = $catObj ? format_string($catObj->name) : ('Categoría ' . $catid);
                    $sections[$key] = [
                        'id'       => $catid,
                        'name'     => $catName,
                        'isshared' => false,
                        'courses'  => [],
                    ];
                }
                $sections[$key]['courses'][] = $course;
            }
        }

        // 3. Preparar datos visuales para Mustache
        $configuredImages = helper::get_configured_images($this->instance->id);
        $cardsData = [];

        foreach ($sections as $k => $sec) {
            $visual = helper::get_section_visual($sec['id'], $sec['name'], $configuredImages, $sec['isshared']);
            $pageurl = new moodle_url('/blocks/iomad_category_courses/view.php', [
                'id'          => $sec['id'],
                'isshared'    => $sec['isshared'] ? 1 : 0,
                'binstanceid' => $this->instance->id,
            ]);

            $cardsData[] = [
                'catid'        => $sec['id'],
                'name'         => $sec['name'],
                'url'          => $pageurl->out(),
                'hasimage'     => $visual['hasimage'],
                'imageurl'     => $visual['imageurl'],
                'color'        => $visual['color'],
                'initials'     => $visual['initials'],
                'coursecount'  => count($sec['courses']),
                'isshared'     => $sec['isshared'],
            ];
        }

        $cols = !empty($this->config->columns) ? (int)$this->config->columns : 3;
        $colclass = 'col-md-4';
        if ($cols === 2) {
            $colclass = 'col-md-6';
        } else if ($cols === 4) {
            $colclass = 'col-md-3';
        }

        $renderdata = [
            'categories' => $cardsData,
            'colclass'   => $colclass,
        ];

        $this->content->text = $OUTPUT->render_from_template('block_iomad_category_courses/content', $renderdata);
        return $this->content;
    }
}
