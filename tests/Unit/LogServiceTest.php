<?php

declare(strict_types=1);

use JustinHoltWeb\Freelog\Services\LogService;
use JustinHoltWeb\Freelog\Tests\Support\LogsTestCase;
use JustinHoltWeb\Freelog\Tests\Support\TestableLogService;

/*
 * The log reader: listing, parsing, paging, tailing, clearing and keeping every request inside
 * storage/logs. Ported from Freelog 5's PHPUnit suite unchanged in substance.
 */

uses(LogsTestCase::class);

test('format bytes', function () {
    $this->assertSame('0 B', $this->service->formatBytes(0));
    $this->assertSame('512 B', $this->service->formatBytes(512));
    $this->assertSame('1023 B', $this->service->formatBytes(1023));
    $this->assertSame('1 KB', $this->service->formatBytes(1024));
    $this->assertSame('1.5 KB', $this->service->formatBytes(1536));
    $this->assertSame('1 MB', $this->service->formatBytes(1024 * 1024));
    $this->assertSame('1 GB', $this->service->formatBytes(1024 ** 3));
    // Caps at GB (largest unit).
    $this->assertSame('1024 GB', $this->service->formatBytes(1024 ** 4));
});

test('is compressed', function () {
    $this->assertFalse($this->service->isCompressed('app.log'));
    $this->assertFalse($this->service->isCompressed('app.txt'));
    $this->assertTrue($this->service->isCompressed('app.log.gz'));
    $this->assertTrue($this->service->isCompressed('app.txt.gz'));
    $this->assertTrue($this->service->isCompressed('APP.LOG.GZ'));
});

test('get log files returns empty when dir missing', function () {
    $service = new TestableLogService($this->logsDir.'/does-not-exist');
    $this->assertSame([], $service->getLogFiles());
});

test('get log files filters and flags compression', function () {
    $this->write('app.log', 'a', 3000);
    $this->write('notes.txt', 'b', 2000);
    $this->write('rotated.log.1.gz', gzencode('c'), 1000);
    $this->write('dateext.log-20260325.gz', gzencode('d'), 500);
    $this->write('archive.txt.gz', gzencode('e'), 400);
    // Unsupported files that must be ignored.
    $this->write('config.json', '{}');
    $this->write('image.png', 'x');
    mkdir($this->logsDir.'/subdir');

    $files = $this->service->getLogFiles();
    $names = array_column($files, 'name');

    $this->assertCount(5, $files);
    $this->assertContains('app.log', $names);
    $this->assertContains('notes.txt', $names);
    $this->assertContains('rotated.log.1.gz', $names);
    $this->assertContains('dateext.log-20260325.gz', $names);
    $this->assertContains('archive.txt.gz', $names);
    $this->assertNotContains('config.json', $names);
    $this->assertNotContains('image.png', $names);
    $this->assertNotContains('subdir', $names);

    // Sorted newest-first by modified time.
    $this->assertSame('app.log', $files[0]['name']);
    $this->assertSame('notes.txt', $files[1]['name']);

    // Compressed flag is set correctly.
    $byName = array_column($files, 'compressed', 'name');
    $this->assertFalse($byName['app.log']);
    $this->assertTrue($byName['rotated.log.1.gz']);
    $this->assertTrue($byName['archive.txt.gz']);
});

test('parses Craft 5 format', function () {
    $this->write('c5.log', "2026-04-03 11:52:11 [web.INFO] [application] Hello world\n");
    $result = $this->service->getLogEntries('c5.log');

    $this->assertSame(1, $result['total']);
    $entry = $result['entries'][0];
    $this->assertSame('2026-04-03 11:52:11', $entry['date']);
    $this->assertSame('info', $entry['level']);
    $this->assertSame('application', $entry['category']);
    $this->assertSame('Hello world', $entry['message']);
});

test('parses monolog format and normalizes date', function () {
    $this->write('mono.log', "[2024-01-15T10:30:45+00:00] app.ERROR: Boom\n");
    $entry = $this->service->getLogEntries('mono.log')['entries'][0];

    $this->assertSame('2024-01-15 10:30:45', $entry['date']);
    $this->assertSame('error', $entry['level']);
    $this->assertSame('app', $entry['category']);
    $this->assertSame('Boom', $entry['message']);
});

