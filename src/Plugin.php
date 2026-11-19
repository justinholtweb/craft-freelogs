<?php

declare(strict_types=1);

namespace JustinHoltWeb\Freelog;

use CraftCms\Cms\Cms;
use CraftCms\Cms\Cp\Data\NavItem;
use CraftCms\Cms\Plugin\Plugin as BasePlugin;
use CraftCms\Cms\Support\Facades\Plugins;
use CraftCms\Cms\Ui\Controls\Choice;
use CraftCms\Cms\Ui\Controls\Lightswitch;
use CraftCms\Cms\Ui\Controls\Text;
use CraftCms\Cms\Ui\Enums\ChoicePresentation;
use CraftCms\Cms\Ui\Nodes\Field;
use CraftCms\Cms\Ui\Nodes\Heading;
use CraftCms\Cms\Ui\Nodes\MarkdownContent;
use CraftCms\Cms\Ui\Ui;
use CraftCms\Cms\Ui\UiContext;
use CraftCms\Cms\User\Data\Permission;
use DateTimeImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;
use JustinHoltWeb\Freelog\Console\Commands\DigestSendCommand;
use JustinHoltWeb\Freelog\Console\Commands\DigestStatusCommand;
use JustinHoltWeb\Freelog\Models\Settings;
use JustinHoltWeb\Freelog\Services\Digest;
use JustinHoltWeb\Freelog\Services\LogService;
use Override;
use Throwable;

use function CraftCms\Cms\cp_url;
use function CraftCms\Cms\currentUser;
use function CraftCms\Cms\t;

