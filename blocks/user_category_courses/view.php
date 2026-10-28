<?php
// This file is part of Moodle - http://moodle.org/

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/course/lib.php');

use block_user_category_courses\helper;

$categoryid = required_param('id', PARAM_INT);
$binstanceid = optional_param('binstanceid', 0, PARAM_INT);

require_login();

$category = core_course_category::get($categoryid, MUST_EXIST);
$context = context_coursecat::instance($categoryid);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/blocks/user_category_courses/view.php', ['id' => $categoryid]));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(format_string($category->name));
$PAGE->set_heading(format_string($category->name));

$PAGE->navbar->add(get_string('pluginname', 'block_user_category_courses'), new moodle_url('/my/'));
if (!empty($category->parent)) {
    $parentcat = core_course_category::get($category->parent, IGNORE_MISSING);
    if ($parentcat) {
        $PAGE->navbar->add(format_string($parentcat->name), new moodle_url('/blocks/user_category_courses/view.php', ['id' => $parentcat->id, 'binstanceid' => $binstanceid]));
    }
}
$PAGE->navbar->add(format_string($category->name));

echo $OUTPUT->header();

$configuredImages = helper::get_configured_images($binstanceid);

// 1. Subcategorías hijas
$children = $category->get_children();
$subcategoriesdata = [];

$fields = 'id, category, sortorder, shortname, fullname, idnumber, startdate, visible, groupmode, groupmodeforce, cacherev, summary, summaryformat';
$mycourses = enrol_get_my_courses($fields);
$coursesbycat = [];
foreach ($mycourses as $c) {
    if ($c->id == SITEID) {
        continue;
    }
    $coursesbycat[$c->category][] = $c;
}

foreach ($children as $child) {
    $visual = helper::get_category_visual($child, $configuredImages);
    $suburl = new moodle_url('/blocks/user_category_courses/view.php', [
        'id' => $child->id,
        'binstanceid' => $binstanceid,
    ]);

    $enrolledCount = isset($coursesbycat[$child->id]) ? count($coursesbycat[$child->id]) : 0;

    $subcategoriesdata[] = [
        'id'          => $child->id,
        'name'        => format_string($child->name),
        'url'         => $suburl->out(),
        'hasimage'    => $visual['hasimage'],
        'imageurl'    => $visual['imageurl'],
        'color'       => $visual['color'],
        'initials'    => $visual['initials'],
        'coursecount' => $enrolledCount,
    ];
}

// 2. Cursos de esta categoría
$thiscategorycourses = isset($coursesbycat[$categoryid]) ? $coursesbycat[$categoryid] : [];
$coursesdata = [];

foreach ($thiscategorycourses as $c) {
    $coursevisual = helper::get_course_visual($c);
    $courseurl = new moodle_url('/course/view.php', ['id' => $c->id]);

    $rawsummary = (!empty($c->summary) && is_string($c->summary)) ? $c->summary : '';
    $summary = '';
    if (!empty($rawsummary)) {
        $summary = strip_tags($rawsummary);
        if (mb_strlen($summary) > 130) {
            $summary = mb_substr($summary, 0, 127) . '...';
        }
    }

    $coursesdata[] = [
        'id'        => $c->id,
        'fullname'  => format_string($c->fullname),
        'shortname' => format_string($c->shortname),
        'summary'   => $summary,
        'url'       => $courseurl->out(),
        'hasimage'  => $coursevisual['hasimage'],
        'imageurl'  => $coursevisual['imageurl'],
        'color'     => $coursevisual['color'],
    ];
}

$backurl = new moodle_url('/my/');
if (!empty($category->parent)) {
    $backurl = new moodle_url('/blocks/user_category_courses/view.php', ['id' => $category->parent, 'binstanceid' => $binstanceid]);
}

$categorynameformatted = format_string($category->name);
$subcategoriesheading = get_string('subcategoriesof', 'block_user_category_courses', $categorynameformatted);
$coursesheading = get_string('coursesof', 'block_user_category_courses', $categorynameformatted);

$templatecontext = [
    'categoryname'          => $categorynameformatted,
    'subcategoriesheading'  => $subcategoriesheading,
    'coursesheading'        => $coursesheading,
    'hassubcategories'      => !empty($subcategoriesdata),
    'subcategories'         => $subcategoriesdata,
    'hascourses'            => !empty($coursesdata),
    'courses'               => $coursesdata,
    'backurl'               => $backurl->out(),
];

echo $OUTPUT->render_from_template('block_user_category_courses/category_view', $templatecontext);

echo $OUTPUT->footer();
