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
 * English strings.
 *
 * @package    local_pdfcompress
 * @copyright  2026 Lukasz Miskowicz
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['dpi'] = 'Image resolution';
$string['dpi_100'] = '100 dpi (smallest, fine for quick checks)';
$string['dpi_150'] = '150 dpi (good on screen)';
$string['dpi_200'] = '200 dpi (sharp handwriting, recommended)';
$string['dpi_300'] = '300 dpi (print quality, least saving)';
$string['dpi_desc'] = 'Pictures inside the PDF are scaled down to this resolution. Text and drawings that are not pictures are not affected.';
$string['enabled'] = 'Compress PDF submissions';
$string['enabled_desc'] = 'When a student saves an assignment file submission, large PDFs are made smaller by scaling down the pictures inside them. The smaller file replaces the original; the original is not kept. If compressing fails or would not save at least 10%, the original is left as it is.';
$string['intro'] = '{$a}';
$string['minsizemb'] = 'Only compress files larger than (MB)';
$string['minsizemb_desc'] = 'Smaller PDFs are left alone.';
$string['pluginname'] = 'PDF submission compression';
$string['privacy:metadata'] = 'The plugin keeps a log of compressed files (sizes and content hashes only) and stores no personal data.';
$string['stats'] = 'So far {$a->count} PDF(s) compressed from {$a->before} to {$a->after}, saving {$a->saved}.';
$string['stats_none'] = 'No PDFs have been compressed yet.';
