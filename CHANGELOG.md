# Changelog

## 6.0.0-beta.1 - Unreleased

Freelog for Craft CMS 6, rebuilt as a native Craft 6 plugin. It runs on Laravel and has no
dependency on the Yii 2 adapter. Craft 6 is in beta, and so is this release. Update to Freelog
5.1.0 before upgrading Craft, and see [the upgrade guide](https://github.com/justinholtweb/craft-freelogs/blob/6.0.0/UPGRADING.md).

### Added

- Reads Laravel's log format, which is what Craft 6 writes (`[2026-11-19 10:06:21] local.ERROR: message {"exception":…}`), with the JSON context and the stack trace kept with their entry. The channel (`local`, `production`) shows as the category. Craft 5 and Craft 3/4 logs kept in `storage/logs` still parse.
- Each file's format is recognised from its first entry, so a line inside a trace or a dumped query that starts with a date is no longer split off as an entry of its own.
- The digest is registered on Laravel's scheduler every 15 minutes; a site running `php artisan schedule:run` needs no cron entry of Freelog's own.
- **Freelog → Error digest**: when the digest last ran and next goes, and *Send a test digest now*.
- A *Clear this log* button on the log view, and a file switcher in its toolbar.

### Changed

- Requires Craft CMS 6 and PHP 8.5.
- Console commands are Artisan commands: `php artisan freelog:digest:send` and `freelog:digest:status`. The Freelog 5 names (`freelog/digest/send`, `freelog/digest/status`) still work as aliases.
- The digest's "own secrets" are Craft 6's: the application key (`APP_KEY`, and `APP_PREVIOUS_KEYS`), every database connection's password, Craft's `securityKey` if set, and secret-named environment variables as before.
- A Laravel error is quoted in the digest by its message, without the `{"exception":…}` context; with traces on, the frames go without the `[stacktrace]` markers.
- *Leave out 4xx HTTP errors* also recognises Symfony's 4xx exceptions in Laravel entries. Craft 6 doesn't log 404s or other HTTP exceptions by default, so the setting now mostly applies to Craft 5 logs kept after an upgrade.
- Digest emails are sent with Laravel Mail through Craft's email settings.
- Settings are on the standard **Settings → Plugins → Freelog** screen. `freelog/settings` redirects there.
- The PHP namespace is `JustinHoltWeb\Freelog` (was `justinholtweb\freelog`).
- `Digest::EVENT_BEFORE_SEND` and `DigestEvent` are replaced by a Laravel event, `JustinHoltWeb\Freelog\Events\SendingDigest`, with the same `recipients`, `subject`, `variables`, `isTest` and `isValid`.
- The web fallback checks the schedule after the response has been sent (Laravel's `terminating`) rather than at the end of the request.

### Fixed

- The `PWD` environment variable (the shell's working directory) is no longer treated as a secret, so the project path in a stack trace isn't masked.

### Internal

- Tests moved to Pest, with parser tests on real Laravel and Craft 5 log excerpts and tests for the digest's read positions across Laravel's daily rotation. Pint and Larastan (level 6) replace ECS and PHPStan's Craft ruleset. The Craft 5 integration scripts were dropped; the same checks were run against the Craft 6 test harness.

## 5.1.0 - 2026-10-09
### Added

- **Error digest by email.** Once a day or once a week, Freelog emails the errors written to your logs since the last digest, grouped by kind with a count and a link to each log. Set it up under **Freelog → Settings**: schedule, recipients (or an environment variable), levels, and whether to leave out 4xx HTTP errors (on by default). Send it from cron with `php craft freelog/digest/send`, or let the end of a web request queue it when it's due; `freelog/digest/status` shows the schedule, and **Send a test digest now** shows what the next one would say without using it up.
- The digest reads each log from where the last one stopped — a byte position per file, kept through rename and copy-and-truncate rotation, a cleared log, and a line still being written — so nothing is reported twice and nothing is missed. The first digest covers one period back rather than the whole history.
- Everything the digest quotes is redacted first: this install's own secrets by value, anything shaped like a credential, email addresses (domain kept), the last octet of IP addresses and card-number-shaped digits. Each error is quoted by its first line; stack traces stay out unless an admin turns them on.
- A **Settings** screen (admins only) and a Logs/Settings subnav for admins.

### Internal

- New `freelog_digests` table (schema 1.1.0) for the digest's marker and read positions.
- Integration suites `tests/integration/digest.php` (schedule, positions, rotation, redaction, failures, fallback, console) and `digest-http.php` (the test-send endpoint and settings screen: admin-only, POST + CSRF, no nested forms), plus unit tests for the redactor and the digest's log reader.

## 5.0.5 - 2026-10-08

> {warning} Clearing a log now needs its own **Clear logs** permission, nested under **View logs** (formerly **Access Freelog**). Users and groups that had Access Freelog keep viewing and downloading, but can no longer clear logs until you grant Clear logs. Admins are unaffected.

### Security

- Reading and clearing logs are separate permissions. Logs hold request parameters, email addresses and stack traces, so the view permission now carries that warning — and clearing a log erases the record of what happened, which shouldn't come bundled with reading it.
- View, download and clear only reach files with a log extension. The listing always filtered on it, but the actions didn't, so any other file in `storage/logs` could be opened, downloaded or truncated by name.
- The Cleared notice names the file it resolved, not the text that was posted.

### Fixed

- **Large log files open.** A log was read whole and parsed into memory, which took about 5.5× the file's size — a 300 MB log needed 1.7 GB, and the viewer died with a memory error well before that on most servers. Logs are now read as a stream, a line at a time (gzip included), and only the page being shown is kept: the same 300 MB log opens in about 4 MB of memory, and faster (1.4s rather than 3.4s for the first page). Rotated `.gz` logs tail the same way, without being decompressed into memory.
- A single entry's message is capped at 64 KB, with a note saying so — one runaway dump or stack trace can no longer take the memory back. Download the file for the whole thing; search looks at the part that's shown.
- **Live tail mode works.** Its panel started hidden and nothing showed it, so Start auto-refresh polled into an invisible box — and on most real logs the request failed anyway, because cutting the last 8 KB at a byte offset split a multibyte character and the JSON response refused it. The tail now starts at a whole line and is always valid UTF-8.

### Changed

- The log view uses Craft's own controls and table: the search box and level filter are Craft's form inputs, entries sit in a standard data table, and there are no inline styles or inline handlers left.
- Every string in the control panel is translatable (`freelog` category).

### Internal

- Added an integration suite (`tests/integration/security.php`) covering the permissions, file reach and CP conventions, plus unit tests for the extension allowlist, symlinks out of the logs folder and the tail.
- Added ECS (craftcms/ecs).


## 5.0.4 - 2026-07-19

### Fixed

- `getTail()` now handles `filesize()` returning `false` on unreadable files instead of attempting an invalid read.
- Log viewer pagination clamps the `page` parameter to a minimum of 1, so a negative or zero page no longer produces a negative offset.
- Yii-format (`[LEVEL][category]`) log levels are now lowercased to match every other supported format for consistent display and filtering.
- Downloaded log files use a sanitized (`basename`) filename in the download header.

### Internal

- Added a PHPUnit test suite covering log parsing, filtering, tailing, clearing, and path-traversal protection.
- Added PHPStan static analysis (level 8, clean) with the official CraftCMS ruleset.

## 5.0.3 - 2026-04-15

### Fixed

- Log files with unrecognized line formats no longer appear empty in the viewer. Added parser support for plain-level (`2026-04-14 14:15:37 [ERROR] message`, e.g. Formie), bracketed-date (`[2026-04-15 07:33:42] message`, e.g. Blitz), and bare-date (`2026-04-10 14:30:42 message`) formats.

## 5.0.2 - 2026-03-08

### Added

- LogService — file listing now picks up .log.gz / .txt.gz, exposes a compressed flag, and reads/tails them via gzdecode. clearLog writes a valid empty gzip stream for .gz files so they stay readable. 
- New helpers: isCompressed(), _isSupportedLogFile(), _readFileContent().
- Templates — index.twig and view.twig show a small gz badge next to compressed files.

## 5.0.1 - 2026-03-08

### Added

- Detect and display gzip-compressed log files (`.log.gz`, `.txt.gz`) alongside plain logs

## 5.0.0 - 2026-03-08

### Added
- Initial release
- Dashboard page for viewing server logs
- Search and filter log entries by level and keyword
- Log file browser with file sizes and modification dates
- Download individual log files
- Tail/live-refresh log viewing
- Clear log files from the control panel
- Permission-based access control
