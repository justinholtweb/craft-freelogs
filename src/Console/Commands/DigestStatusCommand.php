<?php

declare(strict_types=1);

namespace JustinHoltWeb\Freelog\Console\Commands;

use CraftCms\Cms\Console\CraftCommand;
use DateTimeInterface;
use Illuminate\Console\Command;
use JustinHoltWeb\Freelog\Models\Settings;
use JustinHoltWeb\Freelog\Services\Digest;

/**
 * `php artisan freelog:digest:status` — what the schedule says: last run, last send, next due,
 * and how many logs are tracked.
 */
class DigestStatusCommand extends Command
{
    use CraftCommand;

    #[\Override]
    protected $signature = 'freelog:digest:status';

    #[\Override]
    protected $description = 'Shows the Freelog error digest’s schedule and last run.';

    #[\Override]
    protected $aliases = ['freelog/digest/status'];

    public function handle(Digest $digest, Settings $settings): int
    {
        $state = $digest->state();
        $format = fn (?DateTimeInterface $date) => $date?->format('Y-m-d H:i T') ?? '—';

        $rows = [
            'Enabled' => $settings->digestEnabled ? 'yes' : 'no',
            'Schedule' => $settings->digestFrequency,
            'Levels' => implode(', ', $settings->levelList()),
            'Recipients' => (string) count($settings->recipientList()),
            'Logs tracked' => $state['offsets'] === null ? '— (first digest covers one period back)' : (string) count($state['offsets']),
            'Last period' => $state['period'] ?? '—',
            'Last run' => trim($format($state['lastRunAt']).' '.($state['lastResult'] ?? '')),
            'Last sent' => $format($state['lastSentAt']).($state['lastSentAt'] ? " ({$state['lastCount']} entries)" : ''),
            'Next due' => $format($digest->nextDueAt()),
        ];

        foreach ($rows as $label => $value) {
            $this->components->twoColumnDetail($label, $value);
        }

        return self::SUCCESS;
    }
}
