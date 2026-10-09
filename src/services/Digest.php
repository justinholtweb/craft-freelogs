<?php

namespace justinholtweb\freelog\services;

use Craft;
use craft\base\Component;
use craft\db\Migration;
use craft\db\Query;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\UrlHelper;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use justinholtweb\freelog\events\DigestEvent;
use justinholtweb\freelog\helpers\Mailer;
use justinholtweb\freelog\helpers\Redactor;
use justinholtweb\freelog\jobs\SendDigest;
use justinholtweb\freelog\models\Settings;
use justinholtweb\freelog\Plugin;
use Throwable;
use yii\db\IntegrityException;

/**
 * The scheduled error digest: once a day or once a week, email the error-level log entries
 * written since the last one.
 *
 * Ported from craft-yarn's findings digest, the family's reference for scheduled email reports.
 * Everything above "What this plugin reports" is that pattern — schedule, the durable marker, the
 * claim that makes a send happen once, the fallback trigger. Below it is Freelog's: where Yarn
 * diffs a list of finding keys, Freelog keeps a **byte position per log file** and reads only
 * what was written after it.
 *
 * Three ways in, one way through:
 *
 * - `php craft freelog/digest/send` from cron — the recommended route, every 15–60 minutes;
 * - the end of a web request, at most every five minutes, which queues {@see SendDigest};
 * - "Send a test digest now" in the control panel, which never moves the marker or a position.
 *
 * Log lines carry request data, email addresses and, now and then, a credential. Everything the
 * email quotes passes through {@see Redactor}, messages are cut to their first line, and stack
 * traces stay out unless an admin turns them on.
 */
class Digest extends Component
{
    /** @see DigestEvent Cancel it, or change the recipients, subject or variables. */
    public const EVENT_BEFORE_SEND = 'beforeSend';

    public const TABLE = '{{%freelog_digests}}';

    /** One row per digest the plugin sends. Freelog has one. */
    public const HANDLE = 'errors';

    public const RESULT_SENT = 'sent';
    public const RESULT_DISABLED = 'disabled';
    public const RESULT_NO_RECIPIENTS = 'noRecipients';
    public const RESULT_NOT_DUE = 'notDue';
    public const RESULT_ALREADY_SENT = 'alreadySent';
    public const RESULT_NOTHING_NEW = 'nothingNew';
    public const RESULT_CANCELLED = 'cancelled';
    public const RESULT_FAILED = 'failed';

    /** Most groups of entries listed in one email. The rest are a count and a link. */
    public const MAX_ITEMS = 25;

    /** Distinct messages remembered per run. Past this, entries are counted but not grouped. */
    public const MAX_GROUPS = 500;

    /** Longest message quoted, in characters, after redaction. */
    public const MAX_MESSAGE = 500;

    /** Stack trace lines quoted when traces are on, and their cap in characters. */
    public const MAX_TRACE_LINES = 12;
    public const MAX_TRACE = 2000;

    /** How much of a log's start is fingerprinted to notice it was rewritten. */
    private const HEAD_BYTES = 256;

    /** How often the web fallback looks at the schedule at all. */
    public const WEB_CHECK_SECONDS = 300;

    private const WEB_CHECK_KEY = 'freelog:digest:checked';
    private const QUEUED_KEY = 'freelog:digest:queued:';

    // ------------------------------------------------------------------------------- schedule

