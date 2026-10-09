<?php
/**
 * The scheduled error digest: settings, schedule, the durable marker, per-log read positions
 * (rotation, truncation, half-written lines), redaction, the email, the queue fallback and the
 * console command.
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-freelogs/tests/integration/digest.php
 *
 * Self-cleaning. The log service is pointed at a scratch directory, so the harness's own logs
 * (which other builders write to while this runs) are never read; settings are swapped in memory;
 * the marker row is captured and put back; mail goes to a null transport and is captured from the
 * mailer's own event. Nothing here triggers garbage collection.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\db\Query;
use craft\helpers\Db;
use craft\mail\Mailer as CraftMailer;
use craft\mail\Message;
use justinholtweb\freelog\events\DigestEvent;
use justinholtweb\freelog\jobs\SendDigest;
use justinholtweb\freelog\models\Settings;
use justinholtweb\freelog\Plugin;
use justinholtweb\freelog\services\Digest;
use justinholtweb\freelog\services\LogService;
use Symfony\Component\Mailer\Transport\NullTransport;
use yii\base\Event;
use yii\mail\BaseMailer;
use yii\mail\MailEvent;

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();

        if ($result === true) {
            $passed++;
            echo "  ✓ $label\n";
            return;
        }

        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : 'returned ' . var_export($result, true)) . "\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  ✗ $label\n    " . get_class($e) . ': ' . $e->getMessage() . "\n    " . $e->getFile() . ':' . $e->getLine() . "\n";
    }
}

function section(string $title): void
{
    echo "\n$title\n";
}

Craft::$app->getPlugins()->loadPlugins();

$plugin = Plugin::current();
$digest = $plugin->digest;
$original = $plugin->getSettings()->toArray();
$originalRow = (new Query())->from([Digest::TABLE])->where(['handle' => Digest::HANDLE])->one() ?: null;
$originalLogs = $plugin->getLogService();
$tz = new DateTimeZone(Craft::$app->getTimeZone());

// A scratch logs directory: the digest reads every log it is shown, and the harness's real ones
// are being written by other builders' runs.
$dir = sys_get_temp_dir() . '/freelog-digest-' . bin2hex(random_bytes(4));
mkdir($dir, 0777, true);
$plugin->set('logService', new class($dir) extends LogService {
    public function __construct(private string $dir)
    {
        parent::__construct();
    }

    public function getLogsPath(): string
    {
        return $this->dir;
    }
});

register_shutdown_function(function() use ($dir, $plugin, $original, $originalRow, $originalLogs) {
    foreach (glob("$dir/*") ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($dir);

    Db::delete(Digest::TABLE, ['handle' => Digest::HANDLE]);
    if ($originalRow) {
        unset($originalRow['id']);
        Db::insert(Digest::TABLE, $originalRow);
    }
    $plugin->setSettings($original);
    $plugin->set('logService', $originalLogs);
});

/** Swaps in a settings shape, in memory only. */
function configure(array $overrides = []): Settings
{
    global $original;

    $plugin = Plugin::current();
    $settings = new Settings();
    $settings->setAttributes(array_merge($original, $overrides), false);
    $plugin->setSettings($settings->toArray());

    return $plugin->getSettings();
}

function at(string $when): DateTimeImmutable
{
    global $tz;

    return new DateTimeImmutable($when, $tz);
}

function resetMarker(): void
{
    Db::delete(Digest::TABLE, ['handle' => Digest::HANDLE]);
}

function line(string $when, string $level, string $message, string $category = 'application'): string
{
    return "$when [web.$level] [$category] $message\n";
}

function logWrite(string $name, string $text, bool $append = true): void
{
    global $dir;

    file_put_contents("$dir/$name", $text, $append ? FILE_APPEND : 0);
    clearstatcache();
}

// Mail: a null transport, so nothing leaves the harness, and the mailer's own event to see it.
$sent = [];
$refuse = false;
Craft::$app->getMailer()->setTransport(new NullTransport());
Event::on(CraftMailer::class, BaseMailer::EVENT_BEFORE_SEND, function(MailEvent $event) use (&$sent, &$refuse) {
    if ($refuse) {
        $event->isValid = false;
        return;
    }

    $sent[] = $event->message;
});

