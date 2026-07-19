<?php

namespace justinholtweb\freelog\tests;

use PHPUnit\Framework\TestCase;

class LogServiceTest extends TestCase
{
    private string $logsDir;
    private TestableLogService $service;

    protected function setUp(): void
    {
        $this->logsDir = sys_get_temp_dir() . '/freelog-test-' . uniqid('', true);
        mkdir($this->logsDir, 0777, true);
        $this->service = new TestableLogService($this->logsDir);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->logsDir)) {
            $this->rrmdir($this->logsDir);
        }
    }

    private function rrmdir(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function write(string $name, string $contents, ?int $mtime = null): string
    {
        $path = $this->logsDir . '/' . $name;
        file_put_contents($path, $contents);
        if ($mtime !== null) {
            touch($path, $mtime);
        }
        return $path;
    }

    // ---------------------------------------------------------------------
    // formatBytes
    // ---------------------------------------------------------------------

    public function testFormatBytes(): void
    {
        self::assertSame('0 B', $this->service->formatBytes(0));
        self::assertSame('512 B', $this->service->formatBytes(512));
        self::assertSame('1023 B', $this->service->formatBytes(1023));
        self::assertSame('1 KB', $this->service->formatBytes(1024));
        self::assertSame('1.5 KB', $this->service->formatBytes(1536));
        self::assertSame('1 MB', $this->service->formatBytes(1024 * 1024));
        self::assertSame('1 GB', $this->service->formatBytes(1024 ** 3));
        // Caps at GB (largest unit).
        self::assertSame('1024 GB', $this->service->formatBytes(1024 ** 4));
    }

    // ---------------------------------------------------------------------
    // isCompressed
    // ---------------------------------------------------------------------

    public function testIsCompressed(): void
    {
        self::assertFalse($this->service->isCompressed('app.log'));
        self::assertFalse($this->service->isCompressed('app.txt'));
        self::assertTrue($this->service->isCompressed('app.log.gz'));
        self::assertTrue($this->service->isCompressed('app.txt.gz'));
        self::assertTrue($this->service->isCompressed('APP.LOG.GZ'));
    }

    // ---------------------------------------------------------------------
    // getLogFiles
    // ---------------------------------------------------------------------

    public function testGetLogFilesReturnsEmptyWhenDirMissing(): void
    {
        $service = new TestableLogService($this->logsDir . '/does-not-exist');
        self::assertSame([], $service->getLogFiles());
    }

    public function testGetLogFilesFiltersAndFlagsCompression(): void
    {
        $this->write('app.log', 'a', 3000);
        $this->write('notes.txt', 'b', 2000);
        $this->write('rotated.log.1.gz', gzencode('c'), 1000);
        $this->write('dateext.log-20260325.gz', gzencode('d'), 500);
        $this->write('archive.txt.gz', gzencode('e'), 400);
        // Unsupported files that must be ignored.
        $this->write('config.json', '{}');
        $this->write('image.png', 'x');
        mkdir($this->logsDir . '/subdir');

        $files = $this->service->getLogFiles();
        $names = array_column($files, 'name');

        self::assertCount(5, $files);
        self::assertContains('app.log', $names);
        self::assertContains('notes.txt', $names);
        self::assertContains('rotated.log.1.gz', $names);
        self::assertContains('dateext.log-20260325.gz', $names);
        self::assertContains('archive.txt.gz', $names);
        self::assertNotContains('config.json', $names);
        self::assertNotContains('image.png', $names);
        self::assertNotContains('subdir', $names);

        // Sorted newest-first by modified time.
        self::assertSame('app.log', $files[0]['name']);
        self::assertSame('notes.txt', $files[1]['name']);

        // Compressed flag is set correctly.
        $byName = array_column($files, 'compressed', 'name');
        self::assertFalse($byName['app.log']);
        self::assertTrue($byName['rotated.log.1.gz']);
        self::assertTrue($byName['archive.txt.gz']);
    }

    // ---------------------------------------------------------------------
    // getLogEntries — parser formats
    // ---------------------------------------------------------------------

    public function testParsesCraft5Format(): void
    {
        $this->write('c5.log', "2026-04-03 11:52:11 [web.INFO] [application] Hello world\n");
        $result = $this->service->getLogEntries('c5.log');

        self::assertSame(1, $result['total']);
        $entry = $result['entries'][0];
        self::assertSame('2026-04-03 11:52:11', $entry['date']);
        self::assertSame('info', $entry['level']);
        self::assertSame('application', $entry['category']);
        self::assertSame('Hello world', $entry['message']);
    }

    public function testParsesMonologFormatAndNormalizesDate(): void
    {
        $this->write('mono.log', "[2024-01-15T10:30:45+00:00] app.ERROR: Boom\n");
        $entry = $this->service->getLogEntries('mono.log')['entries'][0];

        self::assertSame('2024-01-15 10:30:45', $entry['date']);
        self::assertSame('error', $entry['level']);
        self::assertSame('app', $entry['category']);
        self::assertSame('Boom', $entry['message']);
    }

    public function testParsesYiiFormatWithLowercasedLevel(): void
    {
        $this->write('yii.log', "2024-01-15 10:30:45 [ERROR][application] Something failed\n");
        $entry = $this->service->getLogEntries('yii.log')['entries'][0];

        self::assertSame('error', $entry['level']);
        self::assertSame('application', $entry['category']);
        self::assertSame('Something failed', $entry['message']);
    }

    public function testParsesPlainLevelFormat(): void
    {
        $this->write('formie.log', "2026-04-14 14:15:37 [ERROR] Formie submission failed\n");
        $entry = $this->service->getLogEntries('formie.log')['entries'][0];

        self::assertSame('error', $entry['level']);
        self::assertSame('', $entry['category']);
        self::assertSame('Formie submission failed', $entry['message']);
    }

    public function testParsesBracketedDateFormat(): void
    {
        $this->write('blitz.log', "[2026-04-15 07:33:42] Cache warmed\n");
        $entry = $this->service->getLogEntries('blitz.log')['entries'][0];

        self::assertSame('2026-04-15 07:33:42', $entry['date']);
        self::assertSame('info', $entry['level']);
        self::assertSame('Cache warmed', $entry['message']);
    }

    public function testParsesBareDateFormat(): void
    {
        $this->write('bare.log', "2026-04-10 14:30:42 A bare message\n");
        $entry = $this->service->getLogEntries('bare.log')['entries'][0];

        self::assertSame('2026-04-10 14:30:42', $entry['date']);
        self::assertSame('info', $entry['level']);
        self::assertSame('A bare message', $entry['message']);
    }

    public function testMultiLineEntriesAreJoinedAsContinuation(): void
    {
        $content = "2026-04-03 11:52:11 [web.ERROR] [application] Exception thrown\n"
            . "#0 /path/to/file.php(10): foo()\n"
            . "#1 {main}\n";
        $this->write('trace.log', $content);
        $entry = $this->service->getLogEntries('trace.log')['entries'][0];

        self::assertStringContainsString('Exception thrown', $entry['message']);
        self::assertStringContainsString('#0 /path/to/file.php(10): foo()', $entry['message']);
        self::assertStringContainsString('#1 {main}', $entry['message']);
    }

    public function testEntriesAreReturnedNewestFirst(): void
    {
        $content = "2026-04-01 10:00:00 [web.INFO] [application] first\n"
            . "2026-04-02 10:00:00 [web.INFO] [application] second\n"
            . "2026-04-03 10:00:00 [web.INFO] [application] third\n";
        $this->write('order.log', $content);
        $entries = $this->service->getLogEntries('order.log')['entries'];

        self::assertSame('third', $entries[0]['message']);
        self::assertSame('second', $entries[1]['message']);
        self::assertSame('first', $entries[2]['message']);
    }

    // ---------------------------------------------------------------------
    // getLogEntries — filtering, searching, pagination
    // ---------------------------------------------------------------------

    public function testLevelFilterIsCaseInsensitive(): void
    {
        $content = "2026-04-01 10:00:00 [web.INFO] [application] one\n"
            . "2026-04-02 10:00:00 [web.ERROR] [application] two\n"
            . "2026-04-03 10:00:00 [web.ERROR] [application] three\n";
        $this->write('levels.log', $content);

        $result = $this->service->getLogEntries('levels.log', null, 'ERROR');
        self::assertSame(2, $result['total']);
        foreach ($result['entries'] as $entry) {
            self::assertSame('error', $entry['level']);
        }
    }

    public function testSearchFilterIsCaseInsensitiveOnMessage(): void
    {
        $content = "2026-04-01 10:00:00 [web.INFO] [application] Database connected\n"
            . "2026-04-02 10:00:00 [web.INFO] [application] User logged in\n";
        $this->write('search.log', $content);

        $result = $this->service->getLogEntries('search.log', 'DATABASE');
        self::assertSame(1, $result['total']);
        self::assertSame('Database connected', $result['entries'][0]['message']);
    }

    public function testPaginationSlicesEntriesButReportsFullTotal(): void
    {
        $lines = '';
        for ($i = 1; $i <= 10; $i++) {
            $day = str_pad((string)$i, 2, '0', STR_PAD_LEFT);
            $lines .= "2026-04-$day 10:00:00 [web.INFO] [application] entry $i\n";
        }
        $this->write('page.log', $lines);

        $result = $this->service->getLogEntries('page.log', null, null, 3, 0);
        self::assertSame(10, $result['total']);
        self::assertCount(3, $result['entries']);
        // Newest first: entry 10, 9, 8.
        self::assertSame('entry 10', $result['entries'][0]['message']);

        $page2 = $this->service->getLogEntries('page.log', null, null, 3, 3);
        self::assertCount(3, $page2['entries']);
        self::assertSame('entry 7', $page2['entries'][0]['message']);
    }

    public function testGetLogEntriesForMissingFile(): void
    {
        $result = $this->service->getLogEntries('nope.log');
        self::assertSame(['entries' => [], 'total' => 0], $result);
    }

    public function testGetLogEntriesReadsCompressedFile(): void
    {
        $this->write('gz.log.gz', gzencode("2026-04-03 11:52:11 [web.INFO] [application] From gzip\n"));
        $result = $this->service->getLogEntries('gz.log.gz');

        self::assertSame(1, $result['total']);
        self::assertSame('From gzip', $result['entries'][0]['message']);
    }

    // ---------------------------------------------------------------------
    // getTail
    // ---------------------------------------------------------------------

    public function testGetTailReturnsLastBytesOfPlainFile(): void
    {
        $this->write('tail.log', '0123456789');
        self::assertSame('6789', $this->service->getTail('tail.log', 4));
        self::assertSame('0123456789', $this->service->getTail('tail.log', 100));
    }

    public function testGetTailOfEmptyFile(): void
    {
        $this->write('empty.log', '');
        self::assertSame('', $this->service->getTail('empty.log'));
    }

    public function testGetTailOfCompressedFile(): void
    {
        $this->write('tail.log.gz', gzencode('0123456789'));
        self::assertSame('6789', $this->service->getTail('tail.log.gz', 4));
    }

    public function testGetTailOfMissingFile(): void
    {
        self::assertSame('', $this->service->getTail('missing.log'));
    }

    // ---------------------------------------------------------------------
    // clearLog
    // ---------------------------------------------------------------------

    public function testClearLogEmptiesPlainFile(): void
    {
        $path = $this->write('clear.log', "line one\nline two\n");
        self::assertTrue($this->service->clearLog('clear.log'));
        self::assertSame('', file_get_contents($path));
    }

    public function testClearLogWritesValidEmptyGzip(): void
    {
        $path = $this->write('clear.log.gz', gzencode("2026-04-03 11:52:11 [web.INFO] [application] hi\n"));
        self::assertTrue($this->service->clearLog('clear.log.gz'));

        // File is still valid gzip that decodes to an empty string.
        $raw = file_get_contents($path);
        self::assertNotSame('', $raw);
        self::assertSame('', gzdecode($raw));

        // And the service reads it back cleanly.
        self::assertSame('', $this->service->getTail('clear.log.gz'));
        self::assertSame(0, $this->service->getLogEntries('clear.log.gz')['total']);
    }

    public function testClearLogReturnsFalseForMissingFile(): void
    {
        self::assertFalse($this->service->clearLog('missing.log'));
    }

    // ---------------------------------------------------------------------
    // resolveFilePath — security
    // ---------------------------------------------------------------------

    public function testResolveFilePathReturnsRealPathForValidFile(): void
    {
        $path = $this->write('valid.log', 'x');
        self::assertSame(realpath($path), $this->service->resolveFilePath('valid.log'));
    }

    public function testResolveFilePathReturnsNullForMissingFile(): void
    {
        self::assertNull($this->service->resolveFilePath('missing.log'));
    }

    public function testResolveFilePathRejectsDirectoryTraversal(): void
    {
        // Create a secret outside the logs dir.
        $secret = $this->logsDir . '/../secret.log';
        file_put_contents($secret, 'top secret');

        // basename() strips the traversal, so it resolves inside the logs
        // dir (where no such file exists) and returns null.
        self::assertNull($this->service->resolveFilePath('../secret.log'));
        self::assertNull($this->service->resolveFilePath('../../etc/passwd'));

        unlink($secret);
    }

    public function testResolveFilePathRejectsDirectories(): void
    {
        mkdir($this->logsDir . '/adir.log');
        self::assertNull($this->service->resolveFilePath('adir.log'));
    }
}
