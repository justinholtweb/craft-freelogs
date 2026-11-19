# Freelog for Craft CMS 6

View, search, filter, and download your server logs from the Craft control panel — and get a
redacted email digest of new errors.

> **Beta.** Freelog 6 is in beta, like Craft 6 itself. Test it on a copy of your site before
> relying on it in production.
>
> **Using Craft 5?** Install Freelog 5.x instead: `composer require justinholtweb/craft-freelog:^5.1`.
> Upgrading a site from Craft 5? See [UPGRADING.md](UPGRADING.md).

## Features

- Browse every log file in `storage/logs`, including gzipped rotations
- Read Craft 6's Laravel logs and the Craft 5 logs an upgraded site kept, side by side
- Search log contents by keyword
- Filter entries by level (emergency, alert, critical, error, warning, notice, info, debug)
- Download a log file
- Clear a log file (its own permission)
- Auto-refresh / tail mode
- A scheduled **error digest** by email — daily or weekly, only what's new, redacted

## Requirements

- Craft CMS 6.0.0-beta.1 or later
- PHP 8.5 or later

## Installation

```bash
composer require "justinholtweb/craft-freelog:^6.0@beta"
php artisan craft:plugin:install freelog
```

Or install it from **Settings → Plugins** in the control panel.

## Log formats

Craft 6 logs through Laravel, so a log entry looks like this, with any stack trace on the lines
after it:

```
[2026-11-19 10:06:21] local.ERROR: Unclosed '[' on line 5 {"exception":"[object] (ParseError(code: 0): …
[stacktrace]
#0 /var/www/html/vendor/laravel/framework/src/…
"}
```

Freelog reads the date, the level, and the channel (`local`, `production` — the environment name
Laravel writes) as the entry's category. The JSON context and the trace stay with the entry; click
a row to see all of it.

Logs written by Craft 5 (`2026-10-09 12:00:00 [web.ERROR] [category] message`) and Craft 3/4 still
parse, so the files an upgraded site kept in `storage/logs` stay readable. Freelog works out each
file's format from its first entry, so a line inside a stack trace or a query dump that happens to
start with a date isn't mistaken for a new entry.

Laravel's `daily` log driver (the default stack in a new Craft 6 project) writes a new
`laravel-YYYY-MM-DD.log` each day and deletes the old ones after `LOG_DAILY_DAYS`; the `single`
driver writes `laravel.log`. Any other channel file in `storage/logs` with a `.log` or `.txt`
extension is listed too.

## Large log files

Logs are read as a stream and only the page being shown is held in memory, so a multi-hundred-
megabyte log opens in a few megabytes of RAM. Each view still reads through the whole file to count
its entries. A single entry longer than 64 KB is cut short in the viewer; download the file to see
all of it.

## Error digest

Freelog can email a digest of the errors written to your logs since the last one, so a failing page
gets noticed without anybody opening the log viewer. Admins set it up under **Settings → Plugins →
Freelog** (also **Freelog → Settings** in the sidebar):

- **How often** — daily, or weekly on a chosen day, at or after a chosen hour in the system time zone.
- **Recipients** — email addresses, or an environment variable holding them (`$FREELOG_DIGEST_RECIPIENTS`).
- **Levels to report** — emergency, alert, critical and error by default; tick warning (or anything
  else) to add it.
- **Leave out 4xx HTTP errors** — on by default. Craft 5 logged every not-found and forbidden
  request at error level. Craft 6 doesn't log them at all by default (Laravel leaves HTTP exceptions
  unreported), so this now applies to Craft 5 logs still in `storage/logs`, and to any 4xx your own
  exception handler chooses to report.
- **Include stack traces** — off by default (see below).
- **Send even when there is nothing new** — off by default.

**Freelog → Error digest** shows when the digest last ran and next goes, and has **Send a test
digest now**.

### What "new" means

Freelog remembers, per log file, the byte position the last digest read up to, and reads only what
was written after it. Nothing is reported twice and nothing is skipped:

