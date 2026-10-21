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
 * Shrinks PDFs by re-writing them with Ghostscript, downsampling images to a set resolution.
 *
 * @package    local_pdfcompress
 * @copyright  2026 Lukasz Miskowicz
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class compressor {
    /** @var int Give up on a single file after this many seconds. */
    const TIMEOUT = 180;

    /** @var float Only keep the result if it is at most this fraction of the original size. */
    const MINSAVING = 0.9;

    /** @var string Ghostscript distiller params for good-quality JPEG output. */
    const JPEG_PARAMS = '<< /ColorImageDict << /QFactor 0.15 /Blend 1 /HSamples [1 1 1 1] /VSamples [1 1 1 1] >>'
        . ' /GrayImageDict << /QFactor 0.15 /Blend 1 /HSamples [1 1 1 1] /VSamples [1 1 1 1] >> >> setdistillerparams';

    /**
     * Compress a stored PDF in place if it is big enough and the result is worth keeping.
     *
     * @param \stored_file $file
     * @return \stdClass|null Log record if the file was replaced, null if it was left alone.
     */
    public static function compress_stored_file(\stored_file $file): ?\stdClass {
        global $DB;

        if ($file->is_directory() || $file->is_external_file() || !self::is_pdf($file)) {
            return null;
        }
        $minsize = max(0, (int) get_config('local_pdfcompress', 'minsizemb')) * 1024 * 1024;
        if ($file->get_filesize() < $minsize) {
            return null;
        }
        // Never compress our own output again (each save of the submission fires the event).
        if ($DB->record_exists('local_pdfcompress_log', ['newhash' => $file->get_contenthash()])) {
            return null;
        }

        $dpi = (int) get_config('local_pdfcompress', 'dpi') ?: 200;
        $dir = make_request_directory();
        $in = $dir . '/in.pdf';
        $out = $dir . '/out.pdf';
        $file->copy_content_to($in);

        $start = microtime(true);
        if (!self::compress_path($in, $out, $dpi)) {
            return null;
        }
        $duration = microtime(true) - $start;

        $newsize = filesize($out);
        if (!$newsize || $newsize > $file->get_filesize() * self::MINSAVING) {
            return null;
        }

        // Swap the content into the existing file record, keeping its id, name, author and dates.
        $fs = get_file_storage();
        $temp = $fs->create_file_from_pathname([
            'contextid' => $file->get_contextid(),
            'component' => 'local_pdfcompress',
            'filearea' => 'temp',
            'itemid' => $file->get_id(),
            'filepath' => '/',
            'filename' => $file->get_filename(),
            'userid' => $file->get_userid(),
        ], $out);
        $oldhash = $file->get_contenthash();
        $oldsize = $file->get_filesize();
        $file->replace_file_with($temp);
        $temp->delete();

        $log = (object) [
            'contextid' => $file->get_contextid(),
            'oldhash' => $oldhash,
            'newhash' => $file->get_contenthash(),
            'oldsize' => $oldsize,
            'newsize' => $file->get_filesize(),
            'dpi' => $dpi,
            'duration' => round($duration, 2),
            'timecreated' => time(),
        ];
        $log->id = $DB->insert_record('local_pdfcompress_log', $log);
        return $log;
    }

    /**
     * Is this a PDF?
     *
     * @param \stored_file $file
     * @return bool
     */
    protected static function is_pdf(\stored_file $file): bool {
        return $file->get_mimetype() === 'application/pdf'
            || strtolower(pathinfo($file->get_filename(), PATHINFO_EXTENSION)) === 'pdf';
    }

    /**
     * Path to Ghostscript, from the site's "Ghostscript path" setting.
     *
     * @return string|null
     */
    public static function get_gs_path(): ?string {
        global $CFG;
        $path = $CFG->pathtogs ?? '';
        return ($path !== '' && is_executable($path)) ? $path : null;
    }

    /**
     * Write a downsampled copy of a PDF and check it has the same number of pages.
     *
     * @param string $in Source PDF path.
     * @param string $out Destination PDF path.
     * @param int $dpi Target image resolution.
     * @return bool True if $out is a good copy.
     */
    public static function compress_path(string $in, string $out, int $dpi): bool {
        $gs = self::get_gs_path();
        if (!$gs) {
            return false;
        }
        $res = (string) $dpi;
        $args = [
            '-dSAFER', '-dBATCH', '-dNOPAUSE',
            '-sDEVICE=pdfwrite',
            '-dCompatibilityLevel=1.5',
            '-dDetectDuplicateImages=true',
            '-dDownsampleColorImages=true', '-dColorImageDownsampleType=/Bicubic', "-dColorImageResolution=$res",
            '-dDownsampleGrayImages=true', '-dGrayImageDownsampleType=/Bicubic', "-dGrayImageResolution=$res",
            // Black-and-white (1-bit) images stay sharp at a higher resolution and are tiny anyway.
            '-dDownsampleMonoImages=true', '-dMonoImageDownsampleType=/Subsample', '-dMonoImageResolution=' . max(300, 2 * $dpi),
            '-dAutoFilterColorImages=false', '-dColorImageFilter=/DCTEncode',
            '-dAutoFilterGrayImages=false', '-dGrayImageFilter=/DCTEncode',
            '-sOutputFile=' . $out,
            // Gentle JPEG settings (Ghostscript's default is blocky): high quality, no colour subsampling.
            '-c', self::JPEG_PARAMS,
            '-f', $in,
        ];
        $inpages = self::run_gs($gs, $args);
        if ($inpages === null || !is_readable($out)) {
            return false;
        }
        $outpages = self::count_pages($gs, $out);
        return $outpages !== null && $outpages === $inpages && $inpages > 0;
    }

    /**
     * Count the pages of a PDF by running it through Ghostscript's null device.
     *
     * @param string $gs Ghostscript path.
     * @param string $path PDF path.
     * @return int|null
     */
    protected static function count_pages(string $gs, string $path): ?int {
        return self::run_gs($gs, ['-dSAFER', '-dBATCH', '-dNOPAUSE', '-sDEVICE=nullpage', $path]);
    }

    /**
     * Run Ghostscript and return the page count it reports, or null on failure.
     *
     * @param string $gs Ghostscript path.
     * @param string[] $args
     * @return int|null
     */
    protected static function run_gs(string $gs, array $args): ?int {
        $command = escapeshellarg($gs) . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
        if (is_executable('/usr/bin/timeout')) {
            $command = '/usr/bin/timeout ' . self::TIMEOUT . ' ' . $command;
        }
        $output = [];
        $status = 1;
        exec($command, $output, $status);
        if ($status !== 0) {
            return null;
        }
        // Ghostscript prints "Processing pages 1 through N." for PDF input.
        foreach ($output as $line) {
            if (preg_match('/^Processing pages \d+ through (\d+)\./', $line, $m)) {
                return (int) $m[1];
            }
        }
        return null;
    }

    /**
     * One-line summary of what the plugin has saved so far, for the settings page.
     *
     * @return string
     */
    public static function get_stats_summary(): string {
        global $DB;
        try {
            $row = $DB->get_record_sql('SELECT COUNT(1) AS n, SUM(oldsize) AS oldsize, SUM(newsize) AS newsize
                                          FROM {local_pdfcompress_log}');
        } catch (\dml_exception $e) {
            return '';
        }
        if (empty($row->n)) {
            return get_string('stats_none', 'local_pdfcompress');
        }
        return get_string('stats', 'local_pdfcompress', (object) [
            'count' => $row->n,
            'before' => display_size((int) $row->oldsize),
            'after' => display_size((int) $row->newsize),
            'saved' => display_size((int) $row->oldsize - (int) $row->newsize),
        ]);
    }
}
