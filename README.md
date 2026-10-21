# PDF submission compression (local_pdfcompress)

Students often submit PDFs full of phone photos (for example Notability
exports). A three-page lab report can be 30–50 MB because every photo is
stored at full camera resolution, even though it appears only an inch or two
wide on the page.

When a student saves an assignment **file submission**, this plugin rewrites
each large PDF with Ghostscript, scaling the pictures down to a set
resolution. Handwriting and text drawn in the PDF are not pictures, so they
are not affected. In testing, real 26–30 MB Notability lab reports became
0.7–0.9 MB at 200 dpi, taking about 1.5 seconds each.

- The smaller file **replaces** the original (same name, same file record);
  the original is not kept.
- If compressing fails, changes the page count, or would not save at least
  10%, the original is left as it is. A submission never fails because of
  this plugin.
- Files are compressed during the save, before Moodle's PDF annotation
  (editpdf) makes its own copy, so that copy is small too.
- A file the plugin has already compressed is never compressed again.

## Settings

*Site administration → Plugins → Local plugins → PDF submission compression*

| Setting | Default |
|---|---|
| Compress PDF submissions | On |
| Image resolution | 200 dpi (choices: 100, 150, 200, 300) |
| Only compress files larger than (MB) | 5 |

The settings page also shows how much space has been saved so far.

## Requirements

- Moodle 5.2 or 5.3.
- Ghostscript, at the path set in *Site administration → Server → System
  paths → Path to ghostscript* (the same setting Moodle's PDF annotation uses).

## Licence

GNU GPL v3 or later.
