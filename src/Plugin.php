<?php

namespace justinholtweb\freelog;

use Craft;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\helpers\UrlHelper;
use craft\services\UserPermissions;
use craft\web\Application as WebApplication;
use craft\web\UrlManager;
use justinholtweb\freelog\models\Settings;
use justinholtweb\freelog\services\Digest;
use justinholtweb\freelog\services\LogService;
use Throwable;
use yii\base\Event;

/**
 * Freelog plugin for Craft CMS 5.
 *
 * @property-read LogService $logService
 * @property-read Digest $digest
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    /** Kept from 1.x/5.0 so existing grants still work; it now means view and download only. */
    public const PERMISSION_VIEW = 'freelog:access';
    public const PERMISSION_CLEAR = 'freelog:clear';

    public const LOG_CATEGORY = 'freelog';

    public string $schemaVersion = '1.1.0';
    public bool $hasCpSection = true;
    public bool $hasCpSettings = true;

    /**
     * @return array<string, mixed>
     */
    public static function config(): array
    {
        return [
            'components' => [
                'logService' => LogService::class,
                'digest' => Digest::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function(RegisterUrlRulesEvent $event) {
                $event->rules['freelog'] = 'freelog/logs/index';
                $event->rules['freelog/view'] = 'freelog/logs/view';
                $event->rules['freelog/download'] = 'freelog/logs/download';
                $event->rules['freelog/clear'] = 'freelog/logs/clear';
                $event->rules['freelog/tail'] = 'freelog/logs/tail';
                $event->rules['freelog/settings'] = 'freelog/settings/index';
            },
        );

        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            function(RegisterUserPermissionsEvent $event) {
                // Reading and clearing are separate. Logs hold request parameters, email addresses
                // and stack traces, so viewing them is close to admin-grade on its own — and
                // clearing one erases the record of what happened, which is a different trust.
                // Until 5.0.5 the one permission did both.
                $event->permissions[] = [
                    'heading' => Craft::t('freelog', 'Freelog'),
                    'permissions' => [
                        self::PERMISSION_VIEW => [
                            'label' => Craft::t('freelog', 'View logs'),
                            'warning' => Craft::t('freelog', 'Logs can contain request data, email addresses and stack traces.'),
                            'nested' => [
                                self::PERMISSION_CLEAR => [
                                    'label' => Craft::t('freelog', 'Clear logs'),
                                ],
                            ],
                        ],
                    ],
                ];
            },
        );

        if (!Craft::$app->getRequest()->getIsConsoleRequest()) {
            $this->registerDigestFallback();
        }
    }

    /**
     * The digest's fallback trigger, for sites with no cron job running `freelog/digest/send`.
     *
     * At the end of a web request rather than on `Gc::EVENT_RUN`: garbage collection runs on a
     * dice roll, which makes for a schedule nobody can predict. What runs here is a cache read;
     * the schedule is consulted at most every five minutes and the work happens in a queue job.
     */
    private function registerDigestFallback(): void
    {
        $settings = $this->getSettings();

        if (!$settings->digestEnabled || !$settings->digestWebTrigger) {
            return;
        }

        Event::on(WebApplication::class, WebApplication::EVENT_AFTER_REQUEST, function() {
            try {
                // Before the migration that adds the marker table has run, there is nowhere to
                // read the schedule from.
                if (!Craft::$app->getIsInstalled() || Craft::$app->getPlugins()->isPluginUpdatePending($this)) {
                    return;
                }

                $this->digest->queueIfDue();
            } catch (Throwable $e) {
                Craft::warning('Could not check the digest schedule: ' . $e->getMessage(), self::LOG_CATEGORY);
            }
        });
    }

    /**
     * The plugin instance, for code that only runs once it is loaded (services, controllers, jobs).
     */
    public static function current(): self
    {
        $plugin = self::getInstance();

        if (!$plugin instanceof self) {
            throw new \RuntimeException('Freelog is not loaded.');
        }

        return $plugin;
    }

    protected function createSettingsModel(): Settings
    {
        return new Settings();
    }

    public function getSettingsResponse(): mixed
    {
        /** @var \craft\web\Response $response */
        $response = Craft::$app->getResponse();

        return $response->redirect(UrlHelper::cpUrl('freelog/settings'));
    }

    /**
     * Returns the log service.
     */
    public function getLogService(): LogService
    {
        /** @var LogService $service */
        $service = $this->get('logService');

        return $service;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getCpNavItem(): ?array
    {
        $navItem = parent::getCpNavItem();

        if ($navItem === null) {
            return null;
        }

        $navItem['label'] = Craft::t('freelog', 'Freelog');

        // Settings are admin-only (they choose who receives log contents by email).
        if (Craft::$app->getUser()->getIsAdmin()) {
            $navItem['subnav'] = [
                'logs' => ['label' => Craft::t('freelog', 'Logs'), 'url' => 'freelog'],
                'settings' => ['label' => Craft::t('freelog', 'Settings'), 'url' => 'freelog/settings'],
            ];
        }

        return $navItem;
    }
}
