# Upgrading Freelog from Craft 5 to Craft 6

Freelog 6 is a native Craft 6 plugin. It runs on Laravel and has no dependency on the Yii 2
adapter. The log viewer, the permissions, the digest's settings and its read positions carry over.
What changes is the format of the logs Craft writes, and the PHP around Freelog: console commands,
scheduling, mail and events.

> **Beta.** Freelog 6 is in beta, like Craft 6 itself. Test the upgrade on a copy of your site
> before relying on it in production. Freelog 5 remains the stable release for Craft 5.

| | Freelog 5.x | Freelog 6.x |
| --- | --- | --- |
| Status | Stable | Beta |
| Craft CMS | 5.0+ | 6.0+ |
| PHP | 8.2+ | 8.5+ |
| Log format written by Craft | `2026-10-09 12:00:00 [web.ERROR] [category] …` | `[2026-11-19 10:06:21] local.ERROR: …` |
| Commands | `php craft freelog/digest/send` | `php artisan freelog:digest:send` (old names still work) |
| Digest schedule | your cron job, or web requests | Laravel's scheduler, or web requests |

## Before you start

1. **Update to Freelog 5.1.0** while the site is still on Craft 5, and run its migrations. It adds
   the `freelog_digests` table, and Freelog 6 won't install over anything older.

   ```bash
   composer require justinholtweb/craft-freelog:^5.1.0
   php craft up
   ```

2. **Find your extensions.** If a plugin or module listens for `Digest::EVENT_BEFORE_SEND`, it
   needs the change under [Custom code](#custom-code).

## Upgrade

Follow Craft's [Craft 6 upgrade guide](https://craftcms.com/docs/6.x/upgrade.html), requiring
Freelog 6 in the same step:

```bash
composer require "craftcms/cms:^6.0@beta" "justinholtweb/craft-freelog:^6.0@beta" -W
php artisan craft:up
```

Drop the `@beta` flags once Craft 6 and Freelog 6 have stable releases.

## What carries over

- **Settings.** They stay in project config under the same keys. If you had `config/freelog.php`,
  move it to `config/craft/freelog.php`.
- **Permissions.** *View logs* (`freelog:access`) and *Clear logs* (`freelog:clear`) keep their
  keys, so existing grants still work.
- **The digest's state.** The `freelog_digests` table is unchanged: the last period sent, and the
  read position of each log. Nothing already reported is reported again.
- **Your old logs.** Craft 5's `web-*.log`, `console-*.log` and plugin logs in `storage/logs` stay
  listed and readable, and the digest reads anything still being added to them.
- **URLs.** `admin/freelog`, `admin/freelog/view?file=…` and `admin/freelog/download?file=…` are
  the same. `admin/freelog/settings`, which older digest emails link to, redirects to the new
  settings screen.

## What changes

### The log format

Craft 6 logs through Laravel's Monolog. By default that's `storage/logs/laravel-YYYY-MM-DD.log`, a
new file each day, with files older than `LOG_DAILY_DAYS` deleted; or `laravel.log` with the
`single` driver. An entry starts `[date] channel.LEVEL: message`, followed by JSON context; an
exception's stack trace sits inside that JSON, over several lines.

Freelog reads both formats and works out which one each file uses from its first entry. In the
viewer, Laravel's channel — the environment name, such as `local` or `production` — appears as the
category. The digest quotes a Laravel error by its message, without the JSON.

### 404s and the 4xx setting

Craft 5 logged every not-found, forbidden and bad request at error level, which is why *Leave out
4xx HTTP errors* exists. Craft 6 doesn't log them at all by default: Laravel leaves HTTP exceptions
unreported. The setting still filters 4xx entries from Craft 5 logs kept after the upgrade, and any
4xx your own exception handler chooses to report.

### Console commands

| Freelog 5 | Freelog 6 |
| --- | --- |
| `php craft freelog/digest/send [--force]` | `php artisan freelog:digest:send [--force]` |
| `php craft freelog/digest/status` | `php artisan freelog:digest:status` |

The old names still work as aliases (`php craft freelog/digest/send` too), with the same exit
codes: 0 for anything that isn't a fault, 78 when the digest has no valid recipients, 1 when the
send failed.

### Scheduling

Freelog registers `freelog:digest:send` on Laravel's scheduler, every 15 minutes. If your server
runs `php artisan schedule:run` every minute, you can remove the `freelog/digest/send` cron job you
had for Freelog 5 (leaving it does no harm: the digest sends once per period whatever calls it).

*Check from web requests too* still works for sites without a scheduler. It now looks at the
schedule after the response has been sent, and queues the digest when it's due.

### Mail

The digest is sent with Laravel Mail through Craft's email settings (**Settings → Email**), the
same from address and mailer as Craft's own system messages. Each recipient still gets a separate
message.

### Redaction

The secrets the digest removes by value are now Craft 6's: the application key (`APP_KEY` and any
`APP_PREVIOUS_KEYS`), every database connection's password, Craft's `securityKey` if it's still
set, and every environment variable whose name says it's a secret, as before. Everything else —
credential-shaped values, email addresses, IP addresses, card numbers — works as in Freelog 5.

### The control panel

The log screens look much the same. The file list in the log view's sidebar is now a file switcher
in the toolbar. Settings moved to the standard **Settings → Plugins → Freelog** screen, and the
digest's status and *Send a test digest now* moved to a new **Freelog → Error digest** screen.

## Custom code

### Namespaces

`justinholtweb\freelog\…` is now `JustinHoltWeb\Freelog\…`, with the sub-namespaces capitalised:
`services` → `Services`, `helpers` → `Helpers`, `models` → `Models`.

### The digest event

```php
// Freelog 5
Event::on(Digest::class, Digest::EVENT_BEFORE_SEND, function (DigestEvent $event) { … });

// Freelog 6
use Illuminate\Support\Facades\Event;
use JustinHoltWeb\Freelog\Events\SendingDigest;

Event::listen(SendingDigest::class, function (SendingDigest $event) { … });
```

The properties are the same: `recipients`, `subject`, `variables`, `isTest`, and `isValid` to
cancel.

### Services

`Plugin::getInstance()->getLogService()` and `->digest` are now container services: use
`app(\JustinHoltWeb\Freelog\Services\LogService::class)` and
`app(\JustinHoltWeb\Freelog\Services\Digest::class)`, or inject them.

## Rolling back

Restore your Craft 5 site and database from the backup you took before upgrading, and require
`justinholtweb/craft-freelog:^5.1.0`. The digest's read positions come back with the database.
