<?php
// This file is part of Moodle - http://moodle.org/

namespace block_iomad_category_courses;

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

    /**
     * Obtiene la compañía activa de IOMAD a la que pertenece el usuario.
     */
    public static function get_user_iomad_company($userid): ?\stdClass {
        global $DB;

        // Comprueba si IOMAD está instalado y activo
        if (!$DB->get_manager()->table_exists('company') || !$DB->get_manager()->table_exists('company_users')) {
            return null;
        }

        $sql = "SELECT c.* 
                  FROM {company} c
                  JOIN {company_users} cu ON cu.companyid = c.id
                 WHERE cu.userid = :userid
              ORDER BY cu.id ASC";
        $companies = $DB->get_records_sql($sql, ['userid' => (int)$userid], 0, 1);
        if (!empty($companies)) {
            return reset($companies);
        }

        return null;
    }

    /**
     * Obtiene todas las subcategorías (IDs) pertenecientes a una categoría padre.
     */
    public static function get_all_subcategory_ids(int $parentcatid): array {
        global $DB;
        $category = core_course_category::get($parentcatid, IGNORE_MISSING);
        if (!$category) {
            return [];
        }

        $path = $category->path . '/%';
        $records = $DB->get_records_select_menu('course_categories', "path LIKE :path", ['path' => $path], '', 'id, id AS id2');
        return !empty($records) ? array_map('intval', array_keys($records)) : [];
    }

    /**
     * Obtiene las imágenes configuradas para el bloque.
     */
    public static function get_configured_images($instanceid = 0): array {
        global $DB;

        $images = [
            'byId' => [],
            'byName' => [],
            'shared' => null,
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
            $instances = $DB->get_records('block_instances', ['blockname' => 'iomad_category_courses']);
        }

        $fs = get_file_storage();

        foreach ($instances as $instance) {
            $context = context_block::instance($instance->id, IGNORE_MISSING);
            if (!$context) {
                continue;
            }

            // 1. Filemanager
            $files = $fs->get_area_files($context->id, 'block_iomad_category_courses', 'categoryimages', 0, 'itemid, filepath, filename', false);
            foreach ($files as $file) {
                if ($file->is_directory()) {
                    continue;
                }
                $filename = $file->get_filename();
                $url = moodle_url::make_pluginfile_url(
                    $context->id,
                    'block_iomad_category_courses',
                    'categoryimages',
                    0,
                    '/',
                    $filename
                )->out();

                $basename = pathinfo($filename, PATHINFO_FILENAME);
                $lowername = core_text::strtolower(trim($basename));

                // A. Imagen para cursos compartidos/transversales
                if (strpos($lowername, 'transversal') !== false || strpos($lowername, 'compartid') !== false || strpos($lowername, 'shared') !== false) {
                    $images['shared'] = $url;
                    continue;
                }

                // B. Imagen por defecto
                if ($lowername === 'default' || $lowername === 'defecto' || strpos($lowername, 'default') === 0 || strpos($lowername, 'defecto') === 0) {
                    $images['default'] = $url;
                    continue;
                }

                // C. Detección por ID numérico (ej. 10.jpg, cat_10.png)
                if (preg_match('/^(?:cat|category|id)?[-_ ]*(\d+)$/i', $basename, $matches)) {
                    $images['byId'][(int)$matches[1]] = $url;
                }

                // D. Detección por nombre limpio (ej. Induccion.jpg, Ventas.png)
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
                            $lowerkey = core_text::strtolower($key);
                            if (strpos($lowerkey, 'transversal') !== false || strpos($lowerkey, 'compartid') !== false || strpos($lowerkey, 'shared') !== false) {
                                $images['shared'] = $val;
                            } else if ($lowerkey === 'default' || $lowerkey === 'defecto') {
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

    /**
     * Determina el aspecto visual (imagen, iniciales, color) de una tarjeta o sección.
     */
    public static function get_section_visual(int $id, string $name, array $configuredimages, bool $isshared = false): array {
        $imageurl = '';

        if ($isshared && !empty($configuredimages['shared'])) {
            $imageurl = $configuredimages['shared'];
        } else if (isset($configuredimages['byId'][$id]) && $id > 0) {
            $imageurl = $configuredimages['byId'][$id];
        } else {
            $cleanname = core_text::strtolower(trim(preg_replace('/[^a-zA-Z0-9]/', '', $name)));
            if (!empty($configuredimages['byName'][$cleanname])) {
                $imageurl = $configuredimages['byName'][$cleanname];
            } else if (!empty($configuredimages['default'])) {
                $imageurl = $configuredimages['default'];
            }
        }

        $hasimage = !empty($imageurl);
        $palette = self::COLOR_PALETTE;
        $seed = $id > 0 ? $id : crc32($name);
        $color = $palette[abs($seed) % count($palette)];

        $cleanTitle = trim(preg_replace('/[^a-zA-Z0-9\s]/u', '', $name));
        $words = preg_split('/\s+/u', $cleanTitle, -1, PREG_SPLIT_NO_EMPTY);
        $initials = '';
        if (count($words) >= 2) {
            $initials = mb_strtoupper(mb_substr($words[0], 0, 1) . mb_substr($words[1], 0, 1));
        } else if (count($words) === 1) {
            $initials = mb_strtoupper(mb_substr($words[0], 0, 2));
        } else {
            $initials = 'MC';
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
