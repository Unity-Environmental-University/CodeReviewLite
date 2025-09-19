# CodeReviewLite

CodeReviewLite is a self-contained PHP snippet review tool you can drop onto any PHP-capable host. Paste one or more files, send out the shareable link, and collect line-level threaded comments or suggested edits—perfect for lightweight reviews and Trello card attachments when a full-blown code review system is overkill.

## Highlights
- Single `index.php` file plus a writable `data/` directory—no database or framework
- Paste or upload (optional) up to 10 files per review with syntax highlighting via Highlight.js
- Threaded line comments, multi-line selections, and "suggest edit" workflows
- Optional admin token to toggle review status, resolve individual comments, or delete reviews
- JSON-backed storage for straightforward backups and portability

## Requirements
- PHP 8.0+ (typed functions, `random_bytes`, and JSON extensions are required)
- Web server configured to execute PHP (Apache with mod_php, nginx + php-fpm, or the PHP built-in server)
- Writable `data/` directory located beside `index.php`

## Quick Start
1. Copy `index.php` into your target directory.
2. Create a sibling `data/` directory and grant the web server write access (e.g. `chmod 775 data`).
3. (Optional) Adjust the configuration constants near the top of `index.php`.
4. Serve the file:
   ```bash
   php -S localhost:8000 index.php
   ```
5. Visit `http://localhost:8000` to create and share reviews.

## Deployment Notes
- For production, place `index.php` on any PHP-enabled host or behind your existing reverse proxy.
- The app writes JSON files into `data/`; ensure the directory lives on fast storage and is included in your backup routine.
- HTTPS is strongly recommended when exposing the tool beyond trusted networks.

## Configuration Reference
All options live near the top of `index.php` and can be overridden via `define()` before including the file.

| Constant | Default | Description |
| --- | --- | --- |
| `APP_NAME` | `CodeReviewLite` | UI title and browser tab text |
| `DATA_DIR` | `__DIR__ . '/data'` | Location where review and comment JSON files are stored |
| `ALLOW_FILE_UPLOADS` | `false` | When `true`, enables file uploads in addition to pasted snippets |
| `REQUIRE_CREATE_TOKEN` | `false` | Gate review creation behind a shared secret token |
| `CREATE_TOKEN` | `''` | Token value expected when `REQUIRE_CREATE_TOKEN` is enabled |
| `ALLOW_ANON_COMMENTS` | `true` | Allow comments without a name; set to `false` to require identification |
| `ADMIN_DELETE_TOKEN` | `''` | Token used to unlock admin controls (append `?admin=TOKEN` to URLs) |
| `MAX_FILES` | `10` | Maximum number of files per review |
| `MAX_SIZE` | `500 * 1024` | Maximum bytes stored per file or suggestion |

## Using the App
- **Create a review:** Paste code (and optional filenames) into the provided form, then share the resulting URL.
- **Leave feedback:** Click or drag to select code lines. Choose comment or suggest edit mode. Suggestions prefill the snippet to streamline copy/paste.
- **Track status:** Reviews and comments show resolved state. Admin users can toggle review-wide status or mark individual comments resolved.
- **Moderate:** With a valid admin token (`?admin=TOKEN`), extra controls appear to resolve comments or delete the entire review.

## Data Layout
Each review is stored as `<id>.json`; threaded comments live in `<id>-comments.json`. Files include metadata such as title, author (optional), creation timestamp, per-file contents, and resolved flags. Comments capture author, line range, body, suggestion text, timestamps, and resolution state, making manual edits and exports straightforward.

## Tips
- Keep review IDs safe—they double as the URLs for sharing.
- If you enable uploads, update your PHP `upload_max_filesize` and `post_max_size` settings to match `MAX_SIZE`.
- Pair with Trello or similar tools by dropping the review link into tickets for lightweight code collaboration.

