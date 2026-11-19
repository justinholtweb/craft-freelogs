<?php

declare(strict_types=1);

namespace JustinHoltWeb\Freelog\Console\Commands;

use CraftCms\Cms\Console\CraftCommand;
use Illuminate\Console\Command;
use JustinHoltWeb\Freelog\Services\Digest;

/**
 * `php artisan freelog:digest:send` — the scheduled error digest.
 *
 * The plugin schedules it every 15 minutes on Laravel's scheduler, so a site running
 * `php artisan schedule:run` every minute needs nothing else. It can also go straight in cron.
 * Run it as often as you like; it sends once per period, and only once the configured day and
 * hour have arrived.
 *
 * Exits 0 for every outcome that is not a fault — not due yet, already sent, nothing new — so cron
 * stays quiet. Exits 78 when the digest has nowhere to go, and 1 when the send failed (the next
 * run will try again, with the same entries).
 */
class DigestSendCommand extends Command
{
    use CraftCommand;

    /** What Freelog 5's `freelog/digest/send` returned for "no recipients" (Yii's `ExitCode::CONFIG`). */
    public const int EXIT_CONFIG = 78;

    #[\Override]
    protected $signature = 'freelog:digest:send {--force : Send now, whatever the schedule says and even if this period’s digest has gone}';

    #[\Override]
    protected $description = 'Sends the Freelog error digest if it is due.';

    /** `freelog/digest` was the Craft 5 controller's default action. */
    #[\Override]
    protected $aliases = ['freelog/digest/send', 'freelog/digest'];

    public function handle(Digest $digest): int
    {
        $result = $digest->run(null, (bool) $this->option('force'));

        [$message, $style, $code] = match ($result) {
            Digest::RESULT_SENT => ['Digest sent.', 'info', self::SUCCESS],
            Digest::RESULT_NOTHING_NEW => ['No new log errors since the last digest, so none was sent.', 'comment', self::SUCCESS],
            Digest::RESULT_NOT_DUE => ['Not due yet.', 'comment', self::SUCCESS],
            Digest::RESULT_ALREADY_SENT => ['This period’s digest has already gone.', 'comment', self::SUCCESS],
            Digest::RESULT_DISABLED => ['The error digest is switched off in Freelog’s settings.', 'comment', self::SUCCESS],
            Digest::RESULT_CANCELLED => ['An event handler cancelled the digest.', 'warn', self::SUCCESS],
            Digest::RESULT_NO_RECIPIENTS => ['The digest has no valid recipients. Add some in Freelog’s settings.', 'error', self::EXIT_CONFIG],
            default => ['The digest could not be sent; see the log. The next run will try again.', 'error', self::FAILURE],
        };

        match ($style) {
            'info' => $this->info($message),
            'warn' => $this->warn($message),
            'error' => $this->error($message),
            default => $this->line($message, 'comment'),
        };

        return $code;
    }
}