/**
 * Freelog — view, search, filter and download the logs in `storage/logs`, and email a redacted
 * digest of new errors.
 *
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    /** Kept from 1.x/5.x so existing grants still work; it means view and download only. */
    public const string PERMISSION_VIEW = 'freelog:access';

    public const string PERMISSION_CLEAR = 'freelog:clear';

    public string $schemaVersion = '1.1.0';

    /**
     * Sites upgrade from the last Freelog 5 release, so the `freelog_digests` table is in place
     * and `database/migrations/Install.php` can describe the final schema.
     */
    public string $minVersionRequired = '5.1.0';

    public bool $hasCpSection = true;

    public bool $hasCpSettings = true;

    /** The log viewer's row expansion, level filter, tail and clear confirmation. Idle elsewhere. */
    protected array $scripts = [
        __DIR__.'/../resources/js/freelog.js' => 'js/freelog.js',
    ];

    protected array $styles = [
        __DIR__.'/../resources/css/freelog.css' => 'css/freelog.css',
    ];

    protected array $commands = [
        DigestSendCommand::class,
        DigestStatusCommand::class,
    ];

    #[Override]
    protected static function createSettings(): Settings
    {
        return new Settings;
    }

    public function register(): void
    {
        parent::register();

        $this->app->singleton(LogService::class);
        $this->app->singleton(Digest::class, fn ($app) => new Digest($app->make(LogService::class)));
    }

    public function boot(): void
    {
        $this->app->bind(Settings::class, fn (): Settings => $this->getSettings());

        if (! $this->app->runningInConsole()) {
            $this->registerDigestFallback();
        }
    }

    /**
     * The digest on Laravel's scheduler: every 15 minutes, a cheap no-op until it is due. A site
     * running `php artisan schedule:run` every minute needs no cron entry of Freelog's own.
     */
    #[Override]
    protected function schedule(Schedule $schedule): void
    {
        $schedule->command(DigestSendCommand::class)
            ->everyFifteenMinutes()
            ->withoutOverlapping(30)
            ->when(fn (): bool => $this->getSettings()->digestEnabled);
    }

    /**
     * The digest's fallback trigger, for sites where nothing runs the scheduler.
     *
     * After the response has gone (`terminating`), not during it. What runs is a cache read: the
     * schedule is consulted at most every five minutes and the work happens in a queue job.
     */
    private function registerDigestFallback(): void
    {
        $settings = $this->getSettings();

        if (! $settings->digestEnabled || ! $settings->digestWebTrigger) {
            return;
        }

        $this->app->terminating(function (): void {
            try {
                // Before the migration that adds the marker table has run, there is nowhere to
                // read the schedule from.
                if (! Cms::isInstalled() || Plugins::isPluginUpdatePending($this)) {
                    return;
                }

                $this->app->make(Digest::class)->queueIfDue();
            } catch (Throwable $e) {
                Log::warning('Freelog: could not check the digest schedule: '.$e->getMessage());
            }
        });
    }

    /**
     * Reading and clearing are separate. Logs hold request parameters, email addresses and stack
     * traces, so viewing them is close to admin-grade on its own — and clearing one erases the
     * record of what happened, which is a different trust.
     */
    #[Override]
    protected function getPermissions(): array
    {
        return [
            new Permission(
                key: self::PERMISSION_VIEW,
                label: t('View logs', category: 'freelog'),
                warning: t('Logs can contain request data, email addresses and stack traces.', category: 'freelog'),
                nested: collect([
                    new Permission(
                        key: self::PERMISSION_CLEAR,
                        label: t('Clear logs', category: 'freelog'),
                    ),
                ]),
            ),
        ];
    }

    #[Override]
    public function getCpNavItem(): NavItem|array|null
    {
        $item = parent::getCpNavItem();

        if (! $item instanceof NavItem) {
            return $item;
        }

        $item->label(t('Freelog', category: 'freelog'));

        // The digest and the settings are admin-only (they choose who receives log contents by
        // email).
        if (currentUser()?->isAdmin()) {
            $item->subnav([
                new NavItem()->label(t('Logs', category: 'freelog'))->href('freelog'),
                new NavItem()->label(t('Error digest', category: 'freelog'))->href('freelog/digest'),
                new NavItem()->label(t('Settings', category: 'freelog'))->href('settings/plugins/freelog'),
            ]);
        }

        return $item;
    }

    #[Override]
    public function settingsUi(UiContext $context = new UiContext): Ui
    {
        $weekdays = [];

        foreach (range(1, 7) as $day) {
            // 2024-01-01 was a Monday, so day N of that week is ISO weekday N.
            $weekdays[] = ['label' => new DateTimeImmutable("2024-01-0{$day}")->format('l'), 'value' => $day];
        }

        $hours = [];

        foreach (range(0, 23) as $hour) {
            $hours[] = ['label' => sprintf('%02d:00', $hour), 'value' => $hour];
        }

        $levels = array_map(
            fn (string $level) => ['label' => t(ucfirst($level), category: 'freelog'), 'value' => $level],
            Settings::LEVELS,
        );

        return Ui::make([
            Heading::make('freelog-digest', t('Error digest', category: 'freelog'))
                ->description(t('Once a day or once a week, email the errors written to the logs since the last digest, so a broken page is noticed without anybody opening Freelog to look.', category: 'freelog')),
            MarkdownContent::make('freelog-digest-test', t('Send a test, and see when the next digest is due, under [Freelog → Error digest]({url}).', ['url' => cp_url('freelog/digest')], category: 'freelog')),
            Field::make(t('Email an error digest', category: 'freelog'), Lightswitch::make('digestEnabled')),
            Field::make(t('How often', category: 'freelog'), Choice::make('digestFrequency')->options([
                ['label' => t('Daily', category: 'freelog'), 'value' => Settings::DIGEST_DAILY],
                ['label' => t('Weekly', category: 'freelog'), 'value' => Settings::DIGEST_WEEKLY],
            ])),
            Field::make(t('Day of the week', category: 'freelog'), Choice::make('digestWeekday')->options($weekdays))
                ->instructions(t('For a weekly digest.', category: 'freelog')),
            Field::make(t('Hour', category: 'freelog'), Choice::make('digestHour')->options($hours))
                ->instructions(t('In the system time zone ({zone}). The digest goes out on the first run at or after this hour, once per day or week.', ['zone' => Cms::systemTimezone()], category: 'freelog')),
            Field::make(t('Recipients', category: 'freelog'), Text::make('digestRecipients')->placeholder('ops@example.com, $FREELOG_DIGEST_RECIPIENTS'))
                ->instructions(t('Email addresses, separated by commas, or an environment variable that holds them (`$FREELOG_DIGEST_RECIPIENTS`). The digest quotes log lines — redacted, but still from inside the site — so send it to the people who would read the logs anyway.', category: 'freelog')),
            Field::make(t('Levels to report', category: 'freelog'), Choice::make('digestLevels')->multiple()->presentation(ChoicePresentation::Checkboxes)->options($levels))
                ->instructions(t('Nothing ticked means emergency, alert, critical and error.', category: 'freelog')),
            Field::make(t('Leave out 4xx HTTP errors', category: 'freelog'), Lightswitch::make('digestIgnoreClientErrors'))
                ->instructions(t('Craft 6 doesn’t log not-found, forbidden or bad requests by default, so this mostly applies to Craft 5 logs still in `storage/logs` after an upgrade, where every one of them was an error. It also leaves them out if your exception handler reports them.', category: 'freelog')),
            Field::make(t('Include stack traces', category: 'freelog'), Lightswitch::make('digestIncludeTraces'))
                ->instructions(t('Off, each error is quoted by its first line only. On, the first few lines of its trace go in too — redacted, but traces carry server paths and function arguments, posted values among them.', category: 'freelog')),
            Field::make(t('Send even when there is nothing new', category: 'freelog'), Lightswitch::make('digestSendWhenEmpty'))
                ->instructions(t('Off, a period with no new errors sends nothing. An “all clear” every morning is an email people learn to stop opening.', category: 'freelog')),
            Field::make(t('Check from web requests too', category: 'freelog'), Lightswitch::make('digestWebTrigger'))
                ->instructions(t('Freelog checks every 15 minutes from Laravel’s scheduler, which needs `* * * * * php artisan schedule:run` in cron. Without that, leave this on: after a web request the schedule is looked at, at most every five minutes, and the digest is queued when it is due.', category: 'freelog')),
        ]);
    }
}