test('parses Yii format with lowercased level', function () {
    $this->write('yii.log', "2024-01-15 10:30:45 [ERROR][application] Something failed\n");
    $entry = $this->service->getLogEntries('yii.log')['entries'][0];

    $this->assertSame('error', $entry['level']);
    $this->assertSame('application', $entry['category']);
    $this->assertSame('Something failed', $entry['message']);
});

test('parses plain level format', function () {
    $this->write('formie.log', "2026-04-14 14:15:37 [ERROR] Formie submission failed\n");
    $entry = $this->service->getLogEntries('formie.log')['entries'][0];

    $this->assertSame('error', $entry['level']);
    $this->assertSame('', $entry['category']);
    $this->assertSame('Formie submission failed', $entry['message']);
});

test('parses bracketed date format', function () {
    $this->write('blitz.log', "[2026-04-15 07:33:42] Cache warmed\n");
    $entry = $this->service->getLogEntries('blitz.log')['entries'][0];

    $this->assertSame('2026-04-15 07:33:42', $entry['date']);
    $this->assertSame('info', $entry['level']);
    $this->assertSame('Cache warmed', $entry['message']);
});

test('parses bare date format', function () {
    $this->write('bare.log', "2026-04-10 14:30:42 A bare message\n");
    $entry = $this->service->getLogEntries('bare.log')['entries'][0];

    $this->assertSame('2026-04-10 14:30:42', $entry['date']);
    $this->assertSame('info', $entry['level']);
    $this->assertSame('A bare message', $entry['message']);
});

test('multi line entries are joined as continuation', function () {
    $content = "2026-04-03 11:52:11 [web.ERROR] [application] Exception thrown\n"
        ."#0 /path/to/file.php(10): foo()\n"
        ."#1 {main}\n";
    $this->write('trace.log', $content);
    $entry = $this->service->getLogEntries('trace.log')['entries'][0];

    $this->assertStringContainsString('Exception thrown', $entry['message']);
    $this->assertStringContainsString('#0 /path/to/file.php(10): foo()', $entry['message']);
    $this->assertStringContainsString('#1 {main}', $entry['message']);
});

test('entries are returned newest first', function () {
    $content = "2026-04-01 10:00:00 [web.INFO] [application] first\n"
        ."2026-04-02 10:00:00 [web.INFO] [application] second\n"
        ."2026-04-03 10:00:00 [web.INFO] [application] third\n";
    $this->write('order.log', $content);
    $entries = $this->service->getLogEntries('order.log')['entries'];

    $this->assertSame('third', $entries[0]['message']);
    $this->assertSame('second', $entries[1]['message']);
    $this->assertSame('first', $entries[2]['message']);
});

test('level filter is case insensitive', function () {
    $content = "2026-04-01 10:00:00 [web.INFO] [application] one\n"
        ."2026-04-02 10:00:00 [web.ERROR] [application] two\n"
        ."2026-04-03 10:00:00 [web.ERROR] [application] three\n";
    $this->write('levels.log', $content);

    $result = $this->service->getLogEntries('levels.log', null, 'ERROR');
    $this->assertSame(2, $result['total']);
    foreach ($result['entries'] as $entry) {
        $this->assertSame('error', $entry['level']);
    }
});

test('search filter is case insensitive on message', function () {
    $content = "2026-04-01 10:00:00 [web.INFO] [application] Database connected\n"
        ."2026-04-02 10:00:00 [web.INFO] [application] User logged in\n";
    $this->write('search.log', $content);

    $result = $this->service->getLogEntries('search.log', 'DATABASE');
    $this->assertSame(1, $result['total']);
    $this->assertSame('Database connected', $result['entries'][0]['message']);
});

test('pagination slices entries but reports full total', function () {
    $lines = '';
    for ($i = 1; $i <= 10; $i++) {
        $day = str_pad((string) $i, 2, '0', STR_PAD_LEFT);
        $lines .= "2026-04-$day 10:00:00 [web.INFO] [application] entry $i\n";
    }
    $this->write('page.log', $lines);

    $result = $this->service->getLogEntries('page.log', null, null, 3, 0);
    $this->assertSame(10, $result['total']);
    $this->assertCount(3, $result['entries']);
    // Newest first: entry 10, 9, 8.
    $this->assertSame('entry 10', $result['entries'][0]['message']);

    $page2 = $this->service->getLogEntries('page.log', null, null, 3, 3);
    $this->assertCount(3, $page2['entries']);
    $this->assertSame('entry 7', $page2['entries'][0]['message']);
});

test('get log entries for missing file', function () {
    $result = $this->service->getLogEntries('nope.log');
    $this->assertSame(['entries' => [], 'total' => 0], $result);
});

