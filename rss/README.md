# RSS Importer & Publisher for cPanel

This project provides a secure, production-ready RSS/Atom feed aggregator designed for shared hosting (cPanel/WHM) environments. The importer stores normalized feed data in append-only JSONL files and a SQLite index, and periodically republishes sanitized JSON Feed 1.1, RSS 2.0, and Atom 1.0 feeds.

## Features

- Scheduled feed imports with conditional GET (ETag/Last-Modified)
- Item normalization with HTML sanitization (HTML Purifier)
- Storage in JSONL per feed and SQLite for indexing
- Publisher emits static JSON Feed, RSS, and Atom files (atomic writes)
- Link verifier to detect and flag dead items
- Maintenance script for database vacuum and log rotation
- Lock files prevent overlapping cron executions
- Automatic fetch backoff slows failing feeds and gradually restores cadence after recovery (configurable)
- Optional CLI helpers for scripted feed management
- Pause/resume feeds from the admin UI or CLI without removing stored history
- Mark feeds as private so they continue importing but are excluded from public feeds, combined outputs, and OPML snapshots
- Optional email alerting for feed failures with cooldowns and recovery notifications
- Global URL de-duplication so the importer gracefully skips articles already indexed from other feeds
- Configurable HTTP client with proxy support, custom headers, and retry/backoff controls
- Language detection with per-feed overrides surfaced in the admin UI, CLI, OPML, and published status files
- Per-feed HTTP Basic authentication with admin/CLI controls and optional OPML export (disabled by default for public snapshots)
- Per-feed custom HTTP headers with admin/CLI controls, OPML round-tripping, and metrics visibility
- Hardened admin UI with HTTP Basic Auth, IP allowlist, and CSRF protection
- Cron job telemetry recorded to JSON and surfaced in the admin UI
- Feed-level health metrics exported to `public/status/feeds.json` plus aggregated dashboards at `public/status/metrics.json`
  used by the admin snapshot
- Rolling metrics history appended to `storage/index/metrics_history.jsonl` and graphed in the admin dashboard for quick trend review
- Trending tag summaries aggregated over recent imports and published to `public/status/tags.json` with an accompanying dashboard view
- Latest-item snapshot exported to `public/status/latest.json` with per-feed caps, lookback windows, and optional sanitized HTML summaries
- OPML export (always-on) plus import tools in both the admin UI and CLI
- Built-in diagnostics to validate PHP extensions, permissions, and `.htaccess` protections
- Disk headroom monitoring with configurable thresholds surfaced in diagnostics, metrics.json, health.json, and the admin snapshot
- Runs entirely via PHP CLI + cron without external services

## Directory Layout

```
/rss
  app/            CLI entry points and shared library classes
  admin/          Admin UI (Basic Auth + IP allowlist)
  public/         Published static feeds and status files
  storage/        Private JSONL archives and SQLite database
  logs/           Cron job logs (rotated by maintenance)
```

Private folders (`storage/`, `logs/`) are protected by `.htaccess`. Public feeds live in `public/feeds` and `public/status`.

Status files published in `public/status` include:

- `health.json` &mdash; heartbeat summary with total feeds, paused/overdue/error counters, job snapshots, and a condensed alert summary.
- `feeds.json` &mdash; per-feed metadata including fetch cadence, last/next fetch, item counts, and a `has_notes` flag (notes themselves remain private).
- `metrics.json` &mdash; aggregated totals and averages across all feeds (active vs paused counts, item totals, newest/oldest timestamps, language distribution, alert history, job status mirrors, disk usage snapshots driven by `diagnostics.disk`, and backup counters).
- `backups.json` &mdash; sanitized filesystem snapshot inventory including counts with/without logs/status files, plus latest and oldest creation timestamps for automation.
- `tags.json` &mdash; trending tags derived from recent items with counts, feed coverage, and latest timestamps (configurable lookback window).
- `latest.json` &mdash; newest items aggregated across public feeds with effective timestamps, source metadata, optional sanitized HTML summaries, and truncation details for automation.
- `jobs.json` &mdash; machine-readable details for each cron job (status, last run timestamps, duration, and sanitized context metrics).
- Historical snapshots are also retained privately at `storage/index/metrics_history.jsonl` to power dashboard trends.

