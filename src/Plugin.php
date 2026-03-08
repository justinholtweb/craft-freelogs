<?php

namespace justinholtweb\freelog;

use Craft;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\UserPermissions;
use craft\web\UrlManager;
use yii\base\Event;

/**
 * Freelog plugin for Craft CMS 5
 *
 * @property services\LogService $logService
 */
class Plugin extends BasePlugin
{
    public string $schemaVersion = '1.0.0';
    public bool $hasCpSection = true;
    public bool $hasCpSettings = false;

    public static function config(): array
    {
        return [
            'components' => [
                'logService' => services\LogService::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function (RegisterUrlRulesEvent $event) {
                $event->rules['freelog'] = 'freelog/logs/index';
                $event->rules['freelog/view'] = 'freelog/logs/view';
                $event->rules['freelog/download'] = 'freelog/logs/download';
                $event->rules['freelog/clear'] = 'freelog/logs/clear';
                $event->rules['freelog/tail'] = 'freelog/logs/tail';
            }
        );

        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            function (RegisterUserPermissionsEvent $event) {
                $event->permissions[] = [
                    'heading' => 'Freelog',
                    'permissions' => [
                        'freelog:access' => [
                            'label' => 'Access Freelog',
                        ],
                    ],
                ];
            }
        );
    }

    public function getCpNavItem(): ?array
    {
        $navItem = parent::getCpNavItem();
        $navItem['label'] = 'Freelog';
        return $navItem;
    }
}