test('get log entries reads compressed file', function () {
    $this->write('gz.log.gz', gzencode("2026-04-03 11:52:11 [web.INFO] [application] From gzip\n"));
    $result = $this->service->getLogEntries('gz.log.gz');

    $this->assertSame(1, $result['total']);
    $this->assertSame('From gzip', $result['entries'][0]['message']);
});

test('get tail returns last bytes of plain file', function () {
    $this->write('tail.log', '0123456789');
    $this->assertSame('6789', $this->service->getTail('tail.log', 4));
    $this->assertSame('0123456789', $this->service->getTail('tail.log', 100));
});

test('get tail starts at a whole line', function () {
    $this->write('lines.log', "first line\nsecond line\nthird line\n");
    // 16 bytes reaches back into "second line"; the partial line is dropped.
    $this->assertSame("third line\n", $this->service->getTail('lines.log', 16));
});

test('get tail never returns a broken character', function () {
    // "é" is two bytes; a byte-offset cut through it made the JSON response fail.
    $this->write('utf8.log', "café crème\ncafé crème\n");
    foreach (range(1, 22) as $bytes) {
        $tail = $this->service->getTail('utf8.log', $bytes);
        $this->assertTrue(mb_check_encoding($tail, 'UTF-8'), "cut at $bytes bytes");
        $this->assertNotFalse(json_encode($tail), "cut at $bytes bytes");
    }
});

test('get tail scrubs invalid bytes from the log', function () {
    $this->write('binary.log', "ok\n\xff\xfe binary\n");
    $this->assertTrue(mb_check_encoding($this->service->getTail('binary.log'), 'UTF-8'));
});

test('get tail of empty file', function () {
    $this->write('empty.log', '');
    $this->assertSame('', $this->service->getTail('empty.log'));
});

test('get tail of compressed file', function () {
    $this->write('tail.log.gz', gzencode('0123456789'));
    $this->assertSame('6789', $this->service->getTail('tail.log.gz', 4));
});

test('get tail of missing file', function () {
    $this->assertSame('', $this->service->getTail('missing.log'));
});

test('clear log empties plain file', function () {
    $path = $this->write('clear.log', "line one\nline two\n");
    $this->assertTrue($this->service->clearLog('clear.log'));
    $this->assertSame('', file_get_contents($path));
});

test('clear log writes valid empty gzip', function () {
    $path = $this->write('clear.log.gz', gzencode("2026-04-03 11:52:11 [web.INFO] [application] hi\n"));
    $this->assertTrue($this->service->clearLog('clear.log.gz'));

    // File is still valid gzip that decodes to an empty string.
    $raw = file_get_contents($path);
    $this->assertNotSame('', $raw);
    $this->assertSame('', gzdecode($raw));

    // And the service reads it back cleanly.
    $this->assertSame('', $this->service->getTail('clear.log.gz'));
    $this->assertSame(0, $this->service->getLogEntries('clear.log.gz')['total']);
});

test('clear log returns false for missing file', function () {
    $this->assertFalse($this->service->clearLog('missing.log'));
});

test('resolve file path returns real path for valid file', function () {
    $path = $this->write('valid.log', 'x');
    $this->assertSame(realpath($path), $this->service->resolveFilePath('valid.log'));
});

test('resolve file path returns null for missing file', function () {
    $this->assertNull($this->service->resolveFilePath('missing.log'));
});

test('resolve file path rejects directory traversal', function () {
    // Create a secret outside the logs dir.
    $secret = $this->logsDir.'/../secret.log';
    file_put_contents($secret, 'top secret');

    // basename() strips the traversal, so it resolves inside the logs
    // dir (where no such file exists) and returns null.
    $this->assertNull($this->service->resolveFilePath('../secret.log'));
    $this->assertNull($this->service->resolveFilePath('../../etc/passwd'));

    unlink($secret);
});

test('resolve file path rejects directories', function () {
    mkdir($this->logsDir.'/adir.log');
    $this->assertNull($this->service->resolveFilePath('adir.log'));
});

test('resolve file path rejects files without a log extension', function () {
    // Anything else living in the logs dir — a dump, a config backup, a .gitignore — is not a
    // log, and view, download and clear must not reach it. Until 5.0.5 only the listing
    // filtered on the extension.
    $this->write('.gitignore', '*');
    $this->write('dump.sql', 'DROP TABLE x;');
    $this->write('web.log.bak', 'x');

    $this->assertNull($this->service->resolveFilePath('.gitignore'));
    $this->assertNull($this->service->resolveFilePath('dump.sql'));
    $this->assertNull($this->service->resolveFilePath('web.log.bak'));
});

