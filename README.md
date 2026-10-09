# Freelog for Craft CMS 5

View, search, filter, and download server logs directly from the Craft CMS control panel.

## Features

- Browse all log files in your Craft `storage/logs` directory
- Search log contents by keyword
- Filter entries by log level (emergency, alert, critical, error, warning, notice, info, debug)
- Download log files
- Clear log files
- Auto-refresh / tail mode
- A scheduled **error digest** by email — daily or weekly, only what's new, redacted
- Native Craft CP interface

## Requirements

- Craft CMS 5.0.0 or later
- PHP 8.2 or later

## Installation

Install via Composer:

```bash
composer require jholt/craft-freelog
```

Then install the plugin from the Craft control panel under **Settings > Plugins**, or via CLI:

```bash
php craft plugin/install freelog
```

## Large log files

Logs are read as a stream and only the page being shown is held in memory, so a multi-hundred-megabyte log opens in a few megabytes of RAM. Each view still reads through the whole file to count its entries — about 1.5 seconds per 300 MB, and roughly twice that with a search. A single entry longer than 64 KB is cut short in the viewer; download the file to see all of it.

## Error digest

Freelog can email a digest of the errors written to your logs since the last one, so a failing page gets noticed without anybody opening the log viewer. Admins set it up under **Freelog → Settings** (or the gear on **Settings → Plugins**):

- **How often** — daily, or weekly on a chosen day, at or after a chosen hour in the system time zone.
- **Recipients** — email addresses, or an environment variable holding them (`$FREELOG_DIGEST_RECIPIENTS`).
- **Levels to report** — emergency, alert, critical and error by default; tick warning (or anything else) to add it.
- **Leave out 4xx HTTP errors** — on by default. Craft logs every not-found and forbidden request at error level, and on a public site they bury everything else.
- **Include stack traces** — off by default (see below).
- **Send even when there is nothing new** — off by default.

### What "new" means

Freelog remembers, per log file, the byte position the last digest read up to, and reads only what was written after it. Nothing is reported twice and nothing is skipped:

- Craft 5's dated logs (`web-2026-10-09.log`) are read from the top the day they appear.
- A log rotated by renaming (`web.log` → `web.log.1`) carries on from where it was; a log rotated by copy-and-truncate, or cleared from the viewer, starts again from the top, and the rest of its old content is read from the copy beside it. Gzipped rotations are never read — their content was reported while it was the live log.
- A line still being written when the digest runs is left for the next one.
- The first digest has nothing to start from, so it covers one period back: the last day, or the last week.

Repeats are grouped — a thousand "Undefined array key 17" from one bad template is one line with ×1000 — and the email lists the 25 most severe, most frequent kinds of error, with a link to each log filtered by level.

### What leaves the server

Logs hold request data, email addresses and occasionally a credential, and an email outlives the log it came from. So before anything goes in a digest:

- each error is quoted by its **first line only**, capped at 500 characters. Stack traces stay on the server unless **Include stack traces** is on, and then only the first 12 lines go, redacted like everything else;
- this install's **own secrets are removed by value** — the security key, the database password, and every environment variable whose name says it is a key, secret, password, token, salt or DSN;
- anything **shaped like a credential** is masked: `Bearer …`, `password=…`, `"api_key":"…"`, `[password] => …`, `user:pass@` in URLs, JSON web tokens. A word after `token:` with a space before it is only masked when it looks like a credential, so "Invalid CSRF token: missing" stays readable;
- **personal data** is masked: email addresses keep only their domain, IPv4 addresses lose their last octet, and card-number-shaped digit runs that pass a Luhn check are removed.

Only admins can choose the recipients or send a test.

### Sending it

Best is a cron job, every 15 minutes or so — it sends once per period, only once the hour has come:

```bash
*/15 * * * * php /path/to/craft freelog/digest/send
```

Without one, leave **Check from web requests too** on: the end of a web request looks at the schedule at most every five minutes and queues the digest when it's due. Nothing hangs off garbage collection.

```bash
php craft freelog/digest/send           # send if due (exit 0 unless it failed or has no recipients)
php craft freelog/digest/send --force   # send now, whatever the schedule
php craft freelog/digest/status         # last run, last send, next due, logs tracked
```

**Send a test digest now** on the settings screen shows what the next digest would say, marked as a test. It doesn't count as the period's digest and doesn't move any read position, so the real one still reports the same errors.

A failed send gives the period back and keeps the old read positions, so the next run tries again with the same entries. Developers can change the recipients, subject or contents — or cancel a send — from `justinholtweb\freelog\services\Digest::EVENT_BEFORE_SEND` (a `DigestEvent`).

## Permissions

Freelog adds two permissions under user/group settings:

- **View logs** — open, search, download and tail log files. Logs hold request data, email addresses and stack traces, so treat this as close to admin-level access.
- **Clear logs** (nested under View logs) — empty a log file.

Admins have both. Before 5.0.5 a single **Access Freelog** permission did both jobs; it is now View logs, so grant Clear logs to anyone who should still be able to clear.

## Usage

Navigate to **Freelog** in the control panel sidebar. Select a log file to view its contents. Use the search bar and level filter to find specific entries. Click **Download** to save a log file locally, or **Clear** to empty it.
