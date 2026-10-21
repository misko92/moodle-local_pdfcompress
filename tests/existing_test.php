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
 * Tests for compressing already-submitted PDFs.
 *
 * @package    local_pdfcompress
 * @copyright  2026 Lukasz Miskowicz
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_pdfcompress\existing
 */
final class existing_test extends \advanced_testcase {
    /**
     * Skip if Ghostscript is not available.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        if (!compressor::get_gs_path()) {
            $this->markTestSkipped('Ghostscript is not installed.');
        }
    }

    /**
     * Old submissions are found, compressed, and their annotation copies cleared.
     */
    public function test_find_and_process(): void {
        global $DB;
        // Submit while the plugin is off, as if it had not been installed yet.
        set_config('enabled', 0, 'local_pdfcompress');
        $course = $this->getDataGenerator()->create_course(['shortname' => 'CHEM']);
        $assign = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'name' => 'Lab 1',
            'assignsubmission_file_enabled' => 1,
            'assignsubmission_file_maxfiles' => 5,
            'assignsubmission_file_maxsizebytes' => 10 * 1024 * 1024,
        ]);
        $cm = get_coursemodule_from_instance('assign', $assign->id);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->getDataGenerator()->get_plugin_generator('mod_assign')->create_submission([
            'userid' => $student->id,
            'cmid' => $cm->id,
            'file' => compressor_test::FIXTURE,
        ]);
        $otheruser = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->getDataGenerator()->get_plugin_generator('mod_assign')->create_submission([
            'userid' => $otheruser->id,
            'cmid' => $cm->id,
            'file' => compressor_test::FIXTURE,
        ]);
        set_config('enabled', 1, 'local_pdfcompress');

        // Pretend a teacher opened the first student's submission in the annotation tool.
        $submission = $DB->get_record('assign_submission', ['assignment' => $assign->id, 'userid' => $student->id]);
        $grade = (object) [
            'assignment' => $assign->id, 'userid' => $student->id, 'attemptnumber' => $submission->attemptnumber,
            'grader' => 0, 'grade' => -1, 'timecreated' => time(), 'timemodified' => time(),
        ];
        $grade->id = $DB->insert_record('assign_grades', $grade);
        $contextid = \context_module::instance($cm->id)->id;
        $fs = get_file_storage();
        $fs->create_file_from_string([
            'contextid' => $contextid, 'component' => 'assignfeedback_editpdf', 'filearea' => 'combined',
            'itemid' => $grade->id, 'filepath' => '/', 'filename' => 'combined.pdf',
        ], 'old combined copy');

        // Filters and size limits.
        $this->assertCount(2, existing::find(0));
        $this->assertCount(2, existing::find(0, $course->id));
        $this->assertCount(2, existing::find(0, 0, $cm->id));
        $this->assertCount(0, existing::find(0, $course->id + 1000));
        $this->assertCount(0, existing::find(50 * 1024 * 1024));
        $found = existing::find(0, 0, $cm->id);
        $this->assertSame('CHEM', $found[0]->course);
        $this->assertSame('Lab 1', $found[0]->assignment);

        // Process the first student's file.
        $fileid = $DB->get_field('files', 'id', [
            'component' => 'assignsubmission_file', 'filearea' => 'submission_files', 'itemid' => $submission->id,
            'filename' => 'photos.pdf',
        ]);
        $log = existing::process((int) $fileid, 150);
        $this->assertNotNull($log);
        $this->assertEquals(150, $log->dpi);
        $this->assertLessThan($log->oldsize, $log->newsize);
        $this->assertTrue($fs->is_area_empty($contextid, 'assignfeedback_editpdf', 'combined', $grade->id));

        // Only the other student's file is left to do, and processing it again does nothing.
        $this->assertCount(1, existing::find(0));
        $this->assertNull(existing::process((int) $fileid));
    }
}