test('resolve file path still accepts rotated and compressed logs', function () {
    foreach (['web.log', 'notes.txt', 'web.log.1', 'web.log.2.gz', 'web.log-20260325.gz'] as $name) {
        $this->write($name, 'x');
        $this->assertNotNull($this->service->resolveFilePath($name), $name);
    }
});

test('clear log refuses files without a log extension', function () {
    $path = $this->write('keep.json', '{"keep":true}');

    $this->assertFalse($this->service->clearLog('keep.json'));
    $this->assertSame('{"keep":true}', file_get_contents($path));
});

test('resolve file path rejects a symlink out of the logs dir', function () {
    $outside = $this->logsDir.'-outside.log';
    file_put_contents($outside, 'not a log of ours');
    symlink($outside, $this->logsDir.'/escape.log');

    try {
        $this->assertNull($this->service->resolveFilePath('escape.log'));
        $this->assertFalse($this->service->clearLog('escape.log'));
        $this->assertSame('not a log of ours', file_get_contents($outside));
    } finally {
        unlink($this->logsDir.'/escape.log');
        unlink($outside);
    }
});

test('near and deep pages agree across the pass boundary', function () {
    // More entries than the single-pass window, so the last pages go through the two-pass path.
    $count = LogService::SINGLE_PASS_WINDOW + 500;
    $this->writeEntries('many.log', $count);

    foreach ([0, 1950, 1999, 2000, 2001, 2450, $count - 1, $count] as $offset) {
        $result = $this->service->getLogEntries('many.log', null, null, 50, $offset);
        $expected = [];
        for ($k = $offset; $k < min($offset + 50, $count); $k++) {
            $expected[] = 'entry '.($count - 1 - $k);
        }

        $this->assertSame($count, $result['total'], "offset $offset");
        $this->assertSame($expected, array_column($result['entries'], 'message'), "offset $offset");
    }
});

test('deep page with a filter counts only matches', function () {
    $count = LogService::SINGLE_PASS_WINDOW * 2;
    $this->writeEntries('filtered.log', $count);

    // "entry 1…" matches 1, 10-19, 100-199, 1000-1999 → 1111 entries.
    $result = $this->service->getLogEntries('filtered.log', 'entry 1', null, 10, 1105);

    $this->assertSame(1111, $result['total']);
    // Offset 1105 of 1111 leaves the six oldest matches, newest first.
    $this->assertSame(['entry 14', 'entry 13', 'entry 12', 'entry 11', 'entry 10', 'entry 1'], array_column($result['entries'], 'message'));
});

test('a runaway message is capped not held whole', function () {
    $this->write('dump.log', "2026-04-03 11:52:11 [web.ERROR] [application] dump\n"
        .str_repeat(str_repeat('y', 1000)."\n", 500)
        ."2026-04-03 11:52:12 [web.INFO] [application] after\n");

    $result = $this->service->getLogEntries('dump.log');

    $this->assertSame(2, $result['total']);
    $this->assertSame('after', $result['entries'][0]['message']);
    $this->assertLessThanOrEqual(LogService::MAX_MESSAGE_BYTES + strlen(LogService::TRUNCATED), strlen($result['entries'][1]['message']));
    $this->assertStringEndsWith(LogService::TRUNCATED, $result['entries'][1]['message']);
});

test('an overlong line is not mistaken for a new entry', function () {
    // A single physical line longer than a read chunk arrives in pieces; a piece that happens
    // to begin like a timestamp is still part of the same line.
    $padding = str_repeat('z', LogService::READ_CHUNK - 1 - strlen('2026-04-03 11:52:11 [web.INFO] [application] '));
    $this->write('long.log', '2026-04-03 11:52:11 [web.INFO] [application] '.$padding
        ."2026-04-03 11:52:12 [web.ERROR] [application] not a new entry\n");

    $result = $this->service->getLogEntries('long.log');

    $this->assertSame(1, $result['total']);
    $this->assertSame('info', $result['entries'][0]['level']);
});