/** @return Message[] What was sent since the last call. */
function drain(): array
{
    global $sent;

    $out = $sent;
    $sent = [];

    return $out;
}

function bodies(Message $message): array
{
    $html = '';
    $text = '';
    $email = $message->getSymfonyEmail();
    $html = (string)$email->getHtmlBody();
    $text = (string)$email->getTextBody();

    return [$html, $text];
}

$daily = [
    'digestEnabled' => true,
    'digestFrequency' => 'daily',
    'digestHour' => 8,
    'digestRecipients' => 'ops@example.test, dev@example.test',
    'digestSendWhenEmpty' => false,
    'digestLevels' => [],
    'digestIgnoreClientErrors' => true,
    'digestIncludeTraces' => false,
    'digestWebTrigger' => true,
];

// ============================================================================ settings

section('Settings');

check('a bare install is valid — nothing about the digest is required', function() {
    $settings = new Settings();

    return ($settings->validate() && $settings->digestEnabled === false && $settings->digestIncludeTraces === false)
        ?: json_encode($settings->getErrors());
});

check('recipients split on commas, semicolons and new lines; a bad one fails by name', function() {
    $settings = new Settings();
    $settings->setAttributes(['digestRecipients' => "a@example.test, b@example.test;\nb@example.test  nope"], false);
    $settings->validate();

    return ($settings->recipientList() === ['a@example.test', 'b@example.test'] && str_contains(implode(' ', $settings->getErrors('digestRecipients')), 'nope'))
        ?: json_encode([$settings->recipientList(), $settings->getErrors()]);
});

check('recipients can come from an environment variable', function() {
    putenv('FREELOG_DIGEST_TEST_TO=env@example.test');
    $_SERVER['FREELOG_DIGEST_TEST_TO'] = 'env@example.test';
    $settings = new Settings(['digestRecipients' => '$FREELOG_DIGEST_TEST_TO']);

    return $settings->recipientList() === ['env@example.test'] ?: json_encode($settings->recipientList());
});

check('posted strings are cast; an empty number box keeps the default; ranges are checked', function() {
    $settings = new Settings();
    $settings->setAttributes(['digestEnabled' => '1', 'digestHour' => '', 'digestWeekday' => '9', 'digestIncludeTraces' => ''], false);
    $settings->validate();

    return ($settings->digestEnabled === true && $settings->digestHour === 8 && $settings->digestIncludeTraces === false && $settings->hasErrors('digestWeekday'))
        ?: json_encode([$settings->toArray(), $settings->getErrors()]);
});

check('an unticked level group posts [""], becomes [] and means the default levels; an unknown level fails', function() {
    $settings = new Settings();
    $settings->setAttributes(['digestLevels' => ['']], false);
    $ok = $settings->digestLevels === [] && $settings->levelList() === Settings::DIGEST_DEFAULT_LEVELS && $settings->validate();
    $settings->setAttributes(['digestLevels' => ['error', 'shouting']], false);

    return ($ok && !$settings->validate() && $settings->hasErrors('digestLevels')) ?: json_encode($settings->getErrors());
});

// ============================================================================ schedule

section('Schedule');

check('the marker table exists', fn() => Craft::$app->getDb()->tableExists(Digest::TABLE) ?: 'missing');

check('a daily period is the date; a weekly one is the ISO week (o, not Y)', function() use ($digest, $daily) {
    configure($daily);
    $day = $digest->periodKey(at('2027-01-01 09:00'));
    configure(['digestFrequency' => 'weekly', 'digestWeekday' => 1] + $daily);
    $week = $digest->periodKey(at('2027-01-01 09:00'));
    $due = $digest->dueAt(at('2026-10-09 12:00'))->format('Y-m-d H:i');

    return ($day === '2027-01-01' && $week === '2026-W53' && $due === '2026-10-05 08:00') ?: json_encode([$day, $week, $due]);
});

