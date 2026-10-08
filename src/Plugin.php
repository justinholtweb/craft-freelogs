<?php

namespace justinholtweb\freelog;

use Craft;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\UserPermissions;
use craft\web\UrlManager;
use justinholtweb\freelog\services\LogService;
use yii\base\Event;

/**
 * Freelog plugin for Craft CMS 5.
 *
 * @property-read LogService $logService
 */
class Plugin extends BasePlugin
{
    /** Kept from 1.x/5.0 so existing grants still work; it now means view and download only. */
    public const PERMISSION_VIEW = 'freelog:access';
    public const PERMISSION_CLEAR = 'freelog:clear';

    public string $schemaVersion = '1.0.0';
    public bool $hasCpSection = true;
    public bool $hasCpSettings = false;

    /**
     * @return array<string, mixed>
     */
    public static function config(): array
    {
        return [
            'components' => [
                'logService' => LogService::class,
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

        return $navItem;
    }
}