test('compressed tail is streamed', function () {
    $this->writeEntries('rotated.log', 5000);
    $this->write('rotated.log.1.gz', gzencode((string) file_get_contents($this->logsDir.'/rotated.log')));

    $tail = $this->service->getTail('rotated.log.1.gz', 200);

    $this->assertStringEndsWith("entry 4999\n", $tail);
    $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} /', $tail, 'starts at a whole line');
    $this->assertLessThanOrEqual(200, strlen($tail));
});

test('memory follows the page not the file', function () {
    // ~30 MB of log. Reading it whole — as 5.0.4 and earlier did — costs at least the file's
    // size again in memory; streaming costs about a page. 8 MB of headroom tells them apart.
    $handle = fopen($this->logsDir.'/large.log', 'w');
    $trace = str_repeat("#0 /var/www/html/vendor/yiisoft/yii2/base/Module.php(552): runAction()\n", 4);
    for ($i = 0; ftell($handle) < 30 * 1024 * 1024; $i++) {
        fwrite($handle, '2026-01-01 00:00:00 [web.INFO] [application] request '.$i.' '.str_repeat('.', 80)."\n".($i % 5 === 0 ? $trace : ''));
    }
    fclose($handle);
    file_put_contents($this->logsDir.'/large.log.1.gz', gzencode((string) file_get_contents($this->logsDir.'/large.log'), 1));
    gc_collect_cycles();

    $calls = [
        'first page' => fn () => $this->service->getLogEntries('large.log'),
        'deep page (two passes)' => fn () => $this->service->getLogEntries('large.log', null, null, 50, 100000),
        'search' => fn () => $this->service->getLogEntries('large.log', 'request 12345 '),
        'compressed page' => fn () => $this->service->getLogEntries('large.log.1.gz'),
        'compressed tail' => fn () => $this->service->getTail('large.log.1.gz'),
    ];

    foreach ($calls as $label => $call) {
        $before = memory_get_usage();
        memory_reset_peak_usage();
        $call();
        $grew = memory_get_peak_usage() - $before;

        $this->assertLessThan(8 * 1024 * 1024, $grew, sprintf('%s grew memory by %.1f MB', $label, $grew / 1048576));
    }
});

test('entries since reads only what follows the position', function () {
    $first = "2026-10-09 08:00:00 [web.ERROR] [application] one\n#0 trace line\n";
    $this->write('web.log', $first."2026-10-09 08:01:00 [web.ERROR] [application] two\n");

    $all = $this->service->entriesSince('web.log', 0);
    $messages = array_column(iterator_to_array($all, false), 'message');
    $this->assertSame(["one\n#0 trace line", 'two'], $messages);
    $end = $all->getReturn();
    $this->assertSame(filesize($this->logsDir.'/web.log'), $end);

    $rest = $this->service->entriesSince('web.log', strlen($first));
    $this->assertSame(['two'], array_column(iterator_to_array($rest, false), 'message'));

    $none = $this->service->entriesSince('web.log', $end);
    $this->assertSame([], iterator_to_array($none, false));
    $this->assertSame($end, $none->getReturn());
});

test('entries since leaves a line still being written', function () {
    $whole = "2026-10-09 08:00:00 [web.ERROR] [application] whole\n";
    $this->write('web.log', $whole.'2026-10-09 08:00:01 [web.ERROR] [application] half-writ');

    $entries = $this->service->entriesSince('web.log', 0);
    $this->assertSame(['whole'], array_column(iterator_to_array($entries, false), 'message'));
    $this->assertSame(strlen($whole), $entries->getReturn(), 'The unfinished line is read next time, whole.');
});

test('entries since refuses compressed and unlisted files', function () {
    $this->write('web.log.1.gz', (string) gzencode("2026-10-09 08:00:00 [web.ERROR] [application] old\n"));
    $this->write('secrets.bak', "2026-10-09 08:00:00 [web.ERROR] [application] nope\n");

    foreach (['web.log.1.gz', 'secrets.bak', '../web.log'] as $name) {
        $entries = $this->service->entriesSince($name, 0);
        $this->assertSame([], iterator_to_array($entries, false), $name);
        $this->assertSame(0, $entries->getReturn(), $name);
    }
});

test('rotated names', function () {
    $this->assertFalse($this->service->isRotated('web.log'));
    $this->assertFalse($this->service->isRotated('web-2026-10-09.log'));
    $this->assertFalse($this->service->isRotated('formie.txt'));
    $this->assertTrue($this->service->isRotated('web.log.1'));
    $this->assertTrue($this->service->isRotated('web.log-20261009'));
    $this->assertTrue($this->service->isRotated('web.log.2.gz'));
});
