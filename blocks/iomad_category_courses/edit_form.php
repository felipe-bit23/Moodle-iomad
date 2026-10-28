<?php
// This file is part of Moodle - http://moodle.org/

defined('MOODLE_INTERNAL') || die();

class block_iomad_category_courses_edit_form extends block_edit_form {

    protected function specific_definition($mform) {
        $mform->addElement('header', 'configheader', get_string('blocksettings', 'block_iomad_category_courses'));

        $mform->addElement('text', 'config_title', get_string('config_title', 'block_iomad_category_courses'));
        $mform->setType('config_title', PARAM_TEXT);
        $mform->setDefault('config_title', get_string('pluginname', 'block_iomad_category_courses'));

        $coloptions = [
            '2' => '2 ' . get_string('columns', 'block_iomad_category_courses'),
            '3' => '3 ' . get_string('columns', 'block_iomad_category_courses'),
            '4' => '4 ' . get_string('columns', 'block_iomad_category_courses'),
        ];
        $mform->addElement('select', 'config_columns', get_string('config_columns', 'block_iomad_category_courses'), $coloptions);
        $mform->setDefault('config_columns', '3');

        // Opciones Multi-tenant de IOMAD
        $mform->addElement('header', 'iomadheader', 'Aislamiento Multitenant (IOMAD)');

        $mform->addElement('advcheckbox', 'config_breakdown_subcats', get_string('config_breakdown_subcats', 'block_iomad_category_courses'));
        $mform->setDefault('config_breakdown_subcats', 1);
        $mform->addElement('static', 'config_breakdown_subcats_desc', '', get_string('config_breakdown_subcats_desc', 'block_iomad_category_courses'));

        $mform->addElement('text', 'config_companycoursetitle', get_string('config_companycoursetitle', 'block_iomad_category_courses'));
        $mform->setType('config_companycoursetitle', PARAM_TEXT);
        $mform->setDefault('config_companycoursetitle', get_string('default_companycoursetitle', 'block_iomad_category_courses'));
        $mform->addElement('static', 'config_companycoursetitle_desc', '', get_string('config_companycoursetitle_desc', 'block_iomad_category_courses'));

        $mform->addElement('text', 'config_sharedtitle', get_string('config_sharedtitle', 'block_iomad_category_courses'));
        $mform->setType('config_sharedtitle', PARAM_TEXT);
        $mform->setDefault('config_sharedtitle', get_string('default_sharedtitle', 'block_iomad_category_courses'));
        $mform->addElement('static', 'config_sharedtitle_desc', '', get_string('config_sharedtitle_desc', 'block_iomad_category_courses'));

        // Gestión de Imágenes
        $mform->addElement('header', 'imagesheader', get_string('config_images_header', 'block_iomad_category_courses'));

        $filemanageroptions = [
            'subdirs' => 0,
            'maxbytes' => 10485760, // 10MB
            'areamaxbytes' => 52428800, // 50MB
            'maxfiles' => 50,
            'accepted_types' => ['image'],
        ];
        $mform->addElement('filemanager', 'config_categoryimages', get_string('config_categoryimages', 'block_iomad_category_courses'), null, $filemanageroptions);
        $mform->addElement('static', 'config_categoryimages_desc', '', get_string('config_categoryimages_help', 'block_iomad_category_courses'));

        $mform->addElement('textarea', 'config_categoryurls', get_string('config_categoryurls', 'block_iomad_category_courses'), ['rows' => 4, 'cols' => 50]);
        $mform->setType('config_categoryurls', PARAM_RAW);
        $mform->addElement('static', 'config_categoryurls_desc', '', get_string('config_categoryurls_help', 'block_iomad_category_courses'));
    }

    public function set_data($defaults) {
        if (!empty($this->block->context)) {
            $draftitemid = file_get_submitted_draft_itemid('config_categoryimages');
            file_prepare_draft_area(
                $draftitemid,
                $this->block->context->id,
                'block_iomad_category_courses',
                'categoryimages',
                0,
                ['subdirs' => 0]
            );
            $defaults->config_categoryimages = $draftitemid;
            $defaults->categoryimages = $draftitemid;
        }

        parent::set_data($defaults);
    }
}
