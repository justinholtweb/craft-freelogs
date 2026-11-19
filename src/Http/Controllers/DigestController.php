<?php

declare(strict_types=1);

namespace JustinHoltWeb\Freelog\Http\Controllers;

use CraftCms\Cms\Cms;
use CraftCms\Cms\Cp\Data\ActionItem;
use CraftCms\Cms\Http\RespondsWithFlash;
use CraftCms\Cms\Http\Responses\CpScreenResponse;
use CraftCms\Cms\Support\Url;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use JustinHoltWeb\Freelog\Models\Settings;
use JustinHoltWeb\Freelog\Services\Digest;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

use function CraftCms\Cms\currentUserElement;
use function CraftCms\Cms\t;

/**
 * The error digest's status screen and "Send a test digest now".
 *
 * Admin-only (see `routes/cp.php`): the test emails log contents, redacted or not, to the
 * digest's recipients — the same people only an admin can choose. The settings themselves are on
 * the plugin's standard settings screen.
 */
class DigestController
{
    use RespondsWithFlash;

    /** One test per user per this many seconds. */
    public const int TEST_COOLDOWN = 30;

    public function __construct(
        private readonly Digest $digest,
        private readonly Settings $settings,
    ) {}

    public function index(): CpScreenResponse
    {
        $state = $this->digest->state();

        return new CpScreenResponse()
            ->title(t('Error digest', category: 'freelog'))
            ->crumbs([
                new ActionItem()->label(t('Freelog', category: 'freelog'))->href(Url::cpUrl('freelog')),
            ])
            ->contentTemplate('freelog/digest', [
                'settings' => $this->settings,
                'state' => $state,
                'next' => $this->digest->nextDueAt(),
                'timeZone' => Cms::systemTimezone(),
                'recipients' => $this->settings->recipientList(),
                'settingsUrl' => Url::cpUrl('settings/plugins/freelog'),
                'testUrl' => Url::cpUrl('freelog/digest/test'),
            ]);
    }

    public function sendTest(): Response
    {
        $user = currentUserElement();

        // To the digest's own list, so the test shows exactly who the real one reaches. A site
        // that has not set one up yet gets it sent to the admin pressing the button.
        $recipients = $this->settings->recipientList();

        if ($recipients === [] && is_string($user?->email) && $user->email !== '') {
            $recipients = [$user->email];
        }

        if ($recipients === []) {
            return $this->asFailure(t('The digest has no recipients to send a test to.', category: 'freelog'));
        }

        if (! Cache::add('freelog:digest:test:'.($user->id ?? 0), 1, self::TEST_COOLDOWN)) {
            return $this->asFailure(t('A test digest was sent a moment ago. Give it half a minute.', category: 'freelog'));
        }

        try {
            $sent = $this->digest->sendTest($recipients);
        } catch (Throwable $e) {
            Log::warning('Freelog: could not send a test digest: '.$e->getMessage());
            $sent = 0;
        }

        if ($sent === 0) {
            return $this->asFailure(t('The test digest could not be sent. Check the email settings and the log.', category: 'freelog'));
        }

        return $this->asSuccess(
            t('Test digest sent to {count, plural, =1{one recipient} other{# recipients}}.', ['count' => $sent], category: 'freelog'),
            redirect: Url::cpUrl('freelog/digest'),
        );
    }
}