check('switched off: nothing is sent and nothing is recorded', function() use ($digest, $daily) {
    resetMarker();
    configure(['digestEnabled' => false] + $daily);
    $result = $digest->run(at('2026-10-09 09:00'));

    return ($result === Digest::RESULT_DISABLED && drain() === [] && (new Query())->from([Digest::TABLE])->count() == 0) ?: $result;
});

check('switched on with nobody to send to says so', function() use ($digest, $daily) {
    configure(['digestRecipients' => ''] + $daily);

    return $digest->run(at('2026-10-09 09:00')) === Digest::RESULT_NO_RECIPIENTS ?: 'wrong result';
});

check('before the hour it is not due', function() use ($digest, $daily) {
    configure($daily);

    return ($digest->run(at('2026-10-09 07:59')) === Digest::RESULT_NOT_DUE && !$digest->isDue(at('2026-10-09 07:59')) && $digest->isDue(at('2026-10-09 08:00')))
        ?: 'due early';
});

// ============================================================================ runs

section('Runs, against logs written on purpose');

$secretKey = (string)Craft::$app->getConfig()->getGeneral()->securityKey;

check('the first digest covers one period back: old entries, info lines and 4xx noise are left out', function() use ($digest, $daily) {
    resetMarker();
    configure($daily);
    logWrite('web.log',
        line('2026-10-07 10:00:00', 'ERROR', 'Ancient failure two days ago')
        . line('2026-10-08 12:00:00', 'INFO', 'A routine request')
        . line('2026-10-08 12:01:00', 'ERROR', 'yii\web\NotFoundHttpException: Template not found: nope', 'yii\web\HttpException:404')
        . line('2026-10-08 12:02:00', 'ERROR', 'Database went away during save')
        . "Stack trace:\n#0 /var/www/html/vendor/a.php(1): x()\n"
        . line('2026-10-08 12:03:00', 'CRITICAL', 'Queue worker crashed'),
        false);

    $result = $digest->run(at('2026-10-09 08:05'));
    $mail = drain();
    [$html, $text] = bodies($mail[0]);

    $ok = $result === Digest::RESULT_SENT
        && count($mail) === 2
        && array_map(fn(Message $m) => array_keys($m->getTo()), $mail) === [['ops@example.test'], ['dev@example.test']]
        && str_contains($text, 'Database went away during save')
        && str_contains($text, 'Queue worker crashed')
        && !str_contains($text, 'Ancient failure')
        && !str_contains($text, 'routine request')
        && !str_contains($text, 'Template not found')
        && $digest->state()['lastCount'] === 2;

    return $ok ?: json_encode([$result, count($mail), $text, $digest->state()['lastCount']]);
});

check('the email has HTML and text parts, a deep link to the file, and no stack trace by default', function() use ($digest, $daily) {
    resetMarker();
    configure($daily);
    $digest->run(at('2026-10-09 08:05'));
    $mail = drain();
    [$html, $text] = bodies($mail[0]);

    $ok = $html !== '' && $text !== ''
        && str_contains($html, 'freelog/view')
        && str_contains($html, 'web.log')
        && !str_contains($html . $text, 'vendor/a.php')
        && str_contains($mail[0]->getSubject(), 'Freelog: 2 new log errors');

    return $ok ?: json_encode([$mail[0]->getSubject(), $text]);
});

check('a second run in the same period does nothing — idempotent', function() use ($digest) {
    $result = $digest->run(at('2026-10-09 15:00'));

    return ($result === Digest::RESULT_ALREADY_SENT && drain() === []) ?: $result;
});

check('the SendDigest job is the same decision — a duplicate job is a no-op', function() {
    (new SendDigest())->execute(Craft::$app->getQueue());

    return drain() === [] ?: 'sent again';
});