## Requirements

- PHP 8.0 or newer with CLI access
- cPanel/WHM hosting with cron jobs
- Composer for dependency installation

## Setup on cPanel

1. **Upload the project**
   - Upload the entire `rss/` directory to your home directory (e.g., `/home/username/rss`).
   - Optional: create a symlink `ln -s ~/rss/public ~/public_html/rss` to expose the generated feeds.

2. **Install dependencies**
   - Log in via SSH and run `cd ~/rss && composer install --no-dev --optimize-autoloader`.

3. **Protect the admin UI**
   - Use cPanel's *Directory Privacy* to configure HTTP Basic Auth for `/rss/admin`.
   - Alternatively, upload a `.htpasswd` file and adjust the provided `.htaccess`.

4. **Configure `config.php`**
   - Edit `app/config.php` and update the IP allowlist, admin realm, and optional HTTP proxy/custom headers.
   - Copy `app/config.local.example.php` to `app/config.local.php` for machine-specific overrides (different proxies, alert
     recipients, etc.). Values defined in `config.local.php` merge with the base config at runtime, so you can keep defaults
     version-controlled while applying secrets locally.
   - IP rules accept single addresses, CIDR blocks (e.g., `203.0.113.0/24`), or explicit ranges (`198.51.100.10-198.51.100.40`).
     Remember to mirror the same entries in `admin/.htaccess` so Apache's `Require ip` guard stays aligned.
   - Tune the HTTP retry controls (`http.retries`, `http.retry_delay_ms`, `http.retry_backoff_factor`, `http.retry_max_delay_ms`) for slower feeds or stricter rate limits.
   - Adjust `scheduling.backoff` to control automatic retry pacing for failing feeds (enable/disable, exponential multiplier, and min/max backoff windows).
   - Enable email alerts by setting `alerts.enabled` to `true`, providing both `alerts.email_to` and `alerts.email_from`, and adjusting `alerts.cooldown_minutes` / `alerts.subject_prefix` as needed. The diagnostics suite will report configuration errors if addresses are missing.
   - Populate `publisher.owner_name` / `publisher.owner_email` if you want them embedded in OPML exports.
   - Adjust `publisher.language` to set the fallback language code used for combined feeds and status exports (individual feeds can override or auto-detect).
   - Adjust `maintenance.prune_dead_after_days` / `maintenance.prune_batch_size` to control stale item pruning cadence.
   - Set `maintenance.backups_keep` to cap how many filesystem snapshots remain after each maintenance run.
   - Tune `diagnostics.disk.warn_free_bytes` / `diagnostics.disk.error_free_bytes` to flag low disk headroom and optionally override `diagnostics.disk.path` if storage lives on a different mount.
   - Tune `integrity.sample_size`, `integrity.count_tolerance`, and `integrity.max_lines` to control how aggressively the integrity scanner compares SQLite rows to JSONL archives (higher limits provide stronger guarantees at the cost of longer runs).
   - Tune `metrics.tags` if you want a different lookback window, minimum tag counts, per-feed item caps, or to include private feeds in the public tag summary.
   - Adjust `status.latest` to control how many recent items are published at `public/status/latest.json`, per-feed caps, lookback windows, and whether sanitized HTML summaries are included. Disable the snapshot entirely by setting `status.latest.enabled` to `false`.

5. **Prepare writable directories**
   - Ensure `storage/`, `storage/backups/`, `logs/`, and `public/` are writable by the web and CLI user.

