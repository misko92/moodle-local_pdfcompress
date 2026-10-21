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

namespace local_pdfcompress;

/**
 * Event observer.
 *
 * @package    local_pdfcompress
 * @copyright  2026 Lukasz Miskowicz
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {
    /**
     * Compress large PDFs a student has just saved in a file submission.
     *
     * Any problem leaves the student's original file in place; a submission must never fail because of this plugin.
     *
     * @param \assignsubmission_file\event\assessable_uploaded $event
     */
    public static function assessable_uploaded(\assignsubmission_file\event\assessable_uploaded $event): void {
        if (!get_config('local_pdfcompress', 'enabled')) {
            return;
        }
        $fs = get_file_storage();
        foreach ($event->other['pathnamehashes'] ?? [] as $hash) {
            try {
                $file = $fs->get_file_by_hash($hash);
                if ($file) {
                    compressor::compress_stored_file($file);
                }
            } catch (\Throwable $e) {
                debugging('local_pdfcompress: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
    }
}
