<?php

declare(strict_types=1);

namespace JustinHoltWeb\Freelog\Tests\Support;

use JustinHoltWeb\Freelog\Services\LogService;

/**
 * A LogService pointed at a fixtures directory instead of `storage/logs`, so the filesystem-facing
 * methods run without booting Craft.
 */
class TestableLogService extends LogService
{
    public function __construct(
        private readonly string $logsPath,
    ) {}

    public function getLogsPath(): string
    {
        return $this->logsPath;
    }
}
