<?php

declare(strict_types=1);

namespace JustinHoltWeb\Freelog\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * A fresh, empty logs directory per test, and a LogService reading it.
 */
abstract class LogsTestCase extends TestCase
{
    public string $logsDir;

    protected TestableLogService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logsDir = sys_get_temp_dir().'/freelog-test-'.uniqid('', true);
        mkdir($this->logsDir, 0777, true);
        $this->service = new TestableLogService($this->logsDir);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->logsDir)) {
            $this->rrmdir($this->logsDir);
        }

        parent::tearDown();
    }

    protected function rrmdir(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.'/'.$item;
            is_dir($path) && ! is_link($path) ? $this->rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }

    public function service(): TestableLogService
    {
        return $this->service;
    }

    public function write(string $name, string $contents, ?int $mtime = null): string
    {
        $path = $this->logsDir.'/'.$name;
        file_put_contents($path, $contents);
        if ($mtime !== null) {
            touch($path, $mtime);
        }

        return $path;
    }

    /** Craft 5 entries, one second apart, messages "entry 0" … "entry N-1". */
    public function writeEntries(string $name, int $count, string $level = 'INFO'): void
    {
        $lines = '';
        for ($i = 0; $i < $count; $i++) {
            $lines .= date('Y-m-d H:i:s', 1767225600 + $i)." [web.$level] [application] entry $i\n";
        }
        $this->write($name, $lines);
    }
}
