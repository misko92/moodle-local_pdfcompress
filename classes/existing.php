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
 * Compress PDFs that were submitted before the plugin was installed.
 *
 * @package    local_pdfcompress
 * @copyright  2026 Lukasz Miskowicz
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class existing {
    /** @var string[] Annotation (editpdf) copies of a submission, stored per grade. */
    const EDITPDF_GRADE_AREAS = ['combined', 'partial'];

    /** @var string[] The same copies, stored per marker when an assignment has several markers. */
    const EDITPDF_MARK_AREAS = ['combined_marker', 'partial_marker'];

    /**
     * Find submitted PDFs that are big enough and have not been compressed yet, largest first.
     *
     * @param int $minsize Smallest file, in bytes.
     * @param int $courseid Only this course (0 = all).
     * @param int $cmid Only this assignment's course module (0 = all).
     * @return \stdClass[] File id, size, name, course shortname and assignment name.
     */
    public static function find(int $minsize, int $courseid = 0, int $cmid = 0): array {
        global $DB;
        $params = [
            'contextlevel' => CONTEXT_MODULE,
            'component' => 'assignsubmission_file',
            'filearea' => 'submission_files',
            'minsize' => $minsize,
            'mimetype' => 'application/pdf',
            'pdfname' => '%.pdf',
        ];
        $where = '';
        if ($courseid) {
            $where .= ' AND cm.course = :courseid';
            $params['courseid'] = $courseid;
        }
        if ($cmid) {
            $where .= ' AND cm.id = :cmid';
            $params['cmid'] = $cmid;
        }
        $pdfname = $DB->sql_like('f.filename', ':pdfname', false);
        $sql = "SELECT f.id, f.filesize, f.filename, co.shortname AS course, a.name AS assignment
                  FROM {files} f
                  JOIN {context} ctx ON ctx.id = f.contextid AND ctx.contextlevel = :contextlevel
                  JOIN {course_modules} cm ON cm.id = ctx.instanceid
                  JOIN {modules} m ON m.id = cm.module AND m.name = 'assign'
                  JOIN {assign} a ON a.id = cm.instance
                  JOIN {course} co ON co.id = cm.course
                 WHERE f.component = :component AND f.filearea = :filearea
                       AND f.filename <> '.' AND f.filesize >= :minsize
                       AND (f.mimetype = :mimetype OR $pdfname)
                       AND NOT EXISTS (SELECT 1 FROM {local_pdfcompress_log} l WHERE l.newhash = f.contenthash)
                       $where
              ORDER BY f.filesize DESC, f.id";
        return array_values($DB->get_records_sql($sql, $params));
    }

    /**
     * Compress one submitted file and clear the annotation copies made from the old version.
     *
     * @param int $fileid
     * @param int|null $dpi Resolution; defaults to the site setting.
     * @return \stdClass|null Log record, or null if the file was left alone.
     */
    public static function process(int $fileid, ?int $dpi = null): ?\stdClass {
        $file = get_file_storage()->get_file_by_id($fileid);
        if (!$file) {
            return null;
        }
        $log = compressor::compress_stored_file($file, $dpi, 0);
        if ($log) {
            self::clear_editpdf_copies($file);
        }
        return $log;
    }

    /**
     * Delete the annotation tool's combined copies of a submission so they are rebuilt from the smaller file.
     *
     * Teachers' annotations are stored separately and are kept. Moodle rebuilds the copies the next
     * time the submission is opened for annotation.
     *
     * @param \stored_file $file A file in a submission_files area (its itemid is the submission id).
     * @return int Number of copies deleted.
     */
    public static function clear_editpdf_copies(\stored_file $file): int {
        global $DB;
        $submission = $DB->get_record('assign_submission', ['id' => $file->get_itemid()]);
        if (!$submission) {
            return 0;
        }
        if ($submission->userid) {
            $userids = [$submission->userid];
        } else {
            $userids = array_keys(groups_get_members($submission->groupid, 'u.id'));
        }
        if (!$userids) {
            return 0;
        }
        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $params['assignment'] = $submission->assignment;
        $params['attempt'] = $submission->attemptnumber;
        $gradeids = $DB->get_fieldset_select(
            'assign_grades',
            'id',
            "assignment = :assignment AND attemptnumber = :attempt AND userid $insql",
            $params
        );
        $markids = [];
        if ($gradeids) {
            [$insql, $params] = $DB->get_in_or_equal($gradeids);
            $markids = $DB->get_fieldset_select('assign_mark', 'id', "gradeid $insql", $params);
        }

        $fs = get_file_storage();
        $contextid = $file->get_contextid();
        $deleted = 0;
        foreach ([[self::EDITPDF_GRADE_AREAS, $gradeids], [self::EDITPDF_MARK_AREAS, $markids]] as [$areas, $itemids]) {
            foreach ($areas as $area) {
                foreach ($itemids as $itemid) {
                    foreach ($fs->get_area_files($contextid, 'assignfeedback_editpdf', $area, $itemid, 'id', false) as $copy) {
                        $copy->delete();
                        $deleted++;
                    }
                }
            }
        }
        return $deleted;
    }
}
