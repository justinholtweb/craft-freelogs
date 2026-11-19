<?php

declare(strict_types=1);

use JustinHoltWeb\Freelog\Models\Settings;
use JustinHoltWeb\Freelog\Services\Digest;
use JustinHoltWeb\Freelog\Tests\Support\LogsTestCase;

/*
 * The digest's reader: a byte position per file, carried from one run to the next, through
 * Laravel's daily files, Craft 5's logs and a cleared file. collect() needs no database — the
 * positions it returns are what run() would store.
 */

uses(LogsTestCase::class);

function digest(LogsTestCase $test, array $settings = []): Digest
{
    return new Digest($test->service(), new Settings($settings));
}

function laravelLine(string $time, string $level, string $message): string
{
    return "[2026-11-19 $time] local.$level: $message\n";
}

test('nothing is reported twice, and what is appended is reported once', function () {
    $this->write('laravel-2026-11-19.log', laravelLine('08:00:00', 'ERROR', 'first').laravelLine('08:00:01', 'INFO', 'noise'));
    $digest = digest($this);

    $first = $digest->collect([]);
    expect($first['total'])->toBe(1);

    $again = $digest->collect($first['offsets']);
    expect($again['total'])->toBe(0);

    file_put_contents($this->logsDir.'/laravel-2026-11-19.log', laravelLine('09:00:00', 'CRITICAL', 'second'), FILE_APPEND);
    $third = $digest->collect($again['offsets']);

    expect($third['total'])->toBe(1)
        ->and(array_column($third['groups'], 'message'))->toBe(['second'])
        ->and($digest->collect($third['offsets'])['total'])->toBe(0);
});

test('Laravel daily rotation: a new day is read from the top, a deleted day drops out', function () {
    $this->write('laravel-2026-11-18.log', laravelLine('23:59:00', 'ERROR', 'yesterday'));
    $digest = digest($this);
    $positions = $digest->collect([])['offsets'];

    unlink($this->logsDir.'/laravel-2026-11-18.log');
    $this->write('laravel-2026-11-19.log', laravelLine('00:00:05', 'ERROR', 'today'));

    $scan = $digest->collect($positions);

    expect(array_column($scan['groups'], 'message'))->toBe(['today'])
        ->and(array_column($scan['offsets'], 'file'))->toBe(['laravel-2026-11-19.log']);
});

test('a reused inode does not carry yesterday’s position to today’s file', function () {
    $path = $this->write('laravel-2026-11-19.log', str_repeat(laravelLine('00:00:01', 'ERROR', 'today'), 3));
    $stat = stat($path);
    $key = 'i:'.$stat['dev'].':'.$stat['ino'];

    // As if laravel-2026-11-18.log had had this inode and been read 40 bytes in.
    $scan = digest($this)->collect([$key => ['file' => 'laravel-2026-11-18.log', 'offset' => 40, 'head' => null]]);

    expect($scan['total'])->toBe(3);
});

test('a log renamed by rotation keeps its position', function () {
    $path = $this->write('web.log', "2026-10-09 08:00:00 [web.ERROR] [application] old\n");
    $digest = digest($this);
    $positions = $digest->collect([])['offsets'];

    rename($path, $this->logsDir.'/web.log.1');
    $this->write('web.log', "2026-10-09 09:00:00 [web.ERROR] [application] new\n");

    expect(array_column($digest->collect($positions)['groups'], 'message'))->toBe(['new']);
});

test('a cleared log starts again from the top', function () {
    $this->write('laravel.log', str_repeat(laravelLine('08:00:00', 'ERROR', 'before'), 5));
    $digest = digest($this);
    $positions = $digest->collect([])['offsets'];

    $this->write('laravel.log', laravelLine('09:00:00', 'ERROR', 'after'));

    expect(array_column($digest->collect($positions)['groups'], 'message'))->toBe(['after']);
});

test('the first digest covers one period back and skips logs nobody wrote to since', function () {
    $this->write('laravel-2026-11-18.log', laravelLine('07:00:00', 'ERROR', 'too old').laravelLine('09:00:00', 'ERROR', 'recent'));
    $this->write('laravel-2026-10-01.log', laravelLine('09:00:00', 'ERROR', 'last month'), strtotime('2026-10-01 10:00:00 UTC'));

    $scan = digest($this)->collect(null, new DateTimeImmutable('2026-11-19 08:00:00'));

    expect(array_column($scan['groups'], 'message'))->toBe(['recent']);
});

test('Craft 5 logs kept after an upgrade are read alongside, 4xx left out by default', function () {
    $this->write('web-2026-11-08.log', logFixture('web-2026-11-08.log'));
    $this->write('laravel-2026-11-19.log', logFixture('laravel-2026-11-19.log'));

    $scan = digest($this)->collect([]);
    $categories = array_column($scan['groups'], 'category');
    sort($categories);

    // Laravel: two errors and an emergency. Craft 5: the template error; the 403 is left out.
    expect($scan['total'])->toBe(4)
        ->and($categories)->toBe(['craft\web\twig\TemplateLoaderException', 'laravel', 'local', 'local']);

    $all = digest($this, ['digestIgnoreClientErrors' => false])->collect([]);
    expect($all['total'])->toBe(5);
});

test('levels to report come from the settings', function () {
    $this->write('laravel-2026-11-19.log', logFixture('laravel-2026-11-19.log'));

    expect(digest($this, ['digestLevels' => ['warning']])->collect([])['total'])->toBe(1)
        ->and(digest($this, ['digestLevels' => []])->collect([])['total'])->toBe(3);
});
