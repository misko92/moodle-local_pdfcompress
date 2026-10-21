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
 * Tests for PDF submission compression.
 *
 * @package    local_pdfcompress
 * @copyright  2026 Lukasz Miskowicz
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_pdfcompress\compressor
 * @covers     \local_pdfcompress\observer
 */
final class compressor_test extends \advanced_testcase {
    /** @var string Fixture: two pages, each with an 800x600 picture shown 2 inches wide. */
    const FIXTURE = 'local/pdfcompress/tests/fixtures/photos.pdf';

    /**
     * Skip if Ghostscript is not available, and compress everything regardless of size.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        if (!compressor::get_gs_path()) {
            $this->markTestSkipped('Ghostscript is not installed.');
        }
        set_config('minsizemb', 0, 'local_pdfcompress');
    }

    /**
     * Create an assignment with file submissions and a student.
     *
     * @return array [cm, student]
     */
    protected function create_assignment(): array {
        $course = $this->getDataGenerator()->create_course();
        $assign = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'assignsubmission_file_enabled' => 1,
            'assignsubmission_file_maxfiles' => 5,
            'assignsubmission_file_maxsizebytes' => 10 * 1024 * 1024,
        ]);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        return [get_coursemodule_from_instance('assign', $assign->id), $student];
    }

    /**
     * Get the files in a student's submission.
     *
     * @param \stdClass $cm
     * @return \stored_file[]
     */
    protected function get_submission_files(\stdClass $cm): array {
        $fs = get_file_storage();
        return array_values($fs->get_area_files(
            \context_module::instance($cm->id)->id,
            'assignsubmission_file',
            'submission_files',
            false,
            'filename',
            false
        ));
    }

    /**
     * Submitting a picture-heavy PDF replaces it with a smaller copy.
     */
    public function test_submission_is_compressed(): void {
        global $CFG, $DB;
        [$cm, $student] = $this->create_assignment();
        $originalsize = filesize($CFG->dirroot . '/' . self::FIXTURE);

        $this->getDataGenerator()->get_plugin_generator('mod_assign')->create_submission([
            'userid' => $student->id,
            'cmid' => $cm->id,
            'file' => self::FIXTURE,
        ]);

        $files = $this->get_submission_files($cm);
        $this->assertCount(1, $files);
        $this->assertSame('photos.pdf', $files[0]->get_filename());
        $this->assertLessThan($originalsize * 0.5, $files[0]->get_filesize());
        $this->assertStringStartsWith('%PDF', $files[0]->get_content());

        $log = $DB->get_record('local_pdfcompress_log', []);
        $this->assertEquals($originalsize, $log->oldsize);
        $this->assertEquals($files[0]->get_filesize(), $log->newsize);
        $this->assertEquals(200, $log->dpi);
    }

    /**
     * Files under the size limit, non-PDFs and a disabled plugin are left alone.
     */
    public function test_files_left_alone(): void {
        global $CFG, $DB;
        [$cm, $student] = $this->create_assignment();
        $originalsize = filesize($CFG->dirroot . '/' . self::FIXTURE);
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_assign');

        set_config('minsizemb', 5, 'local_pdfcompress');
        $generator->create_submission(['userid' => $student->id, 'cmid' => $cm->id, 'file' => self::FIXTURE]);
        $this->assertEquals($originalsize, $this->get_submission_files($cm)[0]->get_filesize());

        set_config('minsizemb', 0, 'local_pdfcompress');
        set_config('enabled', 0, 'local_pdfcompress');
        $generator->create_submission(['userid' => $student->id, 'cmid' => $cm->id, 'file' => self::FIXTURE]);
        $this->assertEquals($originalsize, $this->get_submission_files($cm)[0]->get_filesize());

        $this->assertSame(0, $DB->count_records('local_pdfcompress_log'));
    }

    /**
     * A file the plugin already compressed is not compressed again when the submission is saved again.
     */
    public function test_not_compressed_twice(): void {
        global $DB;
        [$cm] = $this->create_assignment();
        $file = $this->create_file($cm, 'photos.pdf', file_get_contents($this->fixture_path()));

        $this->assertNotNull(compressor::compress_stored_file($file));
        $this->assertNull(compressor::compress_stored_file($file));
        $this->assertSame(1, $DB->count_records('local_pdfcompress_log'));
    }

    /**
     * Broken PDFs and other file types are left as they are.
     */
    public function test_broken_and_non_pdf(): void {
        [$cm] = $this->create_assignment();
        $broken = $this->create_file($cm, 'broken.pdf', 'not really a pdf');
        $this->assertNull(compressor::compress_stored_file($broken));
        $this->assertSame('not really a pdf', $broken->get_content());

        $text = $this->create_file($cm, 'notes.txt', file_get_contents($this->fixture_path()));
        $this->assertNull(compressor::compress_stored_file($text));
    }

    /**
     * The resolution setting changes how much is saved.
     */
    public function test_dpi_setting(): void {
        [$cm] = $this->create_assignment();
        set_config('dpi', 100, 'local_pdfcompress');
        $low = compressor::compress_stored_file($this->create_file($cm, 'a.pdf', file_get_contents($this->fixture_path())));
        set_config('dpi', 300, 'local_pdfcompress');
        $high = compressor::compress_stored_file($this->create_file($cm, 'b.pdf', file_get_contents($this->fixture_path())));
        $this->assertLessThan($high->newsize, $low->newsize);
        $this->assertEquals(300, $high->dpi);
    }

    /**
     * Full path of the fixture.
     *
     * @return string
     */
    protected function fixture_path(): string {
        global $CFG;
        return $CFG->dirroot . '/' . self::FIXTURE;
    }

    /**
     * Store a file in an assignment's submission area.
     *
     * @param \stdClass $cm
     * @param string $filename
     * @param string $content
     * @return \stored_file
     */
    protected function create_file(\stdClass $cm, string $filename, string $content): \stored_file {
        return get_file_storage()->create_file_from_string([
            'contextid' => \context_module::instance($cm->id)->id,
            'component' => 'assignsubmission_file',
            'filearea' => 'submission_files',
            'itemid' => 1,
            'filepath' => '/',
            'filename' => $filename,
        ], $content);
    }
}
