<?php
// This file is part of Moodle - http://moodle.org/

namespace block_user_category_courses;

defined('MOODLE_INTERNAL') || die();

use core_course_category;
use core_course_list_element;
use context_block;
use moodle_url;
use core_text;

class helper {

    public const COLOR_PALETTE = [
        '#2563eb', // Azul
        '#059669', // Esmeralda
        '#7c3aed', // Violeta
        '#d97706', // Ámbar
        '#db2777', // Rosa
        '#0891b2', // Cian
        '#dc2626', // Rojo
        '#0d9488', // Verde azulado
        '#4f46e5', // Índigo
        '#ea580c', // Naranja
        '#16a34a', // Verde
        '#475569', // Pizarra
    ];

    public static function get_configured_images($instanceid = 0): array {
        global $DB;

        $images = [
            'byId' => [],
            'byName' => [],
            'default' => null,
        ];

        $instances = [];
        if ($instanceid > 0) {
            $record = $DB->get_record('block_instances', ['id' => $instanceid]);
            if ($record) {
                $instances[] = $record;
            }
        }
        if (empty($instances)) {
            $instances = $DB->get_records('block_instances', ['blockname' => 'user_category_courses']);
        }

        $fs = get_file_storage();

        foreach ($instances as $instance) {
            $context = context_block::instance($instance->id, IGNORE_MISSING);
            if (!$context) {
                continue;
            }

            // 1. Archivos en Filemanager (categoryimages)
            $files = $fs->get_area_files($context->id, 'block_user_category_courses', 'categoryimages', 0, 'itemid, filepath, filename', false);
            foreach ($files as $file) {
                if ($file->is_directory()) {
                    continue;
                }
                $filename = $file->get_filename();
                $url = moodle_url::make_pluginfile_url(
                    $context->id,
                    'block_user_category_courses',
                    'categoryimages',
                    0,
                    '/',
                    $filename
                )->out();

                $basename = pathinfo($filename, PATHINFO_FILENAME);
                $lowername = core_text::strtolower(trim($basename));

                // A. Imagen por defecto general: SOLO si el archivo contiene explícitamente default o defecto
                if ($lowername === 'default' || $lowername === 'defecto' ||
                    strpos($lowername, 'default') === 0 || strpos($lowername, 'defecto') === 0) {
                    $images['default'] = $url;
                    continue;
                }

                // B. Detección por número de ID exacto o prefijado (ej. 1.jpg, cat_1.png, category-1.webp, id_1.jpg)
                if (preg_match('/^(?:cat|category|id)?[-_ ]*(\d+)$/i', $basename, $matches)) {
                    $images['byId'][(int)$matches[1]] = $url;
                }

                // C. Detección por nombre de categoría limpio (ej. Category 1.jpg -> category1, CFA ALT.jpg -> cfaalt)
                $cleanbasename = core_text::strtolower(trim(preg_replace('/[^a-zA-Z0-9]/', '', $basename)));
                if (!empty($cleanbasename) && !is_numeric($cleanbasename)) {
                    $images['byName'][$cleanbasename] = $url;
                }
            }

            // 2. URLs manuales
            if (!empty($instance->configdata)) {
                $raw = base64_decode($instance->configdata, true);
                $config = $raw !== false ? @unserialize($raw) : @unserialize($instance->configdata);
                if (!empty($config->categoryurls)) {
                    $lines = explode("\n", str_replace("\r", "", $config->categoryurls));
                    foreach ($lines as $line) {
                        $parts = explode("=", trim($line), 2);
                        if (count($parts) === 2) {
                            $key = trim($parts[0]);
                            $val = clean_param(trim($parts[1]), PARAM_URL);
                            if ($key === 'default') {
                                $images['default'] = $val;
                            } else if (is_numeric($key)) {
                                $images['byId'][(int)$key] = $val;
                            } else {
                                $cleankey = core_text::strtolower(trim(preg_replace('/[^a-zA-Z0-9]/', '', $key)));
                                if (!empty($cleankey)) {
                                    $images['byName'][$cleankey] = $val;
                                }
                            }
                        }
                    }
                }
            }
        }

        return $images;
    }

    public static function get_category_visual(core_course_category $category, array $configuredimages): array {
        $catid = (int)$category->id;
        $imageurl = '';

        // 1. Coincidencia por ID de categoría (ej. 1.jpg, cat_1.jpg)
        if (isset($configuredimages['byId'][$catid])) {
            $imageurl = $configuredimages['byId'][$catid];
        } else {
            // 2. Coincidencia por nombre de categoría limpio (ej. CFA ALT.jpg -> cfaalt, Category 1.jpg -> category1)
            $catnameclean = core_text::strtolower(trim(preg_replace('/[^a-zA-Z0-9]/', '', $category->name)));
            if (!empty($configuredimages['byName'][$catnameclean])) {
                $imageurl = $configuredimages['byName'][$catnameclean];
            }
        }

        // 3. Fallback a imagen por defecto general SOLO si fue configurada explícitamente (default.jpg o default=...)
        if (empty($imageurl) && !empty($configuredimages['default'])) {
            $imageurl = $configuredimages['default'];
        }

        $hasimage = !empty($imageurl);
        $palette = self::COLOR_PALETTE;
        $color = $palette[$catid % count($palette)];

        $cleanName = trim(preg_replace('/[^a-zA-Z0-9\s]/u', '', $category->name));
        $words = preg_split('/\s+/u', $cleanName, -1, PREG_SPLIT_NO_EMPTY);
        $initials = '';
        if (count($words) >= 2) {
            $initials = mb_strtoupper(mb_substr($words[0], 0, 1) . mb_substr($words[1], 0, 1));
        } else if (count($words) === 1) {
            $initials = mb_strtoupper(mb_substr($words[0], 0, 2));
        } else {
            $initials = 'C' . $catid;
        }

        return [
            'hasimage' => $hasimage,
            'imageurl' => $imageurl,
            'color'    => $color,
            'initials' => $initials,
        ];
    }

    public static function get_course_visual($course): array {
        $courseobj = new core_course_list_element($course);
        $imageurl = '';

        foreach ($courseobj->get_course_overviewfiles() as $file) {
            if ($file->is_valid_image()) {
                $imageurl = moodle_url::make_pluginfile_url(
                    $file->get_contextid(),
                    $file->get_component(),
                    $file->get_filearea(),
                    null,
                    $file->get_filepath(),
                    $file->get_filename()
                )->out();
                break;
            }
        }

        $hasimage = !empty($imageurl);
        $palette = self::COLOR_PALETTE;
        $color = $palette[((int)$course->id) % count($palette)];

        return [
            'hasimage' => $hasimage,
            'imageurl' => $imageurl,
            'color'    => $color,
        ];
    }
}
