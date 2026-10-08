<?php
/**
 * Who can read and clear logs, which files they can reach, and the control panel's conventions —
 * checked in the plugin-testing harness over HTTP.
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-freelogs/tests/integration/security.php
 *
 * Until 5.0.5: one "Access Freelog" permission both read every log and truncated them; view,
 * download and clear reached any file in storage/logs, log or not; and the screens used hand-rolled
 * controls, a custom table, inline styles and untranslated strings — and the live tail polled into
 * a panel that was never shown.
 *
 * Self-cleaning: its users and fixture files are removed at the end.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\elements\User;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use justinholtweb\freelog\Plugin;

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

// craft-penny's broken beforeSaveElement handler (see craft-bird's checks.php) — detached in-process only.
if (Craft::$app->getPlugins()->isPluginEnabled('penny')) {
    yii\base\Event::off(craft\services\Elements::class, craft\services\Elements::EVENT_BEFORE_SAVE_ELEMENT);
}

$run = bin2hex(random_bytes(3));
$password = 'Freelog-' . bin2hex(random_bytes(6));
$logs = Plugin::getInstance()->getLogService()->getLogsPath();
$log = "freelog-check-$run.log";
$notLog = "freelog-check-$run.bak";
$entry = date('Y-m-d H:i:s') . " [web.ERROR] [application] Freelog check $run\n";
$cleanup = ['users' => [], 'files' => ["$logs/$log", "$logs/$notLog"]];

file_put_contents("$logs/$log", $entry);
file_put_contents("$logs/$notLog", "secret $run");

register_shutdown_function(function() use (&$cleanup) {
    foreach ($cleanup['users'] as $user) {
        Craft::$app->getElements()->deleteElement($user, true);
    }
    foreach ($cleanup['files'] as $file) {
        @unlink($file);
    }
});

$user = static function(string $name, array $permissions) use (&$cleanup, $run, $password): User {
    $u = new User(['username' => "freelog-$name-$run", 'email' => "freelog-$name-$run@example.com", 'newPassword' => $password]);
    Craft::$app->getElements()->saveElement($u, false);
    Craft::$app->getUsers()->activateUser($u);
    Craft::$app->getUserPermissions()->saveUserPermissions($u->id, $permissions);
    $cleanup['users'][] = $u;

    return $u;
};

function client(string $username, string $password): array
{
    $http = new Client(['base_uri' => 'http://localhost/', 'cookies' => new CookieJar(), 'http_errors' => false, 'allow_redirects' => false]);
    $csrf = static fn() => (string)(json_decode((string)$http->get('index.php?p=actions/users/session-info', ['headers' => ['Accept' => 'application/json']])->getBody(), true)['csrfTokenValue'] ?? '');
    $http->post('index.php?p=actions/users/login', ['headers' => ['Accept' => 'application/json'], 'form_params' => ['loginName' => $username, 'password' => $password, 'CRAFT_CSRF_TOKEN' => $csrf()]])->getStatusCode() === 200
        or throw new RuntimeException("Could not sign in as $username");

    return [$http, $csrf];
}

$viewer = $user('viewer', ['accesscp', 'accessplugin-freelog', Plugin::PERMISSION_VIEW]);
$clearer = $user('clearer', ['accesscp', 'accessplugin-freelog', Plugin::PERMISSION_VIEW, Plugin::PERMISSION_CLEAR]);
[$viewerHttp, $viewerCsrf] = client($viewer->username, $password);
[$clearerHttp, $clearerCsrf] = client($clearer->username, $password);

$clear = static fn(Client $http, callable $csrf, string $file) => $http->post('index.php?p=admin/actions/freelog/logs/clear', [
    'form_params' => ['file' => $file, 'CRAFT_CSRF_TOKEN' => $csrf()],
])->getStatusCode();

echo "\nViewing and clearing are separate\n";

check('the clear permission is nested under view, so it can’t be granted alone', function() {
    $groups = Craft::$app->getUserPermissions()->getAllPermissions();
    foreach ($groups as $group) {
        $view = $group['permissions'][Plugin::PERMISSION_VIEW] ?? null;
        if ($view !== null) {
            return isset($view['nested'][Plugin::PERMISSION_CLEAR]) ?: 'not nested';
        }
    }

    return 'view permission not registered';
});

check('“View logs” can read a log', function() use ($viewerHttp, $log, $run) {
    $response = $viewerHttp->get('index.php?p=admin/freelog/view&file=' . rawurlencode($log));

    return $response->getStatusCode() === 200 && str_contains((string)$response->getBody(), "Freelog check $run") ?: 'status ' . $response->getStatusCode();
});

check('…download it, and tail it', function() use ($viewerHttp, $log) {
    $download = $viewerHttp->get('index.php?p=admin/freelog/download&file=' . rawurlencode($log))->getStatusCode();
    $tail = $viewerHttp->get('index.php?p=admin/freelog/tail&file=' . rawurlencode($log), ['headers' => ['Accept' => 'application/json']])->getStatusCode();

    return $download === 200 && $tail === 200 ?: "download $download, tail $tail";
});

check('…isn’t offered a Clear button', function() use ($viewerHttp) {
    return !str_contains((string)$viewerHttp->get('index.php?p=admin/freelog')->getBody(), 'freelog-clear-form') ?: 'offered';
});

check('…and can’t clear one: 403, and the log is intact', function() use ($viewerHttp, $viewerCsrf, $clear, $logs, $log, $entry) {
    $status = $clear($viewerHttp, $viewerCsrf, $log);

    return $status === 403 && file_get_contents("$logs/$log") === $entry ?: "status $status";
});

check('“Clear logs” is offered the button and can clear', function() use ($clearerHttp, $clearerCsrf, $clear, $logs, $log) {
    $offered = str_contains((string)$clearerHttp->get('index.php?p=admin/freelog')->getBody(), 'freelog-clear-form');
    $status = $clear($clearerHttp, $clearerCsrf, $log);
    clearstatcache();

    return $offered && $status === 302 && filesize("$logs/$log") === 0 ?: "offered " . var_export($offered, true) . ", status $status";
});

check('the live tail answers with JSON on a big log full of multibyte text', function() use ($viewerHttp, $logs, $run, &$cleanup) {
    // Over 8 KB of two-byte characters, so the tail's byte offset lands inside one.
    $name = "freelog-check-$run-utf8.log";
    $cleanup['files'][] = "$logs/$name";
    file_put_contents("$logs/$name", str_repeat(date('Y-m-d H:i:s') . " [web.INFO] [application] café crème brûlée\n", 300));

    $response = $viewerHttp->get('index.php?p=admin/freelog/tail&file=' . rawurlencode($name), ['headers' => ['Accept' => 'application/json']]);
    $content = json_decode((string)$response->getBody(), true)['content'] ?? null;

    return $response->getStatusCode() === 200 && is_string($content) && str_starts_with($content, date('Y-m-d'))
        ?: 'status ' . $response->getStatusCode();
});

check('a 220 MB log opens — first page and a deep one — inside PHP-FPM’s memory limit', function() use ($viewerHttp, $logs, $run, &$cleanup) {
    // Until 5.0.5 the whole file was read and parsed into memory, about 5.5× its size: this log
    // needed 1.2 GB against the harness's 1 GB limit, and the page died with a 500.
    $name = "freelog-check-$run-big.log";
    $cleanup['files'][] = "$logs/$name";
    $handle = fopen("$logs/$name", 'w');
    $line = ' [web.INFO] [application] A routine request — nothing to see, padded to look like a real one ' . str_repeat('.', 60) . "\n";
    $trace = str_repeat("#0 /var/www/html/vendor/yiisoft/yii2/base/Module.php(552): craft\\web\\Application->runAction()\n", 5);
    for ($i = 0; ftell($handle) < 220 * 1024 * 1024; $i++) {
        fwrite($handle, '2026-01-01 00:00:00' . $line . ($i % 10 === 0 ? $trace : ''));
    }
    fwrite($handle, "2026-01-02 00:00:00 [web.ERROR] [application] newest-$run\n");
    fclose($handle);

    $first = $viewerHttp->get('index.php?p=admin/freelog/view&file=' . rawurlencode($name));
    $deep = $viewerHttp->get('index.php?p=admin/freelog/view&file=' . rawurlencode($name) . '&page=5000');
    @unlink("$logs/$name");

    return $first->getStatusCode() === 200 && str_contains((string)$first->getBody(), "newest-$run") && $deep->getStatusCode() === 200
        ?: 'first page ' . $first->getStatusCode() . ', page 5000 ' . $deep->getStatusCode();
});

echo "\nOnly log files are reachable\n";

check('a non-log file in storage/logs can’t be viewed', fn() => ($s = $viewerHttp->get('index.php?p=admin/freelog/view&file=' . rawurlencode($notLog))->getStatusCode()) === 404 ?: "status $s");

check('…or downloaded', fn() => ($s = $viewerHttp->get('index.php?p=admin/freelog/download&file=' . rawurlencode($notLog))->getStatusCode()) === 404 ?: "status $s");

check('…or cleared, even with the clear permission', function() use ($clearerHttp, $clearerCsrf, $clear, $logs, $notLog, $run) {
    $status = $clear($clearerHttp, $clearerCsrf, $notLog);

    return $status === 404 && file_get_contents("$logs/$notLog") === "secret $run" ?: "status $status";
});

check('a path outside storage/logs can’t be downloaded', fn() => ($s = $viewerHttp->get('index.php?p=admin/freelog/download&file=' . rawurlencode('../../.env'))->getStatusCode()) === 404 ?: "status $s");

echo "\nThe control panel\n";

$templates = glob(dirname(__DIR__, 2) . '/src/templates/*.twig');

check('templates carry no inline styles or inline event handlers', function() use ($templates) {
    $found = [];
    foreach ($templates as $file) {
        if (preg_match('/\sstyle="|\son[a-z]+="/i', file_get_contents($file))) {
            $found[] = basename($file);
        }
    }

    return $found === [] ?: implode(', ', $found);
});

check('no hand-rolled <input>/<select> — Craft’s form macros instead', function() use ($templates) {
    $found = [];
    foreach ($templates as $file) {
        if (preg_match('/<(input|select)\b/i', file_get_contents($file))) {
            $found[] = basename($file);
        }
    }

    return $found === [] ?: implode(', ', $found);
});

check('every visible string goes through |t(\'freelog\')', function() use ($templates) {
    $found = [];
    foreach ($templates as $file) {
        $twig = file_get_contents($file);
        // Drop Twig comments, tags and output, then HTML tags; what's left is literal text.
        $text = preg_replace(['/\{#.*?#\}/s', '/\{%.*?%\}/s', '/\{\{.*?\}\}/s', '/<[^>]+>/s'], ' ', $twig);
        foreach (preg_split('/\s+/', $text) as $word) {
            // `gz` is a file-format badge, not a word.
            if (preg_match('/[A-Za-z]{2,}/', $word) && $word !== 'gz') {
                $found[] = basename($file) . ": $word";
            }
        }
        // And every |t() names the plugin's category.
        if (preg_match('/\|t(?!\(\'freelog\')/', $twig)) {
            $found[] = basename($file) . ': |t without the freelog category';
        }
    }

    return $found === [] ?: implode(', ', array_slice(array_unique($found), 0, 12));
});

check('the stylesheet uses Craft’s variables, not its own colours', function() {
    $css = file_get_contents(dirname(__DIR__, 2) . '/src/resources/css/freelog.css');
    preg_match_all('/#[0-9a-f]{3,8}\b|rgba?\(/i', $css, $m);

    return $m[0] === [] ?: implode(', ', array_unique($m[0]));
});

file_put_contents("$logs/$log", $entry);

check('the log view uses Craft’s data table and controls, and the tail panel starts hidden', function() use ($viewerHttp, $log) {
    $html = (string)$viewerHttp->get('index.php?p=admin/freelog/view&file=' . rawurlencode($log))->getBody();

    $checks = [
        'data table' => (bool)preg_match('/<div class="freelog-entries">\s*<table class="data fullwidth">/', $html),
        'level select in .select' => (bool)preg_match('/<div class="select">\s*<select[^>]*id="freelog-level"/', $html),
        'tail hidden by attribute' => (bool)preg_match('/id="freelog-tail-content"[^>]*\shidden/', $html),
    ];

    return !in_array(false, $checks, true) ?: 'missing: ' . implode(', ', array_keys(array_filter($checks, fn($ok) => !$ok)));
});

check('the auto-refresh script shows the tail panel it fills', function() {
    $js = file_get_contents(dirname(__DIR__, 2) . '/src/resources/js/freelog.js');

    return str_contains($js, 'tailContainer.hidden = false') ?: 'the panel is never shown';
});

echo "\n$passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
