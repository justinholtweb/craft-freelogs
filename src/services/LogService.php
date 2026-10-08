<?php

namespace justinholtweb\freelog\services;

use Craft;
use craft\base\Component;
use DateTime;
use DirectoryIterator;
use Generator;
use Throwable;

/**
 * Log service.
 */
class LogService extends Component
{
    /** Bytes read at a time, and the most of one physical line held at once. */
    public const READ_CHUNK = 65536;

    /** A single entry's message is cut here; a dumped array or a runaway trace can be megabytes. */
    public const MAX_MESSAGE_BYTES = 65536;

    /** Appended to a message that was cut. */
    public const TRUNCATED = "\n[… truncated by Freelog — download the file for the rest]";

    /** Up to this many entries (offset + limit) a page is found in one pass; beyond it, two. */
    public const SINGLE_PASS_WINDOW = 2000;

    /**
     * Returns the path to the logs directory.
     */
    public function getLogsPath(): string
    {
        return Craft::getAlias('@storage/logs');
    }

    /**
     * Returns all log files in the logs directory.
     *
     * @return array<int, array{name: string, size: int, modified: int, compressed: bool}>
     */
    public function getLogFiles(): array
    {
        $path = $this->getLogsPath();

        if (!is_dir($path)) {
            return [];
        }

        $files = [];
        $iterator = new DirectoryIterator($path);

        foreach ($iterator as $file) {
            if ($file->isDot() || $file->isDir()) {
                continue;
            }

            $filename = $file->getFilename();

            if (!$this->_isSupportedLogFile($filename)) {
                continue;
            }

            $files[] = [
                'name' => $filename,
                'size' => $file->getSize(),
                'modified' => $file->getMTime(),
                'compressed' => $this->isCompressed($filename),
            ];
        }

        usort($files, fn($a, $b) => $b['modified'] <=> $a['modified']);

        return $files;
    }

    /**
     * Reads and parses a log file, returning one page of entries, newest first.
     *
     * Streamed, never read whole. Until 5.0.5 the file was loaded into memory, split into an array
     * of lines and parsed into an array of every entry — about 5.5× the file's size, so a 300 MB
     * log needed 1.7 GB and fatally exhausted any ordinary memory limit. Now memory follows the
     * page, not the file:
     *
     * - a near page (offset + limit up to {@see SINGLE_PASS_WINDOW}) is one pass that keeps only the
     *   last offset + limit matches in a ring buffer;
     * - a deep page is two passes — count the matches, then collect just the page.
     *
     * @return array{entries: list<array{date: string, level: string, category: string, message: string}>, total: int}
     */
    public function getLogEntries(
        string $filename,
        ?string $search = null,
        ?string $level = null,
        int $limit = 50,
        int $offset = 0,
    ): array {
        $filepath = $this->resolveFilePath($filename);

        if ($filepath === null || $limit < 1) {
            return ['entries' => [], 'total' => 0];
        }

        $offset = max(0, $offset);
        $level = $level !== null && $level !== '' ? $level : null;
        $search = $search !== null && $search !== '' ? mb_strtolower($search) : null;

        $matches = static function(array $entry) use ($level, $search): bool {
            if ($level !== null && strcasecmp($entry['level'], $level) !== 0) {
                return false;
            }

            return $search === null || str_contains(mb_strtolower($entry['message']), $search);
        };

        $window = $offset + $limit;

        if ($window <= self::SINGLE_PASS_WINDOW) {
            // One pass: a ring buffer of the last `$window` matches, in file order.
            $ring = [];
            $total = 0;

            foreach ($this->_entries($filepath) as $entry) {
                if ($matches($entry)) {
                    $ring[$total % $window] = $entry;
                    $total++;
                }
            }

            $newestFirst = [];
            for ($k = $offset; $k < $window && $k < $total; $k++) {
                $newestFirst[] = $ring[($total - 1 - $k) % $window];
            }

            return ['entries' => $newestFirst, 'total' => $total];
        }

        // Two passes: count, then collect file-order positions [total - offset - limit, total - offset).
        $total = 0;
        foreach ($this->_entries($filepath) as $entry) {
            if ($matches($entry)) {
                $total++;
            }
        }

        $from = $total - $offset - $limit;
        $to = $total - $offset;
        $page = [];
        $position = 0;

        if ($to > 0) {
            foreach ($this->_entries($filepath) as $entry) {
                if (!$matches($entry)) {
                    continue;
                }
                if ($position >= $from && $position < $to) {
                    $page[] = $entry;
                }
                if (++$position >= $to) {
                    break;
                }
            }
        }

        return ['entries' => array_reverse($page), 'total' => $total];
    }

    /**
     * Returns the tail of a log file: whole lines from roughly the last N bytes, as valid UTF-8.
     *
     * Cutting at a byte offset lands mid-line and, often, mid-character — and a broken multibyte
     * character makes the JSON response fail outright, which until 5.0.5 is what the live tail did
     * on most real logs. So the cut moves forward to the next line, and anything still invalid
     * (logs do capture binary) is scrubbed.
     */
    public function getTail(string $filename, int $bytes = 8192): string
    {
        return $this->_wholeLines($this->_rawTail($filename, $bytes));
    }