6. **Verify the environment**
   - Run `php app/cli/diagnostics.php` to confirm PHP extensions, permissions, privacy rules, and disk headroom are in place.
   - Use `--json` for machine-readable output if you want to feed the checks into automation.
   - Once feeds are importing, run `php app/cli/check_integrity.php` (optionally with `--json`) to confirm JSONL archives and SQLite stay in sync; add `--full` for a comprehensive scan before major upgrades or restores.

7. **Add cron jobs** (adjust PHP path as needed):

```
* * * * *  /usr/local/bin/php ~/rss/app/run_importer.php >> ~/rss/logs/import.log 2>&1
*/2 * * * * /usr/local/bin/php ~/rss/app/run_publish.php >> ~/rss/logs/publish.log 2>&1
*/10 * * * * /usr/local/bin/php ~/rss/app/run_verify.php >> ~/rss/logs/verify.log 2>&1
7 3 * * *  /usr/local/bin/php ~/rss/app/run_maintenance.php >> ~/rss/logs/maint.log 2>&1
```

8. **(Optional) Serve public feeds**
   - If your public web root is `~/public_html`, the symlink from step 1 makes generated feeds available at `https://example.com/rss/`.

## Admin Usage

- Browse to `/rss/admin/` with Basic Auth credentials.
- Add a feed by URL, optionally providing a title, fetch interval (minimum 300 seconds), HTTP Basic credentials for protected feeds, and custom request headers for APIs that expect additional metadata.
- Update fetch cadence or queue an immediate fetch using the inline actions next to each feed.
- Pause and resume feeds without deleting them. Paused feeds keep existing items but skip importer and verifier runs until resumed.
- Toggle feed privacy to keep ingesting private sources without publishing them. Private feeds drop from combined feeds, OPML exports, and public JSON/RSS/Atom files, and the publisher removes any previously published artifacts automatically.
- Override feed language detection directly in the dashboard—lock a specific language code or revert to auto-detection using the inline Language action.
- Store or clear per-feed HTTP Basic credentials with the inline **HTTP Auth** action; secrets live in `storage/feeds/*/feed.json` and never appear in public status or OPML exports unless you explicitly opt in.
- Manage custom HTTP headers with the inline **Headers** action; enter one `Name: Value` pair per line to add or update headers, or submit an empty form to clear them entirely.
- Maintain per-feed operator notes using the inline **Notes** action; notes support multi-line text, blanks clear the stored value, and only a `has_notes` flag is exposed in public status exports.
- Remove feeds via the inline delete button (requires confirmation and CSRF token).
- Feed IDs are generated as the lowercase SHA256 hash of the feed URL.
- Review the cron status table and system snapshot to confirm importer, publisher, verifier, and maintenance jobs ran recently.
- Use the System Snapshot metrics (powered by `public/status/metrics.json`) to monitor average items per feed, the age of the newest content, and current disk status/free space against your configured thresholds.
- Review the Backups panel to confirm snapshot inventory, creation times, and included assets; the dashboard reads directly from `public/status/backups.json` so automation and operators see the same data.
- Review the Backoff counters and Err/Suc columns to spot feeds slowed by automatic retries; queuing or forcing a fetch clears the backoff timer once the source recovers.
- Trigger on-demand diagnostics with the inline **Test Fetch** and **Test Conditional** buttons; the dashboard renders HTTP status, the final effective URL, redirect counts, headers (including content encoding), sample items, and parser warnings above the feed list without altering stored history.
- Review the Trending Tags panel (driven by `public/status/tags.json`) to surface popular topics and confirm the tag snapshot cadence.
- Review the Latest Items panel (powered by `public/status/latest.json`) to see the freshest stories across public feeds, the number of feeds represented, and whether any items were truncated by the configured limits.
- Monitor the Recent Alert Activity table to confirm email notifications are firing and to review the last alert subjects.
- Consult the Environment Diagnostics table for quick confirmation that PHP extensions, permissions, privacy controls, and storage integrity remain healthy.
- Import bulk feeds with the OPML form (max 512KB). Feed intervals, language locks, HTTP credentials, custom headers, and privacy flags are preserved when present. Public exports at `public/status/subscriptions.opml` omit credentials, headers, and private feeds by default; the CLI `export_opml.php --include-auth --include-headers --include-private` flags can generate sensitive snapshots when needed.

