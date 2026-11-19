<?php

declare(strict_types=1);

use JustinHoltWeb\Freelog\Services\Digest;
use JustinHoltWeb\Freelog\Services\LogService;
use JustinHoltWeb\Freelog\Tests\Support\LogsTestCase;

/*
 * Both formats a Craft 6 site has in storage/logs: Laravel's (what Craft 6 writes) and Craft 5's
 * (what an upgraded site kept). The fixtures are real entries from the plugin-testing harnesses,
 * with session ids blanked.
 */

uses(LogsTestCase::class);

test('a Laravel log parses into whole entries, traces and JSON context included', function () {
    $this->write('laravel-2026-11-19.log', logFixture('laravel-2026-11-19.log'));

    $result = $this->service->getLogEntries('laravel-2026-11-19.log', limit: 50);
    $entries = array_reverse($result['entries']);

    expect($result['total'])->toBe(7)
        ->and(array_column($entries, 'level'))->toBe(['error', 'info', 'info', 'error', 'warning', 'emergency', 'debug'])
        ->and(array_column($entries, 'category'))->toBe(['local', 'local', 'local', 'local', 'local', 'laravel', 'local'])
        ->and($entries[0]['date'])->toBe('2026-11-19 03:09:14');

    // The ParseError: its trace and the closing `"}` stay with it.
    expect($entries[0]['message'])->toStartWith("Unclosed '[' on line 5 {\"exception\":\"[object] (ParseError(code: 0)")
        ->toContain("\n[stacktrace]\n#0 /var/www/html/vendor/laravel/framework/")
        ->toContain("#6 {main}\n\"}");

    // One-line JSON context stays on the line.
    expect($entries[1]['message'])->toBe('Request received. {"environment":"local","sessionId":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"}');

    // A long entry with a previous exception: 49 + 9 frames, a blank line between them, one entry.
    expect($entries[3]['message'])->toContain("\n[previous exception] [object] (PDOException(code: 42S02)")
        ->and(substr_count($entries[3]['message'], "\n#"))->toBeGreaterThan(50);
});

test('the level filter and search work on Laravel entries', function () {
    $this->write('laravel-2026-11-19.log', logFixture('laravel-2026-11-19.log'));

    $errors = $this->service->getLogEntries('laravel-2026-11-19.log', level: 'ERROR');
    expect($errors['total'])->toBe(2);

    $search = $this->service->getLogEntries('laravel-2026-11-19.log', search: 'base table or view');
    expect($search['total'])->toBe(1)
        ->and($search['entries'][0]['level'])->toBe('error');

    // Search reaches into the trace too: it is part of the message.
    expect($this->service->getLogEntries('laravel-2026-11-19.log', search: 'RegisterProviders.php(34)')['total'])->toBe(1);
});

test('a Craft 5 log still parses', function () {
    $this->write('web-2026-11-08.log', logFixture('web-2026-11-08.log'));

    $result = $this->service->getLogEntries('web-2026-11-08.log');
    $entries = array_reverse($result['entries']);

    expect($result['total'])->toBe(5)
        ->and(array_column($entries, 'level'))->toBe(['info', 'info', 'error', 'info', 'error'])
        ->and($entries[2]['category'])->toBe('yii\web\HttpException:403')
        ->and($entries[2]['message'])->toStartWith('yii\web\ForbiddenHttpException: User is not authorized')
        ->toContain("\nStack trace:\n#0 ")
        // The request-context dump that follows is its own entry, all 140-odd lines of it.
        ->and($entries[3]['message'])->toStartWith("Request context:\n\$_GET = [")
        ->and(substr_count($entries[3]['message'], "\n"))->toBeGreaterThan(100);
});

test('a file keeps the format its first entry has', function () {
    $format = null;

    expect(LogService::parseEntryStart('[2026-11-19 10:06:21] local.ERROR: boom', $format))->not->toBeNull()
        ->and($format)->toBe(LogService::FORMAT_MONOLOG)
        // Inside a Laravel trace, a line that looks like a Craft 5 entry or a bare date is not one.
        ->and(LogService::parseEntryStart('2026-11-19 10:06:22 [web.ERROR] [application] x', $format))->toBeNull()
        ->and(LogService::parseEntryStart('2026-11-19 10:06:22 select 1', $format))->toBeNull()
        ->and(LogService::parseEntryStart('[2026-11-19 10:06:22] not monolog', $format))->toBeNull();

    $format = null;
    expect(LogService::parseEntryStart('2026-10-09 12:00:00 [web.ERROR] [application] boom', $format))->not->toBeNull()
        ->and($format)->toBe(LogService::FORMAT_CRAFT5)
        ->and(LogService::parseEntryStart('[2026-11-19 10:06:21] local.ERROR: boom', $format))->toBeNull();

    // The fallback formats never fix one.
    $format = null;
    expect(LogService::parseEntryStart('2026-04-14 14:15:37 [ERROR] formie', $format))->not->toBeNull()
        ->and($format)->toBeNull();
});

