<?php

namespace justinholtweb\freelog\controllers;

use Craft;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use justinholtweb\freelog\models\Settings;
use justinholtweb\freelog\Plugin;
use yii\web\Response;

/**
 * The settings screen: the error digest.
 *
 * Its own control panel page rather than the plugin settings modal, because the "Send a test
 * digest now" form has to sit outside the settings form, and Craft's modal wraps everything in one.
 */
class SettingsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();
        // `false`: with admin changes off this screen still opens, read-only, rather than 403ing.
        // Saving is what needs them, and `actionSave()` asks for that itself.
        $this->requireAdmin(false);

        return true;
    }

    public function actionIndex(): Response
    {
        return $this->renderTemplate('freelog/settings/index', array_merge($this->options(), [
            'settings' => Plugin::current()->getSettings(),
            'readOnly' => !Craft::$app->getConfig()->getGeneral()->allowAdminChanges,
        ]));
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $this->requireAdmin();

        $plugin = Plugin::current();
        $posted = $this->request->getBodyParam('settings', []);

        /** @var Settings $settings */
        $settings = $plugin->getSettings();
        $settings->setAttributes(is_array($posted) ? $posted : [], false);

        if (!$settings->validate()) {
            $this->setFailFlash(Craft::t('freelog', 'Couldn’t save settings.'));

            return $this->renderTemplate('freelog/settings/index', array_merge($this->options(), [
                'settings' => $settings,
                'readOnly' => false,
            ]));
        }

        if (!Craft::$app->getPlugins()->savePluginSettings($plugin, $settings->toArray())) {
            $this->setFailFlash(Craft::t('freelog', 'Couldn’t save settings.'));

            return $this->redirect(UrlHelper::cpUrl('freelog/settings'));
        }

        $this->setSuccessFlash(Craft::t('freelog', 'Settings saved.'));

        return $this->redirect(UrlHelper::cpUrl('freelog/settings'));
    }

    /**
     * Everything the settings screen offers a choice from, plus the digest's current state.
     *
     * @return array<string, mixed>
     */
    private function options(): array
    {
        $digest = Plugin::current()->digest;

        $weekdayOptions = [];

        foreach (range(1, 7) as $day) {
            // 2024-01-01 was a Monday, so day N of that week is ISO weekday N.
            $weekdayOptions[] = [
                'label' => Craft::$app->getFormatter()->asDate("2024-01-0$day", 'php:l'),
                'value' => $day,
            ];
        }

        $hourOptions = [];

        foreach (range(0, 23) as $hour) {
            $hourOptions[] = ['label' => sprintf('%02d:00', $hour), 'value' => $hour];
        }

        $levelOptions = [];

        foreach (Settings::LEVELS as $level) {
            $levelOptions[] = ['label' => Craft::t('freelog', ucfirst($level)), 'value' => $level];
        }

        return [
            'weekdayOptions' => $weekdayOptions,
            'hourOptions' => $hourOptions,
            'levelOptions' => $levelOptions,
            'timeZone' => Craft::$app->getTimeZone(),
            'digestState' => $digest->state(),
            'digestNext' => $digest->nextDueAt(),
        ];
    }
}
