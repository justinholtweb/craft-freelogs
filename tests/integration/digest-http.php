<?php
/**
 * The error digest over HTTP: "Send a test digest now" is admin-only, POST-only and CSRF-checked;
 * the settings screen is admin-only, renders the test form outside the settings form, and refuses
 * a bad recipient without saving.
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-freelogs/tests/integration/digest-http.php
 *
 * Makes one throwaway non-admin user and deletes it at the end. The admin's test send goes through
 * the harness's own mailer to the admin's address (no recipients are saved in the harness), and
 * the digest marker row is put back as it was. Nothing is written to project config.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\db\Query;
use craft\elements\User;
use craft\helpers\Db;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use justinholtweb\freelog\Plugin;
use justinholtweb\freelog\services\Digest;

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();
    } catch (Throwable $e) {
        $result = get_class($e) . ': ' . $e->getMessage();
    }

    if ($result === true) {
        $passed++;
        echo "  ✓ $label\n";
    } else {
        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : var_export($result, true)) . "\n";
    }
}

Craft::$app->getPlugins()->loadPlugins();

if (Craft::$app->getPlugins()->isPluginEnabled('penny')) {
    yii\base\Event::off(craft\services\Elements::class, craft\services\Elements::EVENT_BEFORE_SAVE_ELEMENT);
}

$run = bin2hex(random_bytes(3));
$password = 'Freelog-' . bin2hex(random_bytes(6));
$originalRow = (new Query())->from([Digest::TABLE])->where(['handle' => Digest::HANDLE])->one() ?: null;
$cleanup = [];

register_shutdown_function(function() use (&$cleanup, $originalRow) {
    foreach ($cleanup as $user) {
        Craft::$app->getElements()->deleteElement($user, true);
    }

    Db::delete(Digest::TABLE, ['handle' => Digest::HANDLE]);
    if ($originalRow) {
        unset($originalRow['id']);
        Db::insert(Digest::TABLE, $originalRow);
    }
});

function client(?string $username, ?string $password): array
{
    $http = new Client(['base_uri' => 'http://localhost/', 'cookies' => new CookieJar(), 'http_errors' => false, 'allow_redirects' => false]);
    $csrf = static fn() => (string)(json_decode((string)$http->get('index.php?p=actions/users/session-info', ['headers' => ['Accept' => 'application/json']])->getBody(), true)['csrfTokenValue'] ?? '');

    if ($username !== null) {
        $login = $http->post('index.php?p=actions/users/login', ['headers' => ['Accept' => 'application/json'], 'form_params' => ['loginName' => $username, 'password' => $password, 'CRAFT_CSRF_TOKEN' => $csrf()]]);

        if ($login->getStatusCode() !== 200) {
            throw new RuntimeException("Could not sign in as $username: " . $login->getStatusCode() . ' ' . substr((string)$login->getBody(), 0, 200));
        }
    }

    return [$http, $csrf];
}

$viewer = new User(['username' => "freelog-digest-$run", 'email' => "freelog-digest-$run@example.com", 'newPassword' => $password]);
Craft::$app->getElements()->saveElement($viewer, false);
Craft::$app->getUsers()->activateUser($viewer);
Craft::$app->getUserPermissions()->saveUserPermissions($viewer->id, ['accesscp', 'accessplugin-freelog', Plugin::PERMISSION_VIEW, Plugin::PERMISSION_CLEAR]);
$cleanup[] = $viewer;

[$guest, $guestCsrf] = client(null, null);
[$viewerHttp, $viewerCsrf] = client($viewer->username, $password);
[$adminHttp, $adminCsrf] = client('admin', 'claudepassword');

$sendTest = static fn(Client $http, ?string $csrf) => $http->post('index.php?p=admin/actions/freelog/digest/send-test', [
    'form_params' => array_filter([
        'CRAFT_CSRF_TOKEN' => $csrf,
        'redirect' => Craft::$app->getSecurity()->hashData('freelog/settings'),
    ]),
]);

echo "\nSend a test digest now\n";

check('a guest is turned away', function() use ($guest, $guestCsrf, $sendTest) {
    $status = $sendTest($guest, $guestCsrf())->getStatusCode();

    return in_array($status, [302, 403], true) ?: "status $status";
});

check('a user who can view and clear logs — but is not an admin — gets 403', function() use ($viewerHttp, $viewerCsrf, $sendTest) {
    $status = $sendTest($viewerHttp, $viewerCsrf())->getStatusCode();

    return $status === 403 ?: "status $status";
});

check('an admin over GET is refused', function() use ($adminHttp) {
    $status = $adminHttp->get('index.php?p=admin/actions/freelog/digest/send-test')->getStatusCode();

    return in_array($status, [400, 405], true) ?: "status $status";
});

check('an admin without a CSRF token is refused', function() use ($adminHttp, $sendTest) {
    $status = $sendTest($adminHttp, null)->getStatusCode();

    return $status === 400 ?: "status $status";
});

check('an admin with a CSRF token sends (or reports the mailer’s failure), back to settings', function() use ($adminHttp, $adminCsrf, $sendTest) {
    $response = $sendTest($adminHttp, $adminCsrf());
    $location = $response->getHeaderLine('Location');
    $page = (string)$adminHttp->get('index.php?p=admin/freelog/settings')->getBody();
    $flash = str_contains($page, 'Test digest sent') || str_contains($page, 'could not be sent');

    return ($response->getStatusCode() === 302 && str_contains($location, 'freelog/settings') && $flash)
        ?: 'status ' . $response->getStatusCode() . " → $location, flash " . var_export($flash, true);
});

check('a second press inside 30 seconds is held back', function() use ($adminHttp, $adminCsrf, $sendTest) {
    $sendTest($adminHttp, $adminCsrf());
    $page = (string)$adminHttp->get('index.php?p=admin/freelog/settings')->getBody();

    return str_contains($page, 'sent a moment ago') ?: 'no cooldown notice';
});

check('a test send never records a period or a read position', function() {
    $row = (new Query())->from([Digest::TABLE])->where(['handle' => Digest::HANDLE])->one();

    return ($row === null || ($row['period'] === null && $row['offsets'] === null && $row['lastSentAt'] === null)) ?: json_encode($row);
});

echo "\nThe settings screen\n";

check('a non-admin gets 403 on the settings screen and on save', function() use ($viewerHttp, $viewerCsrf) {
    $view = $viewerHttp->get('index.php?p=admin/freelog/settings')->getStatusCode();
    $save = $viewerHttp->post('index.php?p=admin/actions/freelog/settings/save', [
        'form_params' => ['CRAFT_CSRF_TOKEN' => $viewerCsrf(), 'settings' => ['digestEnabled' => '1', 'digestRecipients' => 'attacker@example.com']],
    ])->getStatusCode();

    return ($view === 403 && $save === 403 && Plugin::current()->getSettings()->digestRecipients !== 'attacker@example.com') ?: "view $view, save $save";
});

check('…and is not shown the Settings subnav item', function() use ($viewerHttp) {
    return !str_contains((string)$viewerHttp->get('index.php?p=admin/freelog')->getBody(), 'admin/freelog/settings') ?: 'shown';
});

check('Craft’s plugin settings link goes to the screen', function() use ($adminHttp) {
    $response = $adminHttp->get('index.php?p=admin/settings/plugins/freelog');

    return ($response->getStatusCode() === 302 && str_contains($response->getHeaderLine('Location'), 'freelog/settings'))
        ?: 'status ' . $response->getStatusCode() . ' → ' . $response->getHeaderLine('Location');
});

check('the admin sees the screen, with the test form after the settings form and never inside it', function() use ($adminHttp) {
    $response = $adminHttp->get('index.php?p=admin/freelog/settings');
    $html = (string)$response->getBody();
    $save = strpos($html, 'value="freelog/settings/save"');
    $test = strpos($html, 'value="freelog/digest/send-test"');
    $close = $save !== false ? strpos($html, '</form>', $save) : false;
    // No <form> opens between the settings form's start and its end.
    $start = $save !== false ? strrpos(substr($html, 0, $save), '<form') : false;
    $nested = $start !== false && $close !== false && substr_count(substr($html, $start, $close - $start), '<form') > 1;

    return ($response->getStatusCode() === 200 && $save !== false && $test !== false && $close < $test && !$nested
        && str_contains($html, 'settings[digestRecipients]') && str_contains($html, 'name="settings[digestLevels][]"'))
        ?: json_encode(['status' => $response->getStatusCode(), 'save' => $save, 'test' => $test, 'close' => $close, 'nested' => $nested,
            'names' => preg_match_all('/name="(settings[^"]*)"/', $html, $m) ? array_values(array_unique($m[1])) : []]);
});

check('a bad recipient is refused by name and nothing is saved', function() use ($adminHttp, $adminCsrf) {
    $before = Craft::$app->getProjectConfig()->get('plugins.freelog.settings');
    $response = $adminHttp->post('index.php?p=admin/actions/freelog/settings/save', [
        'form_params' => ['CRAFT_CSRF_TOKEN' => $adminCsrf(), 'settings' => ['digestEnabled' => '1', 'digestRecipients' => 'not-an-address']],
    ]);
    $html = (string)$response->getBody();
    Craft::$app->getProjectConfig()->reset();
    $after = Craft::$app->getProjectConfig()->get('plugins.freelog.settings');

    return ($response->getStatusCode() === 200 && str_contains($html, '“not-an-address” is not an email address') && $before === $after)
        ?: 'status ' . $response->getStatusCode();
});

echo "\nTemplates\n";

$templates = array_merge(
    glob(dirname(__DIR__, 2) . '/src/templates/settings/*.twig'),
    glob(dirname(__DIR__, 2) . '/src/templates/_partials/*.twig'),
);

check('the new CP templates carry no inline styles, hand-rolled inputs or untranslated strings', function() use ($templates) {
    $found = [];

    foreach ($templates as $file) {
        $twig = (string)file_get_contents($file);

        if (preg_match('/\sstyle="|\son[a-z]+="|<(input|select)\b/i', $twig)) {
            $found[] = basename($file) . ': inline style/handler or raw input';
        }

        $text = preg_replace(['/\{#.*?#\}/s', '/\{%.*?%\}/s', '/\{\{.*?\}\}/s', '/<[^>]+>/s'], ' ', $twig);

        foreach (preg_split('/\s+/', (string)$text) as $word) {
            if (preg_match('/[A-Za-z]{2,}/', $word)) {
                $found[] = basename($file) . ": $word";
            }
        }

        if (preg_match('/\|t(?!\(\'freelog\')/', $twig)) {
            $found[] = basename($file) . ': |t without the freelog category';
        }
    }

    return $found === [] ?: implode(', ', array_slice(array_unique($found), 0, 12));
});

check('the email templates never print a log string raw', function() {
    $found = [];

    foreach (glob(dirname(__DIR__, 2) . '/src/templates/_emails/*.twig') as $file) {
        if (preg_match('/\|raw\b/', (string)file_get_contents($file))) {
            $found[] = basename($file);
        }
    }

    return $found === [] ?: implode(', ', $found);
});

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
