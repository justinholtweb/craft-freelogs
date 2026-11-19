<?php

declare(strict_types=1);

namespace JustinHoltWeb\Freelog\Jobs;

use CraftCms\Cms\Queue\Job;
use JustinHoltWeb\Freelog\Services\Digest;

use function CraftCms\Cms\t;

/**
 * Sends the error digest if it is due. Queued by the web fallback trigger.
 *
 * Decides nothing itself: {@see Digest::run()} checks the schedule and claims the period, so a
 * job that runs late, twice, or after the scheduler already sent is a no-op.
 */
class SendDigest extends Job
{
    /** Reading a day's or a week's worth of new log lines, then one email per recipient. */
    public int $timeout = 900;

    public function handle(Digest $digest): void
    {
        $digest->run();
    }

    protected function defaultDescription(): string
    {
        return t('Sending the Freelog error digest', category: 'freelog');
    }
}
