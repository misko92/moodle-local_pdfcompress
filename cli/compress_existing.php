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
 * Compress PDFs that were submitted to assignments before this plugin was installed.
 *
 * @package    local_pdfcompress
 * @copyright  2026 Lukasz Miskowicz
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognised] = cli_get_params([
    'run' => false,
    'courseid' => 0,
    'cmid' => 0,
    'minsizemb' => null,
    'dpi' => null,
    'limit' => 0,
    'help' => false,
], ['h' => 'help']);

if ($unrecognised) {
    cli_error(get_string('cliunknowoption', 'admin', implode(PHP_EOL, $unrecognised)));
}

if ($options['help']) {
    echo "Compress PDFs already submitted to assignments.

Without --run this only lists what would be compressed. The original files are not kept.

Options:
  --run             Compress the files (otherwise only list them).
  --courseid=ID     Only this course.
  --cmid=ID         Only this assignment (course module id, the id= in the assignment's URL).
  --minsizemb=N     Only files larger than N MB (default: the plugin setting).
  --dpi=N           Image resolution (default: the plugin setting).
  --limit=N         Stop after N files.
  -h, --help        Show this help.

Run it as the web server user from the Moodle directory, e.g.:
  sudo -u <webuser> php public/local/pdfcompress/cli/compress_existing.php --courseid=12
  sudo -u <webuser> php public/local/pdfcompress/cli/compress_existing.php --courseid=12 --run
";
    exit(0);
}

if (!\local_pdfcompress\compressor::get_gs_path()) {
    cli_error('Ghostscript was not found. Check Site administration > Server > System paths > Path to ghostscript.');
}

$minsizemb = $options['minsizemb'] ?? (int) get_config('local_pdfcompress', 'minsizemb');
$dpi = $options['dpi'] !== null ? (int) $options['dpi'] : null;
if ($dpi !== null && ($dpi < 50 || $dpi > 600)) {
    cli_error('--dpi must be between 50 and 600.');
}

$files = \local_pdfcompress\existing::find((int) $minsizemb * 1024 * 1024, (int) $options['courseid'], (int) $options['cmid']);
if ($options['limit'] > 0) {
    $files = array_slice($files, 0, (int) $options['limit']);
}

$total = array_sum(array_column($files, 'filesize'));
cli_writeln(count($files) . ' PDF(s) larger than ' . $minsizemb . ' MB, ' . display_size($total) . ' in total.');

if (!$options['run']) {
    foreach ($files as $f) {
        cli_writeln(sprintf('  %10s  %s / %s / %s', display_size($f->filesize), $f->course, $f->assignment, $f->filename));
    }
    cli_writeln('Nothing changed. Add --run to compress these files.');
    exit(0);
}

$before = 0;
$after = 0;
$done = 0;
foreach ($files as $i => $f) {
    $prefix = sprintf('[%d/%d] %s / %s / %s: ', $i + 1, count($files), $f->course, $f->assignment, $f->filename);
    try {
        $log = \local_pdfcompress\existing::process($f->id, $dpi);
    } catch (\Throwable $e) {
        cli_writeln($prefix . 'error, left as it was (' . $e->getMessage() . ')');
        continue;
    }
    if ($log) {
        $done++;
        $before += $log->oldsize;
        $after += $log->newsize;
        cli_writeln($prefix . display_size($log->oldsize) . ' -> ' . display_size($log->newsize));
    } else {
        cli_writeln($prefix . 'left as it was (could not be made meaningfully smaller)');
    }
}
cli_writeln("Compressed $done file(s): " . display_size($before) . ' -> ' . display_size($after) .
    ', saved ' . display_size($before - $after) . '.');
