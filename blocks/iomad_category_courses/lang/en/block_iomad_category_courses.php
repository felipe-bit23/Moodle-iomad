<?php
// This file is part of Moodle - http://moodle.org/

$string['pluginname'] = 'My Categories and Courses (Multi-tenant)';
$string['iomad_category_courses:addinstance'] = 'Add a new Multi-tenant Categories block';
$string['iomad_category_courses:myaddinstance'] = 'Add a new Multi-tenant Categories block to Dashboard';
$string['nocourses'] = 'You are not currently enrolled in any courses.';
$string['viewcategoryandcourses'] = 'View category & courses';
$string['subcategoriesof'] = 'Subcategories of {$a}';
$string['coursesof'] = 'Courses in {$a}';
$string['explore'] = 'Explore';
$string['gotocourse'] = 'Access course';
$string['nocoursesincategory'] = 'You are not enrolled in any courses in this section.';
$string['back'] = 'Back';

// Settings
$string['blocksettings'] = 'Block display settings';
$string['config_title'] = 'Custom block title';
$string['config_columns'] = 'Card columns layout';
$string['columns'] = 'columns';
$string['config_sharedtitle'] = 'Card title for external / shared courses';
$string['config_sharedtitle_desc'] = 'Shared or licensed courses from other categories will be grouped under this safe title to prevent brand leaks.';
$string['default_sharedtitle'] = 'Transversal Courses';
$string['config_companycoursetitle'] = 'Title for general company courses';
$string['config_companycoursetitle_desc'] = 'Title used for courses located directly in the company root category without a subcategory.';
$string['default_companycoursetitle'] = 'General Corporate Training';
$string['config_breakdown_subcats'] = 'Break down company thematic subcategories';
$string['config_breakdown_subcats_desc'] = 'If enabled, subcategories within the company (e.g. Sales, Onboarding, Safety) will be shown as distinct cards instead of a single company card.';

// Images
$string['config_images_header'] = 'Category & Section Images';
$string['config_categoryimages'] = 'Upload section images';
$string['config_categoryimages_help'] = 'Upload images for category cards. You can name images with category ID (e.g. 1.jpg, cat_10.png), subcategory name (e.g. Sales.jpg, Onboarding.png), shared courses card (transversal.jpg), or global fallback (default.jpg). Cards without images will display a modern color banner with initials.';
$string['config_categoryurls'] = 'Category image URLs (Optional)';
$string['config_categoryurls_help'] = 'One entry per line in format: KEY=URL (e.g. 10=https://example.com/sales.jpg, transversal=https://example.com/global.jpg, default=https://example.com/default.jpg).';
$string['privacy:metadata'] = 'The My Categories and Courses (Multi-tenant) block displays existing course and company data and does not store personal data.';
