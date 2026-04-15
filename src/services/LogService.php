<?php

namespace justinholtweb\freelog\services;

use Craft;
use craft\base\Component;
use DateTime;
use DirectoryIterator;
use Throwable;

/**
 * Log service.
 */
class LogService extends Component
{
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
     * @return array<int, array{name: string, size: int, modified: int}>
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
     * Reads and parses a log file, returning structured entries.
     *
     * @return array{entries: array, total: int}
     */
    public function getLogEntries(
        string $filename,
        ?string $search = null,
        ?string $level = null,
        int $limit = 50,
        int $offset = 0,
    ): array {
        $filepath = $this->resolveFilePath($filename);

        if ($filepath === null) {
            return ['entries' => [], 'total' => 0];
        }

        $content = $this->_readFileContent($filepath);

        if ($content === null) {
            return ['entries' => [], 'total' => 0];
        }

        $entries = $this->_parseLogContent($content);

        if ($level !== null && $level !== '') {
            $entries = array_filter($entries, fn($entry) => strcasecmp($entry['level'], $level) === 0);
        }

        if ($search !== null && $search !== '') {
            $searchLower = mb_strtolower($search);
            $entries = array_filter(
                $entries,
                fn($entry) => str_contains(mb_strtolower($entry['message']), $searchLower),
            );
        }

        $entries = array_values($entries);
        $total = count($entries);
        $entries = array_slice($entries, $offset, $limit);

        return ['entries' => $entries, 'total' => $total];
    }

    /**
     * Returns the tail of a log file (last N bytes).
     */
    public function getTail(string $filename, int $bytes = 8192): string
    {
        $filepath = $this->resolveFilePath($filename);

        if ($filepath === null) {
            return '';
        }

        if ($this->isCompressed($filename)) {
            // gzip has no cheap seek-to-end; decompress in full and slice.
            $content = $this->_readFileContent($filepath);

            if ($content === null || $content === '') {
                return '';
            }

            return substr($content, -$bytes);
        }

        $size = filesize($filepath);

        if ($size === 0) {
            return '';
        }

        $handle = fopen($filepath, 'r');

        if ($handle === false) {
            return '';
        }

        $readBytes = min($bytes, $size);
        fseek($handle, -$readBytes, SEEK_END);
        $content = fread($handle, $readBytes);
        fclose($handle);

        return $content ?: '';
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
     */
    public function resolveFilePath(string $filename): ?string
    {
        $filename = basename($filename);
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
     * Reads a log file into a string, transparently decompressing gzip.
     */
    private function _readFileContent(string $filepath): ?string
    {
        if ($this->isCompressed($filepath)) {
            $raw = file_get_contents($filepath);

            if ($raw === false || $raw === '') {
                return $raw === '' ? '' : null;
            }

            $decoded = @gzdecode($raw);

            return $decoded === false ? null : $decoded;
        }

        $content = file_get_contents($filepath);

        return $content === false ? null : $content;
    }

    /**
     * Parses log content into structured entries.
     *
     * Supports:
     * - Craft 5 default: 2026-04-03 11:52:11 [channel.LEVEL] [category] message
     * - Monolog:         [2024-01-15T10:30:45+00:00] channel.LEVEL: message
     * - Craft 3/4 Yii:   2024-01-15 10:30:45 [level][category] message
     * - Plain level:     2026-04-14 14:15:37 [LEVEL] message (e.g. Formie)
     * - Bracketed date:  [2026-04-15 07:33:42] message (e.g. Blitz)
     * - Bare date:       2026-04-10 14:30:42 message
     *
     * @return array<int, array{date: string, level: string, category: string, message: string}>
     */
    private function _parseLogContent(string $content): array
    {
        $entries = [];
        $lines = explode("\n", $content);
        $currentEntry = null;

        // Craft 5 format: 2026-04-03 11:52:11 [channel.LEVEL] [category] message
        $craft5Pattern = '/^(\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}:\d{2})\s\[(\S+?)\.(\w+)\]\s\[([^\]]*)\]\s?(.*)/';

        // Monolog format: [2024-01-15T10:30:45+00:00] channel.LEVEL: message
        $monologPattern = '/^\[(\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}[^\]]*)\]\s+(\S+?)\.(\w+):\s?(.*)/';

        // Craft 3/4 Yii format: 2024-01-15 10:30:45 [level][category] message
        $yiiPattern = '/^(\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}:\d{2})\s\[(\w+)\]\[([^\]]*)\]\s?(.*)/';

        // Plain level format: 2026-04-14 14:15:37 [LEVEL] message
        $plainLevelPattern = '/^(\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}:\d{2})\s\[(\w+)\]\s?(.*)/';

        // Bracketed date format: [2026-04-15 07:33:42] message
        $bracketedDatePattern = '/^\[(\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}:\d{2})\]\s?(.*)/';

        // Bare date format: 2026-04-10 14:30:42 message
        $bareDatePattern = '/^(\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}:\d{2})\s(.*)/';

        foreach ($lines as $line) {
            if (preg_match($craft5Pattern, $line, $matches)) {
                if ($currentEntry !== null) {
                    $entries[] = $currentEntry;
                }
                $currentEntry = [
                    'date' => $matches[1],
                    'level' => strtolower($matches[3]),
                    'category' => $matches[4],
                    'message' => $matches[5],
                ];
            } elseif (preg_match($monologPattern, $line, $matches)) {
                if ($currentEntry !== null) {
                    $entries[] = $currentEntry;
                }
                $date = $matches[1];
                try {
                    $date = (new DateTime($date))->format('Y-m-d H:i:s');
                } catch (Throwable) {
                }
                $currentEntry = [
                    'date' => $date,
                    'level' => strtolower($matches[3]),
                    'category' => $matches[2],
                    'message' => $matches[4],
                ];
            } elseif (preg_match($yiiPattern, $line, $matches)) {
                if ($currentEntry !== null) {
                    $entries[] = $currentEntry;
                }
                $currentEntry = [
                    'date' => $matches[1],
                    'level' => $matches[2],
                    'category' => $matches[3],
                    'message' => $matches[4],
                ];
            } elseif (preg_match($plainLevelPattern, $line, $matches)) {
                if ($currentEntry !== null) {
                    $entries[] = $currentEntry;
                }
                $currentEntry = [
                    'date' => $matches[1],
                    'level' => strtolower($matches[2]),
                    'category' => '',
                    'message' => $matches[3],
                ];
            } elseif (preg_match($bracketedDatePattern, $line, $matches)) {
                if ($currentEntry !== null) {
                    $entries[] = $currentEntry;
                }
                $currentEntry = [
                    'date' => $matches[1],
                    'level' => 'info',
                    'category' => '',
                    'message' => $matches[2],
                ];
            } elseif (preg_match($bareDatePattern, $line, $matches)) {
                if ($currentEntry !== null) {
                    $entries[] = $currentEntry;
                }
                $currentEntry = [
                    'date' => $matches[1],
                    'level' => 'info',
                    'category' => '',
                    'message' => $matches[2],
                ];
            } elseif ($currentEntry !== null && trim($line) !== '') {
                // Continuation of previous entry (stack trace, etc.)
                $currentEntry['message'] .= "\n" . $line;
            }
        }

        if ($currentEntry !== null) {
            $entries[] = $currentEntry;
        }

        return array_reverse($entries);
    }
}
