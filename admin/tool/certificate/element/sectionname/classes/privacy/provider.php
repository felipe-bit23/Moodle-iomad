<?php
// This file is part of the tool_certificate plugin for Moodle - http://moodle.org/

namespace certificateelement_sectionname\privacy;

defined('MOODLE_INTERNAL') || die();

class provider implements \core_privacy\local\metadata
ull_provider {
    public static function get_reason(): string {
        return 'privacy:metadata';
    }
}
