<?php

namespace justinholtweb\freelog\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\freelog\Plugin;
use Throwable;
use yii\web\Response;

/**
 * "Send a test digest now".
 *
 * POST only, CSRF-checked by Craft, and admin-only: it emails log contents, redacted or not, to
 * the digest's recipients — the same people only an admin can choose.
 */
class DigestController extends Controller
{
    /** One test per user per this many seconds. */
    public const TEST_COOLDOWN = 30;

    public function actionSendTest(): ?Response
    {
        $this->requireCpRequest();
        $this->requirePostRequest();
        // `false`: settings are read-only without admin changes, but a test send changes nothing.
        $this->requireAdmin(false);

        $plugin = Plugin::current();
        $user = Craft::$app->getUser()->getIdentity();

        // To the digest's own list, so the test shows exactly who the real one reaches. A site
        // that has not set one up yet gets it sent to the admin pressing the button.
        $recipients = $plugin->getSettings()->recipientList();

        if ($recipients === [] && $user?->email) {
            $recipients = [$user->email];
        }

        if ($recipients === []) {
            $this->setFailFlash(Craft::t('freelog', 'The digest has no recipients to send a test to.'));

            return $this->redirectToPostedUrl();
        }

        $cache = Craft::$app->getCache();

        if ($cache !== null && !$cache->add('freelog:digest:test:' . ($user->id ?? 0), 1, self::TEST_COOLDOWN)) {
            $this->setFailFlash(Craft::t('freelog', 'A test digest was sent a moment ago. Give it half a minute.'));

            return $this->redirectToPostedUrl();
        }

        try {
            $sent = $plugin->digest->sendTest($recipients);
        } catch (Throwable $e) {
            Craft::warning('Could not send a test digest: ' . $e->getMessage(), Plugin::LOG_CATEGORY);
            $sent = 0;
        }

        if ($sent === 0) {
            $this->setFailFlash(Craft::t('freelog', 'The test digest could not be sent. Check the email settings and the web log.'));
        } else {
            $this->setSuccessFlash(Craft::t('freelog', 'Test digest sent to {count, plural, =1{one recipient} other{# recipients}}.', [
                'count' => $sent,
            ]));
        }

        return $this->redirectToPostedUrl();
    }
}