### CLI helpers

- Register a feed from SSH: `php app/cli/add_feed.php https://example.com/feed.xml "Optional Title" 900 en-US --http-user=feedbot --http-pass='secret' --header="X-Token: abc123" --private` (use `--help` for flag-based arguments, password/header file support, repeatable `--header` inputs, and the `--private` switch).
- Remove a feed by ID: `php app/cli/remove_feed.php <feed-id>`
- Queue an immediate fetch (optionally updating the interval): `php app/cli/reschedule_feed.php <feed-id> [new-interval]`
- Pause a feed: `php app/cli/pause_feed.php <feed-id>`
- Resume a feed (optionally skipping the immediate queue): `php app/cli/resume_feed.php <feed-id> [--no-queue]`
- Inspect feed health from the shell: `php app/cli/list_feeds.php [--refresh-status]` (includes Err/Suc and Backoff columns for quick triage)
- Run an ad-hoc fetch test (with optional header overrides): `php app/cli/test_feed.php --feed=<feed-id> [--conditional] [--json] [--header="Name: Value"]`. Use `--url=<feed-url>` plus `--username/--password` to inspect feeds before registering them. The CLI output now highlights the effective URL, redirect chain, and response encoding alongside the raw headers so you can confirm proxy and caching behaviour.
- Review recent job runs and context metrics: `php app/cli/job_status.php [--job=importer] [--public] [--json]`
- Review the cross-feed latest snapshot: `php app/cli/show_latest.php [--refresh] [--feed=<id>] [--limit=20] [--json]`. The command can regenerate `public/status/latest.json`, print a formatted table, or emit the raw JSON payload for automation workflows.
- Review recent metrics trends: `php app/cli/metrics_history.php [--limit=10] [--refresh]`
- Import multiple feeds from OPML: `php app/cli/import_opml.php subscriptions.opml [--private|--public]`
- Export the current roster to OPML: `php app/cli/export_opml.php [optional-output-path] [--include-auth] [--include-headers] [--include-private]`
- Update or clear feed notes from the shell: `php app/cli/set_notes.php <feed-id> [--clear | --file=PATH|- | notes...]`
- Update or clear per-feed custom headers from the shell: `php app/cli/set_http_headers.php <feed-id> [--header="Name: Value" ... | --headers-file=path | --clear]`
- Update or clear HTTP credentials from the shell: `php app/cli/set_http_auth.php <feed-id> [username] [password|--clear|--password-file=path]`
- Lock or unlock a feed language from the shell: `php app/cli/set_language.php <feed-id> [language|--unlock]`
- Flip feed visibility without touching other metadata: `php app/cli/set_privacy.php <feed-id> <private|public>`
- Run environment diagnostics: `php app/cli/diagnostics.php [--json]`
- Validate SQLite/JSONL integrity: `php app/cli/check_integrity.php [--json] [--full] [--feed=<feed-id>]`
- Create, list, or prune filesystem snapshots: `php app/cli/backup.php [--help]` (automatically refreshes `public/status/backups.json` after each run)
- Extract or restore a snapshot: `php app/cli/restore_backup.php [backup-name] [--help]`
- Scripts exit with non-zero codes on validation errors, making them suitable for automation.

### Targeted job execution