    /** Now, in the system time zone — the one the digest hour is set in. */
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone(Craft::$app->getTimeZone()));
    }

    /**
     * The period a moment falls in: `2026-10-09` for a daily digest, `2026-W41` (ISO week) for a
     * weekly one. One send per key, ever.
     */
    public function periodKey(DateTimeInterface $now): string
    {
        $now = $this->inSystemTime($now);

        return $this->settings()->digestFrequency === Settings::DIGEST_DAILY
            ? $now->format('Y-m-d')
            : $now->format('o-\WW');
    }

    /** When the digest for the period containing `$now` becomes due. */
    public function dueAt(DateTimeInterface $now): DateTimeImmutable
    {
        $settings = $this->settings();
        $now = $this->inSystemTime($now);

        if ($settings->digestFrequency === Settings::DIGEST_WEEKLY) {
            $now = $now->setISODate((int)$now->format('o'), (int)$now->format('W'), $settings->digestWeekday);
        }

        return $now->setTime($settings->digestHour, 0);
    }

    /** Whether a scheduled run at `$now` would send (or at least try to). */
    public function isDue(?DateTimeInterface $now = null): bool
    {
        $settings = $this->settings();
        $now = $this->inSystemTime($now ?? $this->now());

        return $settings->digestEnabled
            && $settings->recipientList() !== []
            && $now >= $this->dueAt($now)
            && $this->state()['period'] !== $this->periodKey($now);
    }

    /**
     * When the next scheduled digest will go — for the settings screen. A digest that is due now
     * and has not gone yet answers with now.
     */
    public function nextDueAt(?DateTimeInterface $now = null): ?DateTimeImmutable
    {
        $settings = $this->settings();

        if (!$settings->digestEnabled) {
            return null;
        }

        $now = $this->inSystemTime($now ?? $this->now());

        if ($this->state()['period'] !== $this->periodKey($now)) {
            $due = $this->dueAt($now);

            return $due > $now ? $due : $now;
        }

        $next = $now->modify($settings->digestFrequency === Settings::DIGEST_DAILY ? '+1 day' : '+1 week');

        return $this->dueAt($next);
    }

    // --------------------------------------------------------------------------- the triggers

    /**
     * The scheduled send. Idempotent: call it as often as you like, it sends once per period.
     *
     * @param bool $force Ignore the schedule and the "already sent" marker — `--force` on the
     *                    console command. Still records the send and moves the read positions,
     *                    so the next scheduled digest reports what is new since *this*.
     * @return string One of the `RESULT_*` constants.
     */
    public function run(?DateTimeInterface $now = null, bool $force = false): string
    {
        $settings = $this->settings();
        $now = $this->inSystemTime($now ?? $this->now());

        if (!$force && !$settings->digestEnabled) {
            return self::RESULT_DISABLED;
        }

        $recipients = $settings->recipientList();

        if ($recipients === []) {
            return self::RESULT_NO_RECIPIENTS;
        }

        if (!$force && $now < $this->dueAt($now)) {
            return self::RESULT_NOT_DUE;
        }

        $period = $this->periodKey($now);
        $state = $this->state();

        if (!$force && ($state['period'] === $period || !$this->claim($period, $state['period']))) {
            return self::RESULT_ALREADY_SENT;
        }

        try {
            $scan = $this->collect($state['offsets'], $this->firstRunSince($state, $now));
        } catch (Throwable $e) {
            $this->release($period, $state['period']);
            Craft::warning('Could not read the logs for the error digest: ' . $e->getMessage(), Plugin::LOG_CATEGORY);

            return $this->record(null, null, self::RESULT_FAILED);
        }

        if ($scan['total'] === 0 && !$settings->digestSendWhenEmpty) {
            return $this->record($period, $scan['offsets'], self::RESULT_NOTHING_NEW);
        }

        $delivered = $this->deliver($recipients, $scan, false);

        if ($delivered === null) {
            $this->release($period, $state['period']);

            return $this->record(null, null, self::RESULT_CANCELLED);
        }

        if ($delivered === 0) {
            // Give the period back and keep the old read positions, so the next run tries again
            // with the same entries rather than the day's errors going unreported because the
            // mail server was down for five minutes.
            $this->release($period, $state['period']);

            return $this->record(null, null, self::RESULT_FAILED);
        }

        return $this->record($period, $scan['offsets'], self::RESULT_SENT, $scan['total']);
    }

    /**
     * "Send a test digest now". Sends what the next digest would say, marked as a test, and
     * leaves the marker and every read position alone — a test must never stop the real one
     * reporting the same entries.
     *
     * @param string[] $recipients
     * @return int How many recipients it was delivered to.
     */
    public function sendTest(array $recipients, ?DateTimeInterface $now = null): int
    {
        $state = $this->state();
        $scan = $this->collect($state['offsets'], $this->firstRunSince($state, $this->inSystemTime($now ?? $this->now())));

        return (int)$this->deliver($recipients, $scan, true);
    }

    /**
     * The fallback for sites with no cron job: called at the end of web requests, looks at the
     * schedule at most every {@see WEB_CHECK_SECONDS}, and queues {@see SendDigest} when due.
     */
    public function queueIfDue(?DateTimeInterface $now = null): bool
    {
        $settings = $this->settings();

        if (!$settings->digestEnabled || !$settings->digestWebTrigger) {
            return false;
        }

        $cache = Craft::$app->getCache();

        if ($cache === null) {
            return false;
        }

        // `add()` only writes when the key is absent, so of several requests landing together
        // one goes on to look at the schedule.
        if (!$cache->add(self::WEB_CHECK_KEY, 1, self::WEB_CHECK_SECONDS)) {
            return false;
        }

        $now = $this->inSystemTime($now ?? $this->now());

        if (!$this->isDue($now)) {
            return false;
        }

        if (!$cache->add(self::QUEUED_KEY . $this->periodKey($now), 1, 3600)) {
            return false;
        }

        Craft::$app->getQueue()->push(new SendDigest());

        return true;
    }

    // ------------------------------------------------------------------------ durable marker

    /**
     * The marker: which period was last claimed, where each log was read up to, and how the last
     * run went. `offsets` is null until the first digest has been recorded.
     *
     * @return array{period: string|null, offsets: array<string, array{file: string, offset: int, head?: string|null}>|null, lastRunAt: \DateTime|null, lastSentAt: \DateTime|null, lastResult: string|null, lastCount: int}
     */
    public function state(): array
    {
        $row = $this->row();

        if ($row === null) {
            try {
                Db::insert(self::TABLE, ['handle' => self::HANDLE, 'lastCount' => 0]);
            } catch (IntegrityException) {
                // Somebody else made it between our read and our write. Theirs will do.
            }

            $row = $this->row() ?? [];
        }

        $offsets = null;
        $decoded = isset($row['offsets']) ? json_decode((string)$row['offsets'], true) : null;

        if (is_array($decoded)) {
            $offsets = [];

            foreach ($decoded as $key => $position) {
                if (is_string($key) && is_array($position) && isset($position['file'], $position['offset'])) {
                    $offsets[$key] = [
                        'file' => (string)$position['file'],
                        'offset' => max(0, (int)$position['offset']),
                        'head' => isset($position['head']) ? (string)$position['head'] : null,
                    ];
                }
            }
        }

        return [
            'period' => isset($row['period']) && $row['period'] !== '' ? (string)$row['period'] : null,
            'offsets' => $offsets,
            'lastRunAt' => DateTimeHelper::toDateTime($row['lastRunAt'] ?? null) ?: null,
            'lastSentAt' => DateTimeHelper::toDateTime($row['lastSentAt'] ?? null) ?: null,
            'lastResult' => $row['lastResult'] ?? null,
            'lastCount' => (int)($row['lastCount'] ?? 0),
        ];
    }

    /**
     * Moves the marker onto `$period`, but only if it still says `$previous`. Of two runs racing
     * for the same period, the database lets exactly one of them through.
     */
    private function claim(string $period, ?string $previous): bool
    {
        return Db::update(
            self::TABLE,
            ['period' => $period],
            ['handle' => self::HANDLE, 'period' => $previous],
        ) === 1;
    }

    /** Hands a claimed period back after a failure, so the next run tries again. */
    private function release(string $period, ?string $previous): void
    {
        Db::update(self::TABLE, ['period' => $previous], ['handle' => self::HANDLE, 'period' => $period]);
    }

    /**
     * Writes down how a run went. `$period` and `$offsets` are null when nothing should change but
     * the result — a failure leaves the period and the read positions as they were.
     *
     * @param array<string, array{file: string, offset: int, head?: string|null}>|null $offsets
     */
    private function record(?string $period, ?array $offsets, string $result, ?int $count = null): string
    {
        $now = new DateTimeImmutable();
        $columns = [
            'lastRunAt' => Db::prepareDateForDb($now),
            'lastResult' => $result,
        ];

        if ($period !== null) {
            $columns['period'] = $period;
        }

        if ($offsets !== null) {
            // An object even when empty, so "read nothing yet" and "never ran" stay different.
            $columns['offsets'] = json_encode((object)$offsets);
        }

        if ($result === self::RESULT_SENT) {
            $columns['lastSentAt'] = Db::prepareDateForDb($now);
            $columns['lastCount'] = (int)$count;
        }

        Db::update(self::TABLE, $columns, ['handle' => self::HANDLE]);

        if ($result === self::RESULT_FAILED) {
            Craft::warning('The error digest was not sent; the next run will try again.', Plugin::LOG_CATEGORY);
        } else {
            Craft::info("Error digest: $result.", Plugin::LOG_CATEGORY);
        }

        return $result;
    }

    /** @return array<string, mixed>|null */
    private function row(): ?array
    {
        return (new Query())->from([self::TABLE])->where(['handle' => self::HANDLE])->one() ?: null;
    }

    /** Shared by `Install` and the migration that adds the table to an existing install. */
    public static function createTable(Migration $migration): void
    {
        $migration->createTable(self::TABLE, [
            'id' => $migration->primaryKey(),
            'handle' => $migration->string(64)->notNull(),
            // The period last claimed: `2026-10-09` or `2026-W41`. Null until the first run.
            'period' => $migration->string(32),
            // JSON: per log file, the byte position the last digest read up to. Null until the
            // first digest is recorded.
            'offsets' => $migration->mediumText(),
            'lastRunAt' => $migration->dateTime(),
            'lastSentAt' => $migration->dateTime(),
            'lastResult' => $migration->string(32),
            'lastCount' => $migration->integer()->notNull()->defaultValue(0),
            'dateCreated' => $migration->dateTime()->notNull(),
            'dateUpdated' => $migration->dateTime()->notNull(),
            'uid' => $migration->uid(),
        ]);

        $migration->createIndex(null, self::TABLE, ['handle'], true);
    }

    // ---------------------------------------------------------------------------- the email

    /**
     * Renders and sends. Null when an event handler cancelled it.
     *
     * @param string[] $recipients
     * @param array{groups: array<string, array<string, mixed>>, total: int, ungrouped: int, offsets: array<string, mixed>, files: array<string, int>, levels: array<string, int>} $scan
     */
    private function deliver(array $recipients, array $scan, bool $test): ?int
    {
        $event = new DigestEvent([
            'recipients' => $recipients,
            'subject' => $this->subject($scan['total'], $test),
            'variables' => $this->variables($scan, $test),
            'isTest' => $test,
        ]);
        $this->trigger(self::EVENT_BEFORE_SEND, $event);

        if (!$event->isValid) {
            return null;
        }

        return Mailer::send($event->recipients, $event->subject, 'freelog/_emails/digest', $event->variables);
    }

    // ===================================================================== What this plugin reports
    //
    // Freelog's: read each log from where the last digest stopped, keep the entries at the
    // reported levels, group repeats, redact what goes in the email.

    /**
     * Reads every live log from its saved position and groups the new entries at the reported
     * levels.
     *
     * Positions are keyed by inode where the filesystem has them, so a log renamed by rotation
     * (`web.log` → `web.log.1`) carries on from where it was, and a log that has shrunk (cleared,
     * or rotated by copy-and-truncate) starts again from the top — with the rest of its old
     * content read from the rotated copy beside it. Gzipped rotations are never read: their
     * content was reported while it was the live log.
     *
     * @param array<string, array{file: string, offset: int, head?: string|null}>|null $offsets Null on the first run.
     * @param DateTimeImmutable|null $since On the first run only: ignore entries older than this.
     * @return array{groups: array<string, array<string, mixed>>, total: int, ungrouped: int, offsets: array<string, array{file: string, offset: int, head?: string|null}>, files: array<string, int>, levels: array<string, int>}
     */
    public function collect(?array $offsets, ?DateTimeImmutable $since = null): array
    {
        $logs = Plugin::current()->getLogService();
        $settings = $this->settings();
        $levels = $settings->levelList();
        $cutoff = $since?->format('Y-m-d H:i:s');

        $files = [];

        foreach ($logs->getLogFiles() as $file) {
            if ($file['compressed']) {
                continue;
            }

            $path = $logs->resolveFilePath($file['name']);

            if ($path === null) {
                continue;
            }

            clearstatcache(true, $path);
            $inode = (int)@fileinode($path);
            $stat = @stat($path);
            $device = $stat !== false ? (int)$stat['dev'] : 0;

            $files[$file['name']] = [
                'name' => $file['name'],
                'key' => $inode > 0 ? "i:$device:$inode" : 'n:' . $file['name'],
                'size' => (int)@filesize($path),
                'rotated' => $logs->isRotated($file['name']),
            ];
        }

        $scan = [
            'groups' => [],
            'total' => 0,
            'ungrouped' => 0,
            'offsets' => [],
            'files' => [],
            'levels' => [],
        ];

        // Read from [file => start]; positions recorded after.
        $plan = [];
        $known = $offsets ?? [];

        foreach ($files as $name => $file) {
            if (isset($plan[$name])) {
                continue;
            }

            if ($offsets === null) {
                // First run: live logs from the top (entries before `$since` are skipped),
                // rotated copies not at all.
                $plan[$name] = $file['rotated'] ? $file['size'] : 0;
                continue;
            }

            $previous = $known[$file['key']]['offset'] ?? null;

            if ($previous === null) {
                // A log we have not seen: a new live log is all new; a rotated copy we have not
                // seen holds what the live log held, which was read then.
                $plan[$name] = $file['rotated'] ? $file['size'] : 0;
                continue;
            }

            $head = $known[$file['key']]['head'] ?? null;

            if ($file['size'] < $previous || ($head !== null && $this->head($logs, $name, $previous) !== $head)) {
                // Shrunk, or rewritten under us: cleared, or rotated by copy-and-truncate and
                // already written to again. Start again from the top, and look for the copy that
                // holds the rest of what we had not read yet.
                $plan[$name] = 0;

                foreach ([$name . '.1', $name . '.0'] as $sibling) {
                    if (isset($files[$sibling]) && !isset($known[$files[$sibling]['key']]) && $files[$sibling]['size'] >= $previous) {
                        $plan[$sibling] = $previous;
                        break;
                    }
                }

                continue;
            }

            $plan[$name] = $previous;
        }

        foreach ($plan as $name => $start) {
            $file = $files[$name];
            $end = $start;

            if ($start < $file['size']) {
                $entries = $logs->entriesSince($name, $start);

                foreach ($entries as $entry) {
                    if (!in_array($entry['level'], $levels, true)) {
                        continue;
                    }

                    if ($settings->digestIgnoreClientErrors && preg_match('/^yii\\\\web\\\\HttpException:4\d\d$/', $entry['category'])) {
                        continue;
                    }

                    if ($cutoff !== null && $entry['date'] < $cutoff) {
                        continue;
                    }

                    $this->add($scan, $name, $entry);
                }

                $end = $entries->getReturn();
            }

            $scan['offsets'][$file['key']] = ['file' => $name, 'offset' => $end, 'head' => $this->head($logs, $name, $end)];
        }

        return $scan;
    }

    /**
     * A fingerprint of a log's first bytes (up to {@see HEAD_BYTES}, never past `$upTo`), kept with
     * its position. Same inode, size still past the position, different first bytes: the file was
     * truncated and written again between two digests — which a size check alone cannot see.
     */
    private function head(LogService $logs, string $name, int $upTo): string
    {
        $path = $logs->resolveFilePath($name);
        $length = min($upTo, self::HEAD_BYTES);

        if ($path === null || $length < 1) {
            return '';
        }

        $bytes = @file_get_contents($path, false, null, 0, $length);

        return $bytes === false ? '' : substr(md5($bytes), 0, 16);
    }

    /**
     * Groups an entry with others like it: same level, same category, same message once the
     * numbers, quoted values and ids in it are set aside. A thousand "Undefined array key 17"
     * from one bad template is one line in the email with a count, not a thousand.
     *
     * @param array{groups: array<string, array<string, mixed>>, total: int, ungrouped: int, offsets: array<string, mixed>, files: array<string, int>, levels: array<string, int>} $scan
     * @param array{date: string, level: string, category: string, message: string} $entry
     */
    private function add(array &$scan, string $file, array $entry): void
    {
        $scan['total']++;
        $scan['files'][$file] = ($scan['files'][$file] ?? 0) + 1;
        $scan['levels'][$entry['level']] = ($scan['levels'][$entry['level']] ?? 0) + 1;

        [$message, $trace] = self::split($entry['message']);
        $key = substr(md5($entry['level'] . '|' . $entry['category'] . '|' . self::fingerprint($message)), 0, 16);

        if (!isset($scan['groups'][$key])) {
            if (count($scan['groups']) >= self::MAX_GROUPS) {
                $scan['ungrouped']++;

                return;
            }

            $scan['groups'][$key] = [
                'level' => $entry['level'],
                'category' => $entry['category'],
                'count' => 0,
                'firstSeen' => $entry['date'],
                'files' => [],
            ];
        }

        $group = &$scan['groups'][$key];
        $group['count']++;
        $group['lastSeen'] = $entry['date'];
        // The latest occurrence is the one quoted: it is the one still happening.
        $group['message'] = $message;
        $group['trace'] = $trace;

        if (count($group['files']) < 5 && !in_array($file, $group['files'], true)) {
            $group['files'][] = $file;
        }
    }

    /**
     * An entry's first line, without the JSON context Monolog appends to it, and the lines after
     * it (the stack trace), at most {@see MAX_TRACE_LINES} of them.
     *
     * @return array{string, string}
     */
    public static function split(string $message): array
    {
        $lines = explode("\n", $message);
        $first = (string)array_shift($lines);

        // Monolog's line format ends with the record's context and extra as JSON: ` {"…":…} []`.
        // Strip up to two trailing JSON values, trying the leftmost candidate first so a whole
        // nested object goes rather than its last key.
        for ($pass = 0; $pass < 2; $pass++) {
            if (!preg_match_all('/\s(?=\{"|\[\]\s*$|\[\]\s+\{)/', $first, $found, PREG_OFFSET_CAPTURE)) {
                break;
            }

            $stripped = false;

            foreach ($found[0] as [, $offset]) {
                $tail = trim(substr($first, $offset));

                if ($tail !== '' && json_decode($tail) !== null) {
                    $first = rtrim(substr($first, 0, $offset));
                    $stripped = true;
                    break;
                }
            }

            if (!$stripped) {
                break;
            }
        }

        $trace = array_slice(array_filter($lines, fn($line) => trim($line) !== ''), 0, self::MAX_TRACE_LINES);

        return [trim($first), implode("\n", $trace)];
    }

    /** A message with what varies between occurrences taken out. */
    public static function fingerprint(string $message): string
    {
        return (string)preg_replace(
            [
                '/(["\']).*?\1/',                            // quoted values
                '/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i', // UUIDs
                '/\b[0-9a-f]{12,}\b/i',                      // hashes and hex ids
                '/\d+/',                                     // numbers
            ],
            ['""', 'UUID', 'HEX', '#'],
            mb_substr($message, 0, 1000),
        );
    }

    private function subject(int $count, bool $test): string
    {
        $system = Craft::$app->getSystemName();
        $subject = $count > 0
            ? Craft::t('freelog', '{count, plural, =1{One new log error} other{# new log errors}} on {site}', [
                'count' => $count,
                'site' => $system,
            ])
            : Craft::t('freelog', 'No new log errors on {site}', ['site' => $system]);

        return ($test ? Craft::t('freelog', '[Test]') . ' ' : '') . 'Freelog: ' . $subject;
    }

    /**
     * What the email templates see. Every string that came out of a log is redacted here, once,
     * so neither template — nor an event handler that only adds to them — can quote it raw.
     *
     * @param array{groups: array<string, array<string, mixed>>, total: int, ungrouped: int, offsets: array<string, mixed>, files: array<string, int>, levels: array<string, int>} $scan
     * @return array<string, mixed>
     */
    private function variables(array $scan, bool $test): array
    {
        $settings = $this->settings();
        $redactor = Redactor::forInstall();
        $rank = array_flip(Settings::LEVELS);

        $groups = array_values($scan['groups']);
        usort($groups, fn(array $a, array $b) => [$rank[$a['level']] ?? 99, -$a['count'], $b['lastSeen']]
            <=> [$rank[$b['level']] ?? 99, -$b['count'], $a['lastSeen']]);

        $items = [];

        foreach (array_slice($groups, 0, self::MAX_ITEMS) as $group) {
            $items[] = [
                'level' => $group['level'],
                'category' => $redactor->redact((string)$group['category'], 200),
                'count' => $group['count'],
                'firstSeen' => $group['firstSeen'],
                'lastSeen' => $group['lastSeen'],
                'files' => $group['files'],
                'message' => $redactor->redact((string)$group['message'], self::MAX_MESSAGE),
                'trace' => $settings->digestIncludeTraces && $group['trace'] !== ''
                    ? $redactor->redact((string)$group['trace'], self::MAX_TRACE)
                    : null,
                'url' => UrlHelper::cpUrl('freelog/view', ['file' => $group['files'][0] ?? '', 'level' => $group['level']]),
            ];
        }

        arsort($scan['files']);
        $levels = [];

        foreach (Settings::LEVELS as $level) {
            if (!empty($scan['levels'][$level])) {
                $levels[$level] = $scan['levels'][$level];
            }
        }

        return [
            'siteName' => Craft::$app->getSystemName(),
            'isTest' => $test,
            'items' => $items,
            'total' => $scan['total'],
            'groupCount' => count($scan['groups']),
            'more' => max(0, count($scan['groups']) - self::MAX_ITEMS),
            'ungrouped' => $scan['ungrouped'],
            'levels' => $levels,
            'files' => $scan['files'],
            'since' => $this->state()['lastSentAt'],
            'includeTraces' => $settings->digestIncludeTraces,
            'logsUrl' => UrlHelper::cpUrl('freelog'),
            'settingsUrl' => UrlHelper::cpUrl('freelog/settings'),
        ];
    }

    /**
     * The first digest has no positions to start from. Rather than mail a log's whole history,
     * it covers one period back: the last day, or the last week.
     *
     * @param array{offsets: array<string, mixed>|null} $state
     */
    private function firstRunSince(array $state, DateTimeImmutable $now): ?DateTimeImmutable
    {
        if ($state['offsets'] !== null) {
            return null;
        }

        return $now->modify($this->settings()->digestFrequency === Settings::DIGEST_DAILY ? '-1 day' : '-1 week');
    }

    // ---------------------------------------------------------------------------------- misc

    private function inSystemTime(DateTimeInterface $moment): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($moment)
            ->setTimezone(new DateTimeZone(Craft::$app->getTimeZone()));
    }

    private function settings(): Settings
    {
        /** @var Settings $settings */
        $settings = Plugin::current()->getSettings();

        return $settings;
    }
}