    /**
     * The last N bytes of a log, plus whether that is less than the whole file.
     *
     * @return array{string, bool}
     */
    private function _rawTail(string $filename, int $bytes): array
    {
        $filepath = $this->resolveFilePath($filename);

        if ($filepath === null) {
            return ['', false];
        }

        if ($this->isCompressed($filename)) {
            // gzip has no seek-to-end, so decompress as a stream, keeping only the last N bytes.
            $handle = @gzopen($filepath, 'rb');

            if ($handle === false) {
                return ['', false];
            }

            $tail = '';
            $truncated = false;

            try {
                while (!gzeof($handle)) {
                    $chunk = gzread($handle, self::READ_CHUNK);

                    if ($chunk === false || $chunk === '') {
                        break;
                    }

                    $tail .= $chunk;

                    if (strlen($tail) > $bytes) {
                        $tail = substr($tail, -$bytes);
                        $truncated = true;
                    }
                }
            } finally {
                gzclose($handle);
            }

            return [$tail, $truncated];
        }

        $size = filesize($filepath);

        if ($size === false || $size === 0) {
            return ['', false];
        }

        $handle = fopen($filepath, 'r');

        if ($handle === false) {
            return ['', false];
        }

        $readBytes = min($bytes, $size);

        if ($readBytes < 1) {
            fclose($handle);

            return ['', false];
        }

        fseek($handle, -$readBytes, SEEK_END);
        $content = fread($handle, $readBytes);
        fclose($handle);

        return [$content ?: '', $size > $readBytes];
    }

    /**
     * Drops a partial first line when the tail starts mid-file, and scrubs invalid UTF-8.
     *
     * @param array{string, bool} $tail
     */
    private function _wholeLines(array $tail): string
    {
        [$content, $truncated] = $tail;

        if ($truncated) {
            $newline = strpos($content, "\n");
            // One enormous line longer than the window: keep it rather than show nothing.
            if ($newline !== false && $newline < strlen($content) - 1) {
                $content = substr($content, $newline + 1);
            }
        }

        return mb_scrub($content, 'UTF-8');
    }

    /**
     * Clears a log file.
     */
    public function clearLog(string $filename): bool
    {
        $filepath = $this->resolveFilePath($filename);

        if ($filepath === null) {
            return false;
        }

        // Keep gzipped files valid after clearing so they can still be read.
        $empty = $this->isCompressed($filename) ? (string)gzencode('') : '';

        return file_put_contents($filepath, $empty) !== false;
    }

    /**
     * Returns true if the filename indicates a gzip-compressed log.
     */
    public function isCompressed(string $filename): bool
    {
        return str_ends_with(strtolower($filename), '.gz');
    }

    /**
     * Returns the full file path for a log file, with security checks.
     *
     * Only a file the listing would show resolves: the name is reduced to its basename, must carry
     * a log extension, and must really live in the logs directory — a symlink out of it is refused.
     * Until 5.0.5 the extension check applied to the listing only, so view, download and clear
     * accepted any other file sitting in `storage/logs`.
     */
    public function resolveFilePath(string $filename): ?string
    {
        $filename = basename($filename);

        if (!$this->_isSupportedLogFile($filename)) {
            return null;
        }

        $filepath = $this->getLogsPath() . DIRECTORY_SEPARATOR . $filename;

        if (!file_exists($filepath) || !is_file($filepath)) {
            return null;
        }

        $realPath = realpath($filepath);
        $logsRealPath = realpath($this->getLogsPath());

        if ($realPath === false || $logsRealPath === false) {
            return null;
        }

        if (!str_starts_with($realPath, $logsRealPath . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $realPath;
    }

    /**
     * Formats bytes to a human-readable size.
     */
    public function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $size = (float)$bytes;

        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }

        return round($size, 1) . ' ' . $units[$i];
    }

    /**
     * Returns true if the filename has a supported log extension.
     *
     * Matches plain logs (foo.log, foo.txt) and gzipped rotations
     * produced by logrotate, including numbered (foo.log.1.gz) and
     * dateext (foo.log-20260325.gz) variants.
     */
    private function _isSupportedLogFile(string $filename): bool
    {
        return (bool)preg_match(
            '/\.(log|txt)(\.\d+|-\d+)?(\.gz)?$/i',
            $filename,
        );
    }

    /**
     * A file's lines, streamed, in chunks of at most {@see READ_CHUNK} bytes.
     *
     * Gzip is decompressed as it is read (`gzopen` reads an uncompressed file transparently too).
     * A line longer than a chunk arrives in pieces; every piece after the first is flagged as a
     * continuation, so it is never mistaken for the start of a new entry.
     *
     * @return Generator<int, array{string, bool}> [line without its newline, is a continuation]
     */
    private function _lines(string $filepath): Generator
    {
        $compressed = $this->isCompressed($filepath);
        $handle = $compressed ? @gzopen($filepath, 'rb') : @fopen($filepath, 'rb');

        if ($handle === false) {
            return;
        }

        try {
            $continued = false;

            while (($chunk = $compressed ? gzgets($handle, self::READ_CHUNK) : fgets($handle, self::READ_CHUNK)) !== false) {
                $complete = str_ends_with($chunk, "\n");
                yield [rtrim($chunk, "\r\n"), $continued];
                $continued = !$complete;
            }
        } finally {
            $compressed ? gzclose($handle) : fclose($handle);
        }
    }

