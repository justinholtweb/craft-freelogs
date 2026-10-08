# Changelog

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