- Laravel's daily logs (`laravel-2026-11-19.log`) are read from the top the day they appear, and
  drop out when Laravel deletes them.
- A log rotated by renaming (`web.log` → `web.log.1`) carries on from where it was; a log rotated by
  copy-and-truncate, or cleared from the viewer, starts again from the top, and the rest of its old
  content is read from the copy beside it. Gzipped rotations are never read — their content was
  reported while it was the live log.
- A line still being written when the digest runs is left for the next one.
- The first digest has nothing to start from, so it covers one period back: the last day, or the
  last week.

Repeats are grouped — a thousand "Undefined array key 17" from one bad template is one line with
×1000 — and the email lists the 25 most severe, most frequent kinds of error, with a link to each
log filtered by level.

### What leaves the server

Logs hold request data, email addresses and occasionally a credential, and an email outlives the
log it came from. So before anything goes in a digest:

- each error is quoted by its **first line only**, without Laravel's JSON context, capped at 500
  characters. Stack traces stay on the server unless **Include stack traces** is on, and then only
  the first 12 frames go, redacted like everything else;
- this install's **own secrets are removed by value** — the application key (`APP_KEY` and any
  previous keys), every database connection's password, and every environment variable whose name
  says it is a key, secret, password, token, salt or DSN;
- anything **shaped like a credential** is masked: `Bearer …`, `password=…`, `"api_key":"…"`,
  `[password] => …`, `user:pass@` in URLs, JSON web tokens. A word after `token:` with a space before
  it is only masked when it looks like a credential, so "Invalid CSRF token: missing" stays readable;
- **personal data** is masked: email addresses keep only their domain, IPv4 addresses lose their
  last octet, and card-number-shaped digit runs that pass a Luhn check are removed.

Only admins can choose the recipients or send a test.

### Sending it

Freelog registers the digest on Laravel's scheduler, every 15 minutes, so a site that runs the
scheduler needs nothing else:

```bash
* * * * * cd /path/to/project && php artisan schedule:run >> /dev/null 2>&1
```

It sends once per period, and only once the hour has come. Without the scheduler, leave **Check
from web requests too** on: after a web request, the schedule is looked at at most every five
minutes, and the digest is queued when it's due (Craft runs the queue from web requests by default).

```bash
php artisan freelog:digest:send           # send if due (exit 0 unless it failed or has no recipients)
php artisan freelog:digest:send --force   # send now, whatever the schedule
php artisan freelog:digest:status         # last run, last send, next due, logs tracked
```

The digest is sent through Craft's email settings (**Settings → Email**), one message per recipient.

**Send a test digest now** shows what the next digest would say, marked as a test. It doesn't count
as the period's digest and doesn't move any read position, so the real one still reports the same
errors.

A failed send gives the period back and keeps the old read positions, so the next run tries again
with the same entries. Developers can change the recipients, subject or contents — or cancel a
send — by listening for `JustinHoltWeb\Freelog\Events\SendingDigest`:

```php
use Illuminate\Support\Facades\Event;
use JustinHoltWeb\Freelog\Events\SendingDigest;

Event::listen(SendingDigest::class, function (SendingDigest $event) {
    $event->recipients[] = 'ops@example.com';
    // $event->isValid = false; to cancel
});
```

## Permissions

Freelog adds two permissions:

- **View logs** — open, search, download and tail log files. Logs hold request data, email
  addresses and stack traces, so treat this as close to admin-level access.
- **Clear logs** (nested under View logs) — empty a log file.

Users also need **Access Freelog** (Craft's plugin access permission) to see it in the sidebar.
Admins have everything. The error digest and its settings are admin-only.

Every file request — view, download, tail, clear — only reaches a file with a log extension that
really lives in `storage/logs`. Paths, `../`, and symlinks out of the folder get a 404.

## Settings in a config file

Settings can be overridden per environment in `config/craft/freelog.php`, using the setting names
(`digestEnabled`, `digestRecipients`, `digestLevels`, …).