check('next day with nothing new: no email, but the period is used up and positions kept', function() use ($digest, $dir) {
    $result = $digest->run(at('2026-10-10 08:05'));
    $state = $digest->state();
    $offset = array_values($state['offsets'])[0]['offset'] ?? null;

    return ($result === Digest::RESULT_NOTHING_NEW && drain() === [] && $state['period'] === '2026-10-10' && $offset === filesize("$dir/web.log"))
        ?: json_encode([$result, $state]);
});

check('next day with a new error: only that one is listed', function() use ($digest) {
    logWrite('web.log', line('2026-10-10 14:00:00', 'ERROR', 'Brand new failure'));
    $result = $digest->run(at('2026-10-11 08:05'));
    $mail = drain();
    [, $text] = $mail ? bodies($mail[0]) : ['', ''];

    return ($result === Digest::RESULT_SENT && str_contains($text, 'Brand new failure') && !str_contains($text, 'Database went away') && $digest->state()['lastCount'] === 1)
        ?: json_encode([$result, $text]);
});

check('repeats are grouped: three of one error is one line with ×3', function() use ($digest) {
    logWrite('web.log',
        line('2026-10-11 09:00:00', 'ERROR', 'Undefined array key 17 in "site/_entry"')
        . line('2026-10-11 09:01:00', 'ERROR', 'Undefined array key 4 in "site/_news"')
        . line('2026-10-11 09:02:00', 'ERROR', 'Undefined array key 99 in "site/_entry"'));
    $digest->run(at('2026-10-12 08:05'));
    $mail = drain();
    [, $text] = $mail ? bodies($mail[0]) : ['', ''];

    return (substr_count($text, 'Undefined array key') === 1 && str_contains($text, '×3') && str_contains($text, 'key 99'))
        ?: $text;
});

section('Redaction');

check('secrets, credentials and personal data are masked in both parts; the CSRF prose keeps its words', function() use ($digest, $secretKey) {
    logWrite('web.log',
        line('2026-10-12 09:00:00', 'ERROR', "Login failed for jo.smith@example.com from 203.0.113.42 with password=hunter2&remember=1")
        . line('2026-10-12 09:01:00', 'ERROR', 'Upstream said 401 for Authorization: Bearer abc123def456ghi789jkl')
        . line('2026-10-12 09:02:00', 'ERROR', "Signature check used key $secretKey")
        . line('2026-10-12 09:03:00', 'ERROR', 'Invalid CSRF token: missing from the request')
        . line('2026-10-12 09:04:00', 'ERROR', '<script>alert(1)</script> in a template'));
    $digest->run(at('2026-10-13 08:05'));
    $mail = drain();
    [$html, $text] = $mail ? bodies($mail[0]) : ['', ''];
    $both = $html . $text;

    $leaks = array_filter([
        'password' => str_contains($both, 'hunter2'),
        'email local part' => str_contains($both, 'jo.smith'),
        'last IP octet' => str_contains($both, '203.0.113.42'),
        'bearer token' => str_contains($both, 'abc123def456'),
        'security key' => strlen($secretKey) >= 6 && str_contains($both, $secretKey),
        'raw script tag in HTML' => str_contains($html, '<script>alert'),
    ]);
    $kept = str_contains($text, 'Invalid CSRF token: missing from the request') && str_contains($text, '@example.com') && str_contains($text, '203.0.113.x');

    return ($leaks === [] && $kept) ?: 'leaked: ' . implode(', ', array_keys($leaks)) . ($kept ? '' : ' / prose or domain lost') . "\n" . $text;
});

check('stack traces stay out unless an admin turns them on — and then they are redacted too', function() use ($digest, $daily) {
    logWrite('web.log',
        line('2026-10-13 09:00:00', 'ERROR', 'Payment failed')
        . "Stack trace:\n#0 /var/www/html/src/Pay.php(12): charge('tok_live_1234567890abcdef', 'buyer@example.com')\n#1 {main}\n");

    configure($daily);
    $digest->sendTest(['ops@example.test'], at('2026-10-14 08:05'));
    [, $off] = bodies(drain()[0]);

    configure(['digestIncludeTraces' => true] + $daily);
    $digest->sendTest(['ops@example.test'], at('2026-10-14 08:05'));
    [$html, $on] = bodies(drain()[0]);
    configure($daily);

    $ok = !str_contains($off, 'Pay.php')
        && str_contains($on, 'Pay.php(12)')
        && !str_contains($on . $html, 'buyer@')
        && str_contains($on, '@example.com');

    return $ok ?: "off:\n$off\non:\n$on";
});