    /**
     * A file's entries, streamed, oldest first.
     *
     * A line that starts an entry closes the previous one; anything else is a continuation of it
     * (a stack trace, a dumped array). Blank lines are skipped. A message is capped at
     * {@see MAX_MESSAGE_BYTES} — past that its lines are counted, not kept — so one runaway dump
     * can't take the memory back.
     *
     * @return Generator<int, array{date: string, level: string, category: string, message: string}>
     */
    private function _entries(string $filepath): Generator
    {
        $current = null;
        $full = false;

        foreach ($this->_lines($filepath) as [$line, $continued]) {
            $start = $continued ? null : $this->_parseEntryStart($line);

            if ($start !== null) {
                if ($current !== null) {
                    yield $current;
                }

                $current = $start;
                $full = false;

                if (strlen($current['message']) > self::MAX_MESSAGE_BYTES) {
                    $current['message'] = mb_strcut($current['message'], 0, self::MAX_MESSAGE_BYTES) . self::TRUNCATED;
                    $full = true;
                }

                continue;
            }

            if ($current === null || $full || ($continued ? $line === '' : trim($line) === '')) {
                continue;
            }

            $current['message'] .= ($continued ? '' : "\n") . $line;

            if (strlen($current['message']) > self::MAX_MESSAGE_BYTES) {
                $current['message'] = mb_strcut($current['message'], 0, self::MAX_MESSAGE_BYTES) . self::TRUNCATED;
                $full = true;
            }
        }

        if ($current !== null) {
            yield $current;
        }
    }

    /**
     * Parses the first line of an entry, or returns null for any other line.
     *
     * Supports:
     * - Craft 5 default: 2026-04-03 11:52:11 [channel.LEVEL] [category] message
     * - Monolog:         [2024-01-15T10:30:45+00:00] channel.LEVEL: message
     * - Craft 3/4 Yii:   2024-01-15 10:30:45 [level][category] message
     * - Plain level:     2026-04-14 14:15:37 [LEVEL] message (e.g. Formie)
     * - Bracketed date:  [2026-04-15 07:33:42] message (e.g. Blitz)
     * - Bare date:       2026-04-10 14:30:42 message
     *
     * @return array{date: string, level: string, category: string, message: string}|null
     */
    private function _parseEntryStart(string $line): ?array
    {
        // Every format starts with a digit or a bracket. Checking that first keeps the regexes off
        // the continuation lines — most of the lines in a log with stack traces in it.
        $first = $line[0] ?? '';

        if ($first !== '[' && !ctype_digit($first)) {
            return null;
        }

        // Craft 5 format: 2026-04-03 11:52:11 [channel.LEVEL] [category] message
        if (preg_match('/^(\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}:\d{2})\s\[(\S+?)\.(\w+)\]\s\[([^\]]*)\]\s?(.*)/', $line, $m)) {
            return ['date' => $m[1], 'level' => strtolower($m[3]), 'category' => $m[4], 'message' => $m[5]];
        }

        // Monolog format: [2024-01-15T10:30:45+00:00] channel.LEVEL: message
        if (preg_match('/^\[(\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}[^\]]*)\]\s+(\S+?)\.(\w+):\s?(.*)/', $line, $m)) {
            $date = $m[1];
            try {
                $date = (new DateTime($date))->format('Y-m-d H:i:s');
            } catch (Throwable) {
            }

            return ['date' => $date, 'level' => strtolower($m[3]), 'category' => $m[2], 'message' => $m[4]];
        }

        // Craft 3/4 Yii format: 2024-01-15 10:30:45 [level][category] message
        if (preg_match('/^(\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}:\d{2})\s\[(\w+)\]\[([^\]]*)\]\s?(.*)/', $line, $m)) {
            return ['date' => $m[1], 'level' => strtolower($m[2]), 'category' => $m[3], 'message' => $m[4]];
        }

        // Plain level format: 2026-04-14 14:15:37 [LEVEL] message
        if (preg_match('/^(\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}:\d{2})\s\[(\w+)\]\s?(.*)/', $line, $m)) {
            return ['date' => $m[1], 'level' => strtolower($m[2]), 'category' => '', 'message' => $m[3]];
        }

        // Bracketed date format: [2026-04-15 07:33:42] message
        if (preg_match('/^\[(\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}:\d{2})\]\s?(.*)/', $line, $m)) {
            return ['date' => $m[1], 'level' => 'info', 'category' => '', 'message' => $m[2]];
        }

        // Bare date format: 2026-04-10 14:30:42 message
        if (preg_match('/^(\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}:\d{2})\s(.*)/', $line, $m)) {
            return ['date' => $m[1], 'level' => 'info', 'category' => '', 'message' => $m[2]];
        }

        return null;
    }
}
