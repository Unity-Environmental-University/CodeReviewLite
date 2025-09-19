# CodeReviewLite

A lightweight, self-contained code review tool designed for teams that need efficient collaboration without the overhead of enterprise solutions. Deploy instantly on any PHP-capable server and start collecting threaded feedback on code snippets and files.

## Key Features
- Zero-dependency deployment: single `index.php` file with local JSON storage
- Multi-file review support with syntax highlighting for 180+ languages
- Line-level threaded comments and inline edit suggestions
- Administrative controls for review lifecycle management
- Portable data format for easy backup and migration

## Technical Requirements
- PHP 8.0+ with JSON extension
- Web server with PHP execution capability (Apache, nginx, or similar)
- Writable `data/` directory for review storage

## Installation
1. Deploy `index.php` to your web server directory
2. Create a writable `data/` directory alongside the file:
   ```bash
   mkdir data && chmod 755 data
   ```
3. Configure application settings by editing constants in `index.php`
4. Access the application through your web server

## Production Deployment
- **Security**: Enable HTTPS for all deployments handling sensitive code
- **Storage**: Ensure the `data/` directory is included in backup procedures
- **Performance**: Place on fast storage for optimal JSON read/write operations
- **Integration**: Compatible with reverse proxies and existing infrastructure

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

## Usage Guide

### Creating Reviews
Submit code snippets or files through the web interface to generate a unique review URL for team collaboration.

### Providing Feedback
- Select code lines to add threaded comments or suggest modifications
- Use inline editing mode for specific change recommendations
- Track comment resolution status throughout the review lifecycle

### Administrative Functions
Administrative controls are available with proper authentication:
- Mark reviews as complete or archive obsolete discussions
- Resolve individual comment threads
- Remove reviews when no longer needed

## Data Architecture
Reviews are stored as individual JSON files with the following structure:
- `<review-id>.json`: Contains review metadata, file contents, and status
- `<review-id>-comments.json`: Stores threaded comments with line mappings

This design enables straightforward data migration, backup procedures, and manual intervention when required.

## Best Practices
- **Access Control**: Distribute review URLs only to authorized team members
- **Server Configuration**: Adjust PHP upload limits if file upload functionality is enabled
- **Workflow Integration**: Embed review links in project management tools for centralized tracking