section('Positions survive rotation, truncation and half-written lines');

check('a line still being written is left for the next digest, and then reported whole', function() use ($digest) {
    $digest->run(at('2026-10-14 08:05')); // flush the payment entry
    drain();
    logWrite('web.log', '2026-10-14 10:00:00 [web.ERROR] [application] Half-wri');
    $first = $digest->run(at('2026-10-15 08:05'));
    $firstMail = drain();
    logWrite('web.log', "tten line finished\n");
    $second = $digest->run(at('2026-10-16 08:05'));
    $mail = drain();
    [, $text] = $mail ? bodies($mail[0]) : ['', ''];

    return ($first === Digest::RESULT_NOTHING_NEW && $firstMail === [] && $second === Digest::RESULT_SENT && str_contains($text, 'Half-written line finished'))
        ?: json_encode([$first, $second, $text]);
});

check('a cleared log starts again from the top', function() use ($digest) {
    logWrite('web.log', line('2026-10-16 10:00:00', 'ERROR', 'After the clear'), false);
    $result = $digest->run(at('2026-10-17 08:05'));
    $mail = drain();
    [, $text] = $mail ? bodies($mail[0]) : ['', ''];

    return ($result === Digest::RESULT_SENT && str_contains($text, 'After the clear') && $digest->state()['lastCount'] === 1) ?: json_encode([$result, $text]);
});

check('copy-and-truncate rotation: the unread tail is read from the copy, nothing twice — even when the new log is already longer', function() use ($digest, $dir) {
    logWrite('web.log', line('2026-10-17 10:00:00', 'ERROR', 'Written just before rotation') . line('2026-10-17 10:01:00', 'ERROR', 'Second before rotation'));
    copy("$dir/web.log", "$dir/web.log.1");
    // Truncated in place (same inode) and written past the old position before the next digest.
    $handle = fopen("$dir/web.log", 'r+');
    ftruncate($handle, 0);
    fclose($handle);
    logWrite('web.log', line('2026-10-17 11:00:00', 'ERROR', 'Written after rotation, and long enough to pass the old read position easily'));

    $digest->run(at('2026-10-18 08:05'));
    $mail = drain();
    [, $text] = $mail ? bodies($mail[0]) : ['', ''];
    @unlink("$dir/web.log.1");

    $ok = str_contains($text, 'Written just before rotation') && str_contains($text, 'Second before rotation') && str_contains($text, 'Written after rotation')
        && !str_contains($text, 'After the clear') && $digest->state()['lastCount'] === 3;

    return $ok ?: $text;
});

check('rename rotation: the renamed file carries on from its position, the new one from the top', function() use ($digest, $dir) {
    logWrite('web.log', line('2026-10-18 10:00:00', 'ERROR', 'Last line before rename'));
    rename("$dir/web.log", "$dir/web.log.1");
    logWrite('web.log', line('2026-10-18 11:00:00', 'ERROR', 'First line after rename'), false);

    $digest->run(at('2026-10-19 08:05'));
    $mail = drain();
    [, $text] = $mail ? bodies($mail[0]) : ['', ''];
    @unlink("$dir/web.log.1");

    $ok = str_contains($text, 'Last line before rename') && str_contains($text, 'First line after rename')
        && !str_contains($text, 'Written after rotation') && $digest->state()['lastCount'] === 2;

    return $ok ?: $text;
});

