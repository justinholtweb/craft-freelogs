<?php

namespace justinholtweb\freelog\tests;

use justinholtweb\freelog\services\LogService;

/**
 * A LogService whose logs directory can be pointed at a test fixtures
 * directory, so the filesystem-facing methods can be exercised without a
 * running Craft application.
 */
class TestableLogService extends LogService
{
    public function __construct(
        private readonly string $logsPath,
        array $config = [],
    ) {
        parent::__construct($config);
    }

    public function getLogsPath(): string
    {
        return $this->logsPath;
    }
}