test('a date-shaped line in a Laravel entry stays part of it', function () {
    $this->write('laravel.log', implode("\n", [
        '[2026-11-19 10:00:00] local.ERROR: Query failed {"sql":"…"}',
        '2026-11-19 09:59:59 is when the import started',
        '[2026-11-19 10:00:01] local.INFO: next',
        '',
    ]));

    $entries = $this->service->getLogEntries('laravel.log')['entries'];

    expect($entries)->toHaveCount(2)
        ->and($entries[1]['message'])->toBe("Query failed {\"sql\":\"…\"}\n2026-11-19 09:59:59 is when the import started");
});

test('ISO-8601 Monolog dates are normalised', function () {
    $entry = LogService::parseEntryStart('[2026-11-19T10:06:21.123456+00:00] production.CRITICAL: down');

    expect($entry)->toBe(['date' => '2026-11-19 10:06:21', 'level' => 'critical', 'category' => 'production', 'message' => 'down']);
});

test('a runaway Laravel entry is capped', function () {
    $trace = '';
    for ($i = 0; strlen($trace) < 3 * LogService::MAX_MESSAGE_BYTES; $i++) {
        $trace .= "#$i /var/www/html/vendor/laravel/framework/src/Illuminate/Container/Container.php(1197): build()\n";
    }
    $this->write('laravel.log', "[2026-11-19 10:00:00] local.ERROR: deep {\"exception\":\"[object] (Error(code: 0): deep at /x.php:1)\n[stacktrace]\n{$trace}\"} \n[2026-11-19 10:00:01] local.INFO: after\n");

    $entries = $this->service->getLogEntries('laravel.log')['entries'];

    expect($entries)->toHaveCount(2)
        ->and($entries[1]['message'])->toEndWith(LogService::TRUNCATED)
        ->and(strlen($entries[1]['message']))->toBeLessThanOrEqual(LogService::MAX_MESSAGE_BYTES + strlen(LogService::TRUNCATED))
        ->and($entries[0]['message'])->toBe('after');
});

test('the digest quotes a Laravel error by its message, not its exception JSON', function () {
    $this->write('laravel-2026-11-19.log', logFixture('laravel-2026-11-19.log'));
    $entry = array_reverse($this->service->getLogEntries('laravel-2026-11-19.log')['entries'])[0];

    [$first, $trace] = Digest::split($entry['message']);

    expect($first)->toBe("Unclosed '[' on line 5")
        ->and($trace)->toStartWith('#0 /var/www/html/vendor/laravel/framework/src/Illuminate/Foundation/Bootstrap/RegisterProviders.php(34)')
        ->not->toContain('[stacktrace]')
        ->and(explode("\n", $trace))->toHaveCount(7);

    // Craft 6 logs the calling method as a one-line JSON list; it stays, doing the job Craft 5's category did.
    [$first] = Digest::split('Invalid plugin base path: /x/src ["CraftCms\\\\Cms\\\\Plugin\\\\Plugins::__construct"]');
    expect($first)->toBe('Invalid plugin base path: /x/src ["CraftCms\\\\Cms\\\\Plugin\\\\Plugins::__construct"]');
});

test('4xx HTTP exceptions are recognised in both formats', function () {
    $entry = fn (string $category, string $message) => ['date' => '', 'level' => 'error', 'category' => $category, 'message' => $message];

    expect(Digest::isClientError($entry('yii\web\HttpException:404', 'Template not found')))->toBeTrue()
        ->and(Digest::isClientError($entry('yii\web\HttpException:500', 'boom')))->toBeFalse()
        ->and(Digest::isClientError($entry('local', 'No query results {"exception":"[object] (Symfony\\\\Component\\\\HttpKernel\\\\Exception\\\\NotFoundHttpException(code: 0): x at /y.php:1)')))->toBeTrue()
        ->and(Digest::isClientError($entry('local', 'Forbidden {"exception":"[object] (Symfony\\\\Component\\\\HttpKernel\\\\Exception\\\\AccessDeniedHttpException(code: 0): x at /y.php:1)')))->toBeTrue()
        ->and(Digest::isClientError($entry('local', 'Boom {"exception":"[object] (Symfony\\\\Component\\\\HttpKernel\\\\Exception\\\\HttpException(code: 0): x at /y.php:1)')))->toBeFalse()
        ->and(Digest::isClientError($entry('local', "Unclosed '[' {\"exception\":\"[object] (ParseError(code: 0): x at /y.php:1)")))->toBeFalse();
});

test('a 4xx marker smuggled into an error message does not hide the error', function () {
    $entry = fn (string $message) => ['date' => '', 'level' => 'error', 'category' => 'local', 'message' => $message];
    $fake = '{"exception":"[object] (Symfony\\\\Component\\\\HttpKernel\\\\Exception\\\\NotFoundHttpException(';

    // Request input that reached a real error's message, which Laravel then repeats in its context.
    $spoofed = 'Undefined array key "'.$fake.'" {"exception":"[object] (ErrorException(code: 0): Undefined array key "'.$fake.'" at /y.php:1)';

    expect(Digest::isClientError($entry($spoofed)))->toBeFalse();
});