check('a new dated log (Craft 5’s web-YYYY-MM-DD.log) is read from the top; gzipped rotations never', function() use ($digest, $dir) {
    logWrite('web-2026-10-19.log', line('2026-10-19 10:00:00', 'ERROR', 'In tomorrow’s file'), false);
    file_put_contents("$dir/web.log.2.gz", gzencode(line('2026-10-19 10:00:00', 'ERROR', 'Inside a gzip')));

    $digest->run(at('2026-10-20 08:05'));
    $mail = drain();
    [, $text] = $mail ? bodies($mail[0]) : ['', ''];
    @unlink("$dir/web.log.2.gz");

    return (str_contains($text, 'In tomorrow') && !str_contains($text, 'Inside a gzip')) ?: $text;
});

section('Failures, events and races');

check('a refused send gives the period back and keeps the positions; the next run sends the same entries', function() use ($digest, &$refuse) {
    logWrite('web.log', line('2026-10-20 10:00:00', 'ERROR', 'Mail server was down for this one'));
    $before = $digest->state();
    $refuse = true;
    $failedRun = $digest->run(at('2026-10-21 08:05'));
    $refuse = false;
    $after = $digest->state();
    $retry = $digest->run(at('2026-10-21 08:20'));
    $mail = drain();
    [, $text] = $mail ? bodies($mail[0]) : ['', ''];

    return ($failedRun === Digest::RESULT_FAILED && $after['period'] === $before['period'] && $after['offsets'] === $before['offsets']
        && $retry === Digest::RESULT_SENT && str_contains($text, 'Mail server was down'))
        ?: json_encode([$failedRun, $retry, $before['period'], $after['period']]);
});

check('an event handler can cancel it, and the period is given back', function() use ($digest) {
    logWrite('web.log', line('2026-10-21 10:00:00', 'ERROR', 'Cancelled one'));
    $handler = function(DigestEvent $event) {
        $event->isValid = false;
    };
    Event::on(Digest::class, Digest::EVENT_BEFORE_SEND, $handler);
    $result = $digest->run(at('2026-10-22 08:05'));
    Event::off(Digest::class, Digest::EVENT_BEFORE_SEND, $handler);

    return ($result === Digest::RESULT_CANCELLED && drain() === [] && $digest->state()['period'] === '2026-10-21') ?: $result;
});

check('an event handler can change the recipients', function() use ($digest) {
    $handler = function(DigestEvent $event) {
        $event->recipients = ['only@example.test'];
    };
    Event::on(Digest::class, Digest::EVENT_BEFORE_SEND, $handler);
    $digest->run(at('2026-10-22 08:10'));
    Event::off(Digest::class, Digest::EVENT_BEFORE_SEND, $handler);
    $mail = drain();

    return (count($mail) === 1 && array_keys($mail[0]->getTo()) === ['only@example.test']) ?: json_encode(array_map(fn($m) => $m->getTo(), $mail));
});

check('of two runs racing for one period, exactly one claims it', function() use ($digest) {
    $claim = new ReflectionMethod(Digest::class, 'claim');
    $previous = $digest->state()['period'];
    $one = $claim->invoke($digest, '2026-10-23', $previous);
    $two = $claim->invoke($digest, '2026-10-23', $previous);
    Db::update(Digest::TABLE, ['period' => $previous], ['handle' => Digest::HANDLE]);

    return ($one === true && $two === false) ?: json_encode([$one, $two]);
});

check('--force sends inside a period that has already gone, and records it', function() use ($digest) {
    logWrite('web.log', line('2026-10-22 11:00:00', 'ERROR', 'Forced out'));
    $result = $digest->run(at('2026-10-22 09:00'), true);
    $mail = drain();

    return ($result === Digest::RESULT_SENT && count($mail) === 2 && $digest->run(at('2026-10-22 10:00')) === Digest::RESULT_ALREADY_SENT) ?: $result;
});

