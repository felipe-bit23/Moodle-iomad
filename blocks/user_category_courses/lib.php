<?php
// This file is part of Moodle - http://moodle.org/

defined('MOODLE_INTERNAL') || die();

function block_user_category_courses_pluginfile($course, $binstance, $context, $filearea, $args, $forcedownload, array $options = []) {
    if ($context->contextlevel != CONTEXT_BLOCK) {
        return false;
    }

    if ($filearea !== 'categoryimages') {
        return false;
    }

    require_login();

    // En Moodle pluginfile, el primer argumento de $args es el $itemid (0 en nuestro caso)
    $itemid = (int)array_shift($args);
    $filename = array_pop($args);
    $filepath = $args ? '/' . implode('/', $args) . '/' : '/';

    $fs = get_file_storage();
    $file = $fs->get_file($context->id, 'block_user_category_courses', 'categoryimages', $itemid, $filepath, $filename);
    if (!$file || $file->is_directory()) {
        return false;
    }

    \core\session\manager::write_close();
    send_stored_file($file, 60 * 60, 0, $forcedownload, $options);
}