- `php app/run_importer.php --feed=<id>` fetches only the specified feed. Repeat the flag to process multiple feeds or use `--url=<feed-url>` to reference by URL. Combine with `--limit=` to cap the batch size or `--force` to bypass the normal fetch interval and any active backoff window for urgent refreshes.
- `php app/run_publish.php --feed=<id>` regenerates outputs for select feeds. Add `--skip-combined` to avoid rewriting the aggregated feeds or `--skip-status` to leave `public/status/*` unchanged when performing partial rebuilds.
- `php app/run_verify.php --feed=<id>` checks links for one feed, while `--uid=<item-uid>` verifies specific entries. Include `--include-dead` to retry links already marked as dead or `--force` to ignore paused-feed suppression.
- All targeted modes support `--help` for on-demand usage documentation and emit helpful errors when a feed or URL does not match the registry.

### Email alerts

- Enable alerts by setting `alerts.enabled` to `true` and supplying valid `alerts.email_to` / `alerts.email_from` addresses. The sender defaults to the recipient if you omit it, but providing a dedicated mailbox improves deliverability.
- Alerts fire when the importer marks a feed status beginning with `error` (e.g., network failures) and optionally when feeds are paused if `alerts.notify_on_pause` is enabled.
- Recovery notices are sent when a previously failing feed returns to a healthy state (`ok`, `empty`, or `not_modified`). Duplicate alerts are throttled using `alerts.cooldown_minutes`.
- Alert history and cooldown bookkeeping are stored at `storage/tmp/alert_state.json` and surfaced in the admin dashboard as well as `public/status/health.json`.
- The diagnostics CLI highlights misconfigured addresses and reports when alerting is disabled, making it easy to validate deployments after editing `config.php` or `config.local.php`.

### Backups & snapshots

- Run `php app/cli/backup.php` before upgrades or bulk edits to create a filesystem snapshot in `storage/backups/`. Snapshots include configuration files, feed metadata/JSONL archives, and, when requested, log files or the latest public status outputs.
- Append `--label=before-upgrade`, `--include-logs`, or `--include-status` flags to capture additional context. The command prints file counts, disk usage, and stores a manifest (`manifest.json`) describing what was saved.
- Use `--list` to review existing snapshots and `--prune=N` (optionally combined with `--no-create`) to keep only the most recent backups when storage is limited. The maintenance cron automatically enforces `maintenance.backups_keep` using the same prune routine.
- After every create or prune operation, the CLI (and scheduled maintenance) refreshes `public/status/backups.json`, publishing a sanitized inventory with counts, timestamps, and inclusion flags for automation.
- The admin dashboard reads the same JSON summary to populate its Backups panel, so reloading `/rss/admin/` confirms new snapshots without shell access.
- Restore snapshots with `php app/cli/restore_backup.php <backup-name>` to extract into a safe directory (use `--target=` to pick the destination, `--with-config`, `--with-logs`, or `--with-status` to include optional directories, and `--skip-storage` when you only need configuration files). Add `--json` for machine-readable output.
- Include `--in-place --force` to push a snapshot directly into the live paths. Combine with `--with-config`, `--with-logs`, and `--with-status` for full replacements or omit them to focus on the feed archives. The command records restore metadata in `storage/index/backup_restore.json`, refreshes `public/status/backups.json`, and surfaces the activity in the admin dashboard.
- After in-place restores, run `php app/run_maintenance.php --rebuild-index` to ensure the SQLite index matches the restored JSONL data before resuming cron jobs.

### Maintenance job & dead link pruning

- `php app/run_maintenance.php` vacuums the SQLite database, rotates logs, and prunes items that have been marked dead for longer than `maintenance.prune_dead_after_days` (45 days by default).
- Pruning is performed in batches (`maintenance.prune_batch_size`, default 500) with atomic JSONL rewrites so large feeds remain responsive.
- Pass `--skip-prune` to `run_maintenance.php` when you want to vacuum or rebuild the index without touching stale entries.
- The maintenance job records the number of pruned items and affected feeds in both the cron log and `public/status/jobs.json`, making it easy to confirm cleanup activity from the admin dashboard.
- When `maintenance.backups_keep` is greater than zero, maintenance also trims old snapshots from `storage/backups/` using the same pruning routine as the CLI.

