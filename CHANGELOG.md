# Changelog


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
