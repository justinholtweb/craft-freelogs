<?php

namespace justinholtweb\freelog\jobs;

use Craft;
use craft\queue\BaseJob;
use justinholtweb\freelog\Plugin;

/**
 * Sends the error digest if it is due. Queued by the web fallback trigger.
 *
 * Decides nothing itself: {@see \justinholtweb\freelog\services\Digest::run()} checks the schedule
 * and claims the period, so a job that runs late, twice, or after cron already sent is a no-op.
 */
class SendDigest extends BaseJob
{
    public function execute($queue): void
    {
        Plugin::current()->digest->run();
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('freelog', 'Sending the Freelog error digest');
    }

    public function getTtr(): int
    {
        // Reading a day's or a week's worth of new log lines, then one email per recipient.
        return 900;
    }
}
