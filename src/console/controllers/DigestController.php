<?php

namespace justinholtweb\freelog\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\freelog\Plugin;
use justinholtweb\freelog\services\Digest;
use yii\console\ExitCode;

/**
 * `php craft freelog/digest/send` — the scheduled error digest, for cron.
 *
 * Run it as often as you like; it sends once per period, and only once the configured day and
 * hour have arrived:
 *
 *     0,15,30,45 * * * * php /path/to/craft freelog/digest/send
 *
 * Exits 0 for every outcome that is not a fault — not due yet, already sent, nothing new — so cron
 * stays quiet. Exits non-zero when the digest has nowhere to go, or when the send failed (the next
 * run will try again, with the same entries).
 */
class DigestController extends Controller
{
    public $defaultAction = 'send';

    /** Send now, whatever the schedule says and even if this period's digest has gone. */
    public bool $force = false;

    public function options($actionID): array
    {
        $options = parent::options($actionID);

        if ($actionID === 'send') {
            $options[] = 'force';
        }

        return $options;
    }

    public function actionSend(): int
    {
        $result = Plugin::current()->digest->run(null, $this->force);

        [$message, $colour, $code] = match ($result) {
            Digest::RESULT_SENT => ['Digest sent.', Console::FG_GREEN, ExitCode::OK],
            Digest::RESULT_NOTHING_NEW => ['No new log errors since the last digest, so none was sent.', Console::FG_GREY, ExitCode::OK],
            Digest::RESULT_NOT_DUE => ['Not due yet.', Console::FG_GREY, ExitCode::OK],
            Digest::RESULT_ALREADY_SENT => ['This period’s digest has already gone.', Console::FG_GREY, ExitCode::OK],
            Digest::RESULT_DISABLED => ['The error digest is switched off in Freelog’s settings.', Console::FG_GREY, ExitCode::OK],
            Digest::RESULT_CANCELLED => ['An event handler cancelled the digest.', Console::FG_YELLOW, ExitCode::OK],
            Digest::RESULT_NO_RECIPIENTS => ['The digest has no valid recipients. Add some in Freelog’s settings.', Console::FG_RED, ExitCode::CONFIG],
            default => ['The digest could not be sent; see the web or console log. The next run will try again.', Console::FG_RED, ExitCode::UNSPECIFIED_ERROR],
        };

        $this->stdout($message . "\n", $colour);

        return $code;
    }

    /** What the schedule says: last run, last send, next due, and how many logs are tracked. */
    public function actionStatus(): int
    {
        $plugin = Plugin::current();
        $digest = $plugin->digest;
        $settings = $plugin->getSettings();
        $state = $digest->state();
        $next = $digest->nextDueAt();
        $format = fn(?\DateTimeInterface $date) => $date?->format('Y-m-d H:i T') ?? '—';

        $this->stdout(str_pad('Enabled', 18) . ($settings->digestEnabled ? 'yes' : 'no') . "\n");
        $this->stdout(str_pad('Schedule', 18) . $settings->digestFrequency . "\n");
        $this->stdout(str_pad('Levels', 18) . implode(', ', $settings->levelList()) . "\n");
        $this->stdout(str_pad('Recipients', 18) . count($settings->recipientList()) . "\n");
        $this->stdout(str_pad('Logs tracked', 18) . ($state['offsets'] === null ? '— (first digest covers one period back)' : count($state['offsets'])) . "\n");
        $this->stdout(str_pad('Last period', 18) . ($state['period'] ?? '—') . "\n");
        $this->stdout(str_pad('Last run', 18) . $format($state['lastRunAt']) . ' ' . ($state['lastResult'] ?? '') . "\n");
        $this->stdout(str_pad('Last sent', 18) . $format($state['lastSentAt']) . ($state['lastSentAt'] ? " ({$state['lastCount']} entries)" : '') . "\n");
        $this->stdout(str_pad('Next due', 18) . $format($next) . "\n");

        return ExitCode::OK;
    }
}
