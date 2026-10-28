<?php
// This file is part of Moodle - http://moodle.org/

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/course/lib.php');

use block_iomad_category_courses\helper;

$sectionid = required_param('id', PARAM_INT);
$isshared = optional_param('isshared', 0, PARAM_INT);
$binstanceid = optional_param('binstanceid', 0, PARAM_INT);

require_login();

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/blocks/iomad_category_courses/view.php', ['id' => $sectionid, 'isshared' => $isshared]));
$PAGE->set_pagelayout('standard');

$binstance = $binstanceid ? $DB->get_record('block_instances', ['id' => $binstanceid]) : null;
$config = $binstance && !empty($binstance->configdata) ? @unserialize(base64_decode($binstance->configdata, true)) : null;

// Títulos y nombres
$sectionName = '';
if ($isshared) {
    $sectionName = $config && !empty($config->sharedtitle) ? format_string($config->sharedtitle) : get_string('default_sharedtitle', 'block_iomad_category_courses');
} else if ($sectionid > 0) {
    $cat = core_course_category::get($sectionid, IGNORE_MISSING);
    if ($cat) {
        $company = helper::get_user_iomad_company($USER->id);
        if ($company && (int)$company->category === (int)$cat->id) {
            $sectionName = $config && !empty($config->companycoursetitle) ? format_string($config->companycoursetitle) : get_string('default_companycoursetitle', 'block_iomad_category_courses');
        } else {
            $sectionName = format_string($cat->name);
        }
    } else {
        $sectionName = get_string('coursesof', 'block_iomad_category_courses', '');
    }
} else {
    $sectionName = get_string('coursesof', 'block_iomad_category_courses', '');
}

$PAGE->set_title($sectionName);
$PAGE->set_heading($sectionName);

$PAGE->navbar->add(get_string('pluginname', 'block_iomad_category_courses'), new moodle_url('/my/'));
$PAGE->navbar->add($sectionName);

echo $OUTPUT->header();

$fields = 'id, category, sortorder, shortname, fullname, idnumber, startdate, visible, groupmode, groupmodeforce, cacherev, summary, summaryformat';
$mycourses = enrol_get_my_courses($fields);

$company = helper::get_user_iomad_company($USER->id);
$companycatid = $company ? (int)$company->category : 0;
$companySubcatIds = ($companycatid > 0) ? helper::get_all_subcategory_ids($companycatid) : [];

$coursesdata = [];

foreach ($mycourses as $c) {
    if ($c->id == SITEID) {
        continue;
    }
    $cCatId = (int)$c->category;

    $matches = false;
    if ($isshared) {
        // Cursos externos/compartidos
        if ($companycatid > 0 && $cCatId !== $companycatid && !in_array($cCatId, $companySubcatIds)) {
            $matches = true;
        }
    } else if ($sectionid === $companycatid) {
        // Cursos en la raíz de la empresa
        if ($cCatId === $companycatid) {
            $matches = true;
        }
    } else if ($sectionid > 0 && $cCatId === $sectionid) {
        // Cursos en una subcategoría específica
        $matches = true;
    }

    if ($matches) {
        $visual = helper::get_course_visual($c);
        $summary = '';
        if (!empty($c->summary)) {
            $summary = file_rewrite_pluginfile_urls(
                $c->summary,
                'pluginfile.php',
                context_course::instance($c->id)->id,
                'course',
                'summary',
                null
            );
            $summary = format_text($summary, $c->summaryformat);
            $summary = html_to_text($summary, 0, false);
            if (core_text::strlen($summary) > 160) {
                $summary = core_text::substr($summary, 0, 157) . '...';
            }
        }

        $coursesdata[] = [
            'id'       => $c->id,
            'fullname' => format_string($c->fullname),
            'url'      => (new moodle_url('/course/view.php', ['id' => $c->id]))->out(),
            'hasimage' => $visual['hasimage'],
            'imageurl' => $visual['imageurl'],
            'color'    => $visual['color'],
            'summary'  => $summary,
        ];
    }
}

$backurl = new moodle_url('/my/');
$configuredImages = helper::get_configured_images($binstanceid);
$headerVisual = helper::get_section_visual($sectionid, $sectionName, $configuredImages, (bool)$isshared);

$renderdata = [
    'sectionname'   => $sectionName,
    'hascourses'    => !empty($coursesdata),
    'courses'       => $coursesdata,
    'coursescount'  => count($coursesdata),
    'backurl'       => $backurl->out(),
    'headerimage'   => $headerVisual['imageurl'],
    'hasheaderimage'=> $headerVisual['hasimage'],
    'headercolor'   => $headerVisual['color'],
    'initials'      => $headerVisual['initials'],
];

echo $OUTPUT->render_from_template('block_iomad_category_courses/category_view', $renderdata);
echo $OUTPUT->footer();