## Security Notes

- `storage/` and `logs/` directories are blocked via `.htaccess` to prevent web access.
- HTTP Basic Auth protects `/admin`, and `config.php` (plus optional overrides in `config.local.php`) enforces an IP allowlist supporting single IPs, CIDR blocks, and explicit IP ranges.
- All POST actions require CSRF tokens (session-based).
- SSRF protections block private and loopback IP ranges before any HTTP requests.
- Published files are written atomically (`*.tmp` then `rename`) to avoid partial writes.
- Public feeds include `X-Content-Type-Options: nosniff` via `.htaccess`.
- All cron entry points acquire exclusive filesystem locks to prevent overlapping runs.
- OPML exports are regenerated automatically after publisher/importer/maintenance runs and can be downloaded from `public/status/subscriptions.opml` for easy backups.
- Feed-level HTTP credentials and custom headers live only in `storage/feeds/*/feed.json` and never appear in public status files or OPML exports unless you explicitly run `export_opml.php --include-auth` and/or `--include-headers`.

## Recovery Procedures

### Rebuilding the SQLite index

If the SQLite database is lost or corrupted:

1. Stop cron jobs temporarily.
2. Delete `storage/index/database.sqlite` (if present) and re-run `php app/run_maintenance.php --rebuild-index`.
3. The maintenance script will replay all JSONL files to repopulate the database.
4. Restart cron jobs.

### Troubleshooting

- **Importer fails with SSL errors**: Ensure OpenSSL is enabled for PHP CLI. Update `config.php` or `config.local.php` to disable certificate verification only if absolutely necessary.
- **Permission denied**: Confirm `storage/`, `logs/`, and `public/` are writable by your user.
- **Feeds not updating**: Check `logs/import.log` for errors. Confirm the feed URL is accessible and not blocked by SSRF rules.
- **Feed stuck waiting to retry**: The dashboard and CLI mark feeds in backoff; queue an immediate fetch (`reschedule_feed.php` or `--force`) once the upstream source is healthy to clear the delay.
- **Email alerts not sending**: Ensure `alerts.enabled` is `true`, both `alerts.email_to` and `alerts.email_from` are valid addresses, and that your hosting environment permits PHP's `mail()` function. Review the admin dashboard's Recent Alert Activity table and web server mail logs for failures.
- **Duplicate URL skipped**: The importer logs this message when another feed already provided the same article. The message is informational; no action is required unless you expect distinct URLs.
- **SQLite/JSONL mismatch**: Run `php app/cli/check_integrity.php --full` to list feeds with missing archives or mismatched counts, then resolve any filesystem issues before rerunning maintenance with `--rebuild-index`.
- **Admin IP blocked**: Update the `admin_allowed_ips` array in `config.php` (or override it in `config.local.php`) with your current IP, CIDR block, or range and mirror the change in `admin/.htaccess`.
- **Static feeds missing**: Run `php app/run_publish.php` manually to generate them and review `logs/publish.log`.
- **Unknown job state**: Check `/rss/public/status/jobs.json` or the admin dashboard for the latest cron telemetry.
- **Metrics history empty**: Snapshots are captured when publisher, importer, verifier, or maintenance jobs refresh status files. Run `php app/run_publish.php` or `php app/cli/metrics_history.php --refresh` to generate a fresh entry after setup.
- **Permission or dependency issues**: Execute `php app/cli/diagnostics.php` to surface missing PHP extensions, unwritable directories, or absent `.htaccess` protections.
- **Low disk headroom warning**: The diagnostics check and admin snapshot surface disk status from `diagnostics.disk`. Prune old backups/logs, raise the thresholds, or expand the filesystem before imports fail due to space exhaustion.

## Licensing

This project is provided without warranty. Review the source code before deploying to production.