check('a test digest is marked as one, and leaves the marker and every position alone', function() use ($digest) {
    logWrite('web.log', line('2026-10-22 12:00:00', 'ERROR', 'Seen by the test first'));
    $before = $digest->state();
    $count = $digest->sendTest(['ops@example.test'], at('2026-10-22 13:00'));
    $mail = drain();
    $after = $digest->state();
    [, $text] = $mail ? bodies($mail[0]) : ['', ''];

    // ...and the real one still reports it.
    $real = $digest->run(at('2026-10-23 08:05'));
    [, $realText] = ($m = drain()) ? bodies($m[0]) : ['', ''];

    return ($count === 1 && str_starts_with($mail[0]->getSubject(), '[Test]') && str_contains($text, 'Seen by the test first')
        && $before['offsets'] === $after['offsets'] && $before['period'] === $after['period'] && $before['lastSentAt'] == $after['lastSentAt']
        && $real === Digest::RESULT_SENT && str_contains($realText, 'Seen by the test first'))
        ?: json_encode([$count, $real]);
});

check('levels are configurable: warnings only when ticked', function() use ($digest, $daily) {
    logWrite('web.log', line('2026-10-23 10:00:00', 'WARNING', 'Deprecated thing used'));
    configure(['digestLevels' => ['error', 'warning']] + $daily);
    $digest->sendTest(['ops@example.test'], at('2026-10-24 08:00'));
    [, $with] = bodies(drain()[0]);
    configure($daily);
    $digest->sendTest(['ops@example.test'], at('2026-10-24 08:00'));
    [, $without] = bodies(drain()[0]);

    return (str_contains($with, 'Deprecated thing used') && !str_contains($without, 'Deprecated thing used')) ?: 'levels ignored';
});

// ============================================================================ fallback

section('The web fallback');

check('switched off, the fallback queues nothing', function() use ($digest, $daily) {
    configure(['digestWebTrigger' => false] + $daily);

    return $digest->queueIfDue(at('2026-10-30 08:05')) === false ?: 'queued';
});

check('due: it queues one SendDigest job, then stays quiet for five minutes', function() use ($digest, $daily) {
    configure($daily);
    $cache = Craft::$app->getCache();
    $cache->delete('freelog:digest:checked');
    $cache->delete('freelog:digest:queued:2026-10-30');

    $first = $digest->queueIfDue(at('2026-10-30 08:05'));
    $second = $digest->queueIfDue(at('2026-10-30 08:06'));

    $jobs = (new Query())->select(['id'])->from(['{{%queue}}'])
        ->where(['description' => Craft::t('freelog', 'Sending the Freelog error digest')])
        ->column();

    foreach ($jobs as $id) {
        Craft::$app->getQueue()->release((string)$id);
    }

    $cache->delete('freelog:digest:checked');
    $cache->delete('freelog:digest:queued:2026-10-30');

    return ($first === true && $second === false && count($jobs) === 1) ?: json_encode([$first, $second, $jobs]);
});

check('not due: the fallback queues nothing', function() use ($digest, $daily) {
    configure($daily);
    Craft::$app->getCache()->delete('freelog:digest:checked');
    $queued = $digest->queueIfDue(at('2026-10-30 07:00'));
    Craft::$app->getCache()->delete('freelog:digest:checked');

    return $queued === false ?: 'queued early';
});

check('nothing in the digest goes near garbage collection', function() {
    $source = (string)file_get_contents(dirname(__DIR__, 2) . '/src/services/Digest.php')
        . file_get_contents(dirname(__DIR__, 2) . '/src/Plugin.php');

    return (!str_contains($source, 'craft\\services\\Gc') && !preg_match('/Event::on\(\s*Gc::/', $source)) ?: 'hooks Gc';
});

// ============================================================================ console

section('Console');

check('`freelog/digest/send` runs and exits cleanly with the saved settings', function() {
    exec('php craft freelog/digest/send 2>&1', $output, $code);
    $text = implode("\n", $output);

    // Whatever the harness's saved settings are, this is not a fault: off, not due, gone, nothing new, or no recipients (78).
    return in_array($code, [0, 78], true) ?: "exit $code: $text";
});

check('`freelog/digest/status` prints the schedule', function() {
    exec('php craft freelog/digest/status 2>&1', $output, $code);
    $text = implode("\n", $output);

    return ($code === 0 && str_contains($text, 'Next due') && str_contains($text, 'Logs tracked')) ?: "exit $code: $text";
});

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
