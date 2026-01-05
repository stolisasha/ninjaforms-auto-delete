# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**Ninja Forms - Auto Delete** is a WordPress Add on for the Wordpress Plugin Ninja Forms that provides automated GDPR-compliant data retention management for Ninja Forms. It automatically deletes form submissions and uploaded files after configurable retention periods. It should solve two tasks: (1) delete sumissions that are older than the defined deadline and (2) delete File Uploads, that are older than the deadline. The second task is optional: If the User uses Ninja Forms - File Uploads Addon, the Ninja Forms - Auto Delete should be able to delete these uploads.  

**Requirements:**
- PHP 7.4+
- WordPress 5.0+
- Ninja Forms 3.0+ (hard dependency)

## Architecture

### Core Components

The plugin follows a class-based architecture with four main components:

1. **NF_AD_Logger** (`includes/class-nf-ad-logger.php`)
   - Single source of truth for all logging operations
   - Manages two database tables: `nf_ad_logs` (submission-level logs) and `nf_ad_cron_runs` (execution logs)
   - Handles DB schema installation and versioning via `dbDelta()`
   - Automatically marks orphaned runs as timeout after 1 hour
   - Implements log rotation with configurable retention limits

2. **NF_AD_Submissions_Eraser** (`includes/class-nf-ad-submissions-eraser.php`)
   - Orchestrates the cleanup workflow for both cron and manual execution
   - Implements batch processing (default: 50 submissions per batch) with time limits (20 seconds)
   - Supports three submission actions: `keep`, `trash`, `delete`
   - Delegates file deletion to `NF_AD_Uploads_Deleter`
   - Handles dry-run calculations for UI simulation
   - Uses WordPress site timezone (`current_datetime()`) for all date calculations, NOT server time

3. **NF_AD_Uploads_Deleter** (`includes/class-nf-ad-uploads-deleter.php`)
   - Handles physical file deletion with comprehensive security checks
   - **Jail Check**: Ensures all operations are strictly confined to WordPress uploads directory
   - **Symlink Protection**: Never follows or deletes symlinks
   - Supports multiple file reference formats: URLs, absolute paths, attachment IDs, serialized/JSON arrays
   - Handles both legacy meta-based uploads and the official Ninja Forms File Uploads add-on table (`wp_ninja_forms_uploads`)
   - Detects table schema dynamically to handle different add-on versions

4. **NF_AD_Dashboard** (`includes/class-nf-ad-dashboard.php`)
   - Admin UI controller with tabbed interface (Log, Rules, Settings)
   - All CSS/JS is inline (no separate asset files)
   - Handles AJAX endpoints for manual cleanup, dry-run calculations, log management, and retry operations
   - Uses nonce validation for all admin actions

### Bootstrap & Initialization Flow

1. Main plugin file (`ninjaforms-auto-delete.php`) loads constants and checks PHP version
2. Logger is loaded early (needed for activation and DB setup)
3. `plugins_loaded` hook (priority 20) checks for Ninja Forms dependency
4. Other classes are loaded only after Ninja Forms is confirmed active
5. **Self-healing cron**: On init, validates that scheduled event exists if cron is enabled

### Data Flow

**Cleanup Execution (Cron or Manual):**
1. `NF_AD_Submissions_Eraser::run_cleanup_logic()` starts a run via Logger
2. Iterates through all forms and applies retention rules (global default, custom per-form, or never delete)
3. For each form, processes batches of submissions using `process_form()`
4. For each submission:
   - Optionally calls `NF_AD_Uploads_Deleter::cleanup_files()` to delete file attachments
   - For forms with the File Uploads add-on table, calls `cleanup_uploads_for_form()` once per form
   - Executes submission action (delete/trash/keep) via WordPress core functions
   - Logs result per submission via `NF_AD_Logger::log()`
5. Run continues until no more qualifying submissions found or time limit reached
6. Finishes run via `NF_AD_Logger::finish_run()` with final status

**File Deletion:**
- Supports legacy meta-based storage (`_field_{field_id}` post meta)
- Supports official File Uploads add-on table (`wp_ninja_forms_uploads`)
- Normalizes all file references (serialized PHP, JSON, URLs, paths, attachment IDs)
- Always enforces security checks before deletion
- **OPTIMIZED:** Uses `smart_file_exists()` to skip external storage (OneDrive, S3) - massive performance boost
- **ARCHITECTURE:** Dry-Run logic is now in `NF_AD_Uploads_Deleter::calculate_dry_run()` (not in Submissions Eraser)

### Security Features

1. **Symlink Protection**: `is_link()` check before any file operation
2. **Jail Check**: All file paths validated against `realpath()` of uploads directory
3. **Nonce Validation**: All AJAX endpoints require valid nonce
4. **Capability Check**: All admin operations require `manage_options`
5. **Safe Deletion**: Uses `wp_delete_file()` instead of direct `unlink()`

## Development Commands

This is a WordPress plugin with no build process. Development is done directly with PHP files.

### Testing

**Manual Testing:**
1. Install WordPress with Ninja Forms active
2. Activate the plugin via WP admin
3. Navigate to **Ninja Forms > Auto Delete**
4. Configure retention rules and test with dry-run mode before actual deletion

**Testing Cron:**
```bash
# Trigger the cron event manually via WP-CLI
wp cron event run nf_ad_daily_event

# List scheduled cron events
wp cron event list
```

