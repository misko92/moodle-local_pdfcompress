<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Admin settings.
 *
 * @package    local_pdfcompress
 * @copyright  2026 Lukasz Miskowicz
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_pdfcompress', new lang_string('pluginname', 'local_pdfcompress'));
    $ADMIN->add('localplugins', $settings);

    if ($ADMIN->fulltree) {
        $settings->add(new admin_setting_heading(
            'local_pdfcompress/intro',
            '',
            new lang_string('intro', 'local_pdfcompress', \local_pdfcompress\compressor::get_stats_summary())
        ));
        $settings->add(new admin_setting_configcheckbox(
            'local_pdfcompress/enabled',
            new lang_string('enabled', 'local_pdfcompress'),
            new lang_string('enabled_desc', 'local_pdfcompress'),
            1
        ));
        $settings->add(new admin_setting_configselect(
            'local_pdfcompress/dpi',
            new lang_string('dpi', 'local_pdfcompress'),
            new lang_string('dpi_desc', 'local_pdfcompress'),
            200,
            [
                100 => new lang_string('dpi_100', 'local_pdfcompress'),
                150 => new lang_string('dpi_150', 'local_pdfcompress'),
                200 => new lang_string('dpi_200', 'local_pdfcompress'),
                300 => new lang_string('dpi_300', 'local_pdfcompress'),
            ]
        ));
        $settings->add(new admin_setting_configtext(
            'local_pdfcompress/minsizemb',
            new lang_string('minsizemb', 'local_pdfcompress'),
            new lang_string('minsizemb_desc', 'local_pdfcompress'),
            5,
            PARAM_INT
        ));
    }
}