### Database

**Inspect Plugin Tables:**
```bash
# Via WP-CLI
wp db query "SELECT * FROM wp_nf_ad_logs ORDER BY time DESC LIMIT 10"
wp db query "SELECT * FROM wp_nf_ad_cron_runs ORDER BY time DESC LIMIT 10"
```

**Reset Plugin State:**
```bash
# Clear all logs (via WP-CLI)
wp option delete nf_ad_settings
wp option delete nf_ad_db_version
wp db query "DROP TABLE IF EXISTS wp_nf_ad_logs"
wp db query "DROP TABLE IF EXISTS wp_nf_ad_cron_runs"
# Then reactivate the plugin
```

## Key Implementation Details

### Timezone Handling

**CRITICAL**: The plugin uses WordPress site timezone for all date calculations, NOT server timezone.

- Cutoff dates are calculated using `current_datetime()->modify('-N days')`
- Cron scheduling uses `wp_timezone()` to calculate next execution time
- All database datetime fields store values in site timezone

### Batch Processing

- Default batch size: 50 submissions
- Time limit per execution: 20 seconds
- Returns `has_more` flag to UI for progressive manual cleanup
- Cron runs until complete or time limit reached (next cron run continues)

### Post Status Handling

When `sub_handling = 'delete'`:
- Query includes ALL post statuses (including trash) for GDPR compliance
- Already-trashed submissions must be permanently deleted

When `sub_handling = 'trash'` or `'keep'`:
- Query excludes trash status (performance optimization, avoid reprocessing)

### File Reference Formats

The plugin handles multiple storage formats for uploaded files:

1. **Serialized array** (legacy): `a:1:{i:0;s:50:"https://..."}`
2. **JSON array**: `[{"file_url":"https://...","file_name":"test.pdf"}]`
3. **Direct URL string**: `https://example.com/uploads/file.pdf`
4. **Absolute path**: `/var/www/wp-content/uploads/file.pdf`
5. **Attachment ID** (when "Save to Media Library" enabled): `123`
6. **File Uploads add-on table**: Separate table with metadata

All formats are normalized via `normalize_upload_data()` before processing.

### Logging Strategy

**Submission-level logs** (`nf_ad_logs`):
- One log entry per submission processed
- Includes form ID, submission ID, submission date, status, and detailed message
- Status values: `success`, `error`, `warning`
- Messages prefixed with action tags: `[DELETE]`, `[TRASH]`, `[FILES]`, `[SKIP]`

**Run-level logs** (`nf_ad_cron_runs`):
- One log entry per cleanup execution
- Includes run type tag: `[CRON]` or `[MANUAL]`
- Tracks total processed count, errors, and warnings
- Status values: `running`, `success`, `error`, `warning`, `skipped`

**Log rotation**: Both tables auto-cleanup to configurable limits (default: 256 entries for logs, 50 for runs)

## Performance Optimizations (2025-01)

### smart_file_exists() Function
Inspired by the NF File Uploads Add-on, this function drastically improves performance:

```php
private static function smart_file_exists( $upload_data ) {
    // FAST: External storage (OneDrive, S3) - no disk check needed!
    if ( isset( $upload_data['upload_location'] ) && 'server' !== $upload_data['upload_location'] ) {
        return false;  // Don't count/delete external uploads
    }

    // FAST: Flag check instead of disk check
    if ( isset( $upload_data['removed_from_server'] ) && $upload_data['removed_from_server'] ) {
        return false;
    }

    // ONLY HERE: Actual disk check for local server uploads
    return isset( $upload_data['file_path'] ) && file_exists( $upload_data['file_path'] );
}
```

**Performance gain:** With OneDrive-plugin moving files externally:
- **Before:** 20+ seconds (timeout) checking thousands of non-existent files
- **After:** < 2 seconds (skips external storage completely)

### Dry-Run Architecture
**IMPORTANT:** Dry-Run methods are now in their respective classes:
- `NF_AD_Submissions_Eraser::calculate_dry_run()` → Counts submissions only
- `NF_AD_Uploads_Deleter::calculate_dry_run()` → Counts uploads only

Dashboard AJAX handler delegates to the correct class based on `type` parameter.

### Batch Loading Optimization
Uses WordPress `update_metadata_cache( 'post', $ids )` to pre-load all meta in one query instead of thousands of individual `get_post_meta()` calls.

### Crash Safety
- Upload table queries limited to 5000 rows max
- Meta queries limited to 1000 submissions max
- Time limits enforced throughout

## Common Pitfalls

1. **Never run file operations outside WordPress uploads directory** - The jail check will fail and return errors
2. **Don't bypass the Logger** - Always use `NF_AD_Logger::log()` for consistency
3. **Don't assume Ninja Forms is loaded** - Always check `class_exists('Ninja_Forms')` before using NF API
4. **Don't use server timezone** - Always use `current_datetime()` or `wp_timezone()`
5. **Don't delete symlinks** - The `is_link()` check is a security feature, not optional
6. **Mixed installations**: Installations may have BOTH meta-based uploads and the add-on table; handle both sources
7. **External Storage**: Always use `smart_file_exists()` to check if files should be processed (OneDrive, S3, etc.)
8. **Dry-Run consistency**: Ensure dry-run uses EXACTLY the same logic as actual deletion (same filters, same checks)
