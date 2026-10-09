<?php

namespace justinholtweb\freelog\helpers;

use Craft;

/**
 * Takes secrets and personal data out of a log line that is about to leave the server.
 *
 * The control panel shows a log as it is, to people trusted with it. An email is different: it
 * goes to a mailbox, gets forwarded, sits in an archive long after the log was rotated away. So
 * every line the digest quotes passes through here first:
 *
 * - this install's own secrets **by value** — the security key, the database password, and every
 *   environment variable whose name says it is a key, secret, password, token, salt or DSN;
 * - anything **shaped** like a credential: `Bearer …`/`Basic …`, `password=…`, `"api_key":"…"`,
 *   `user:pass@` in a URL, JSON web tokens;
 * - **personal data**: email addresses keep their domain only, IPv4 addresses lose their last
 *   octet, card-number-shaped digit runs that pass Luhn are masked.
 *
 * The same rules as craft-erpy's alerts (`Alerts::redact()`), with one difference that matters
 * for logs: a value after `token:` or `password:` *with a space before it* is only taken when it
 * looks like a credential (digits in it, or long and unbroken). "Invalid CSRF token: missing"
 * keeps its last word; `token=abc` or `"token":"abc"` never do — there the separator binds the
 * value, so it is a value whatever it looks like.
 */
final class Redactor
{
    public const MASK = '••••';

    /** Secret values shorter than this are not replaced by value: "1" or "dev" would hit everywhere. */
    private const MIN_SECRET_LENGTH = 6;

    /** Names that make an environment variable's value a secret. */
    private const SECRET_ENV_NAME = '/(KEY|SECRET|PASSWORD|PASSWD|PASS|PWD|TOKEN|SALT|DSN|AUTH|CREDENTIAL|PRIVATE)/i';

    /** Names that make the value after them a secret, in `name=value` and `"name": "value"`. */
    private const SECRET_KEYS = 'password|passwd|pwd|pass|secret|client_secret|api[_-]?key|apikey|access[_-]?token|refresh[_-]?token|id[_-]?token|auth[_-]?token|token|csrf[_-]?token|signature|sig|private[_-]?key|securityKey|authorization|cookie|session[_-]?id|phpsessid';

    /** @var string[] Longest first, so a secret containing another is replaced whole. */
    private array $secrets;

    /**
     * @param string[] $secrets Values to replace wherever they appear.
     */
    public function __construct(array $secrets = [])
    {
        $secrets = array_values(array_unique(array_filter(
            $secrets,
            fn(string $secret) => strlen($secret) >= self::MIN_SECRET_LENGTH,
        )));
        usort($secrets, fn(string $a, string $b) => strlen($b) <=> strlen($a));
        $this->secrets = $secrets;
    }

    /**
     * A redactor that knows this install's secrets: the security key, the database password, and
     * every environment variable whose name marks it as one.
     */
    public static function forInstall(): self
    {
        $secrets = [];

        try {
            $secrets[] = (string)Craft::$app->getConfig()->getGeneral()->securityKey;
            $secrets[] = (string)Craft::$app->getConfig()->getDb()->password;
        } catch (\Throwable) {
            // A half-configured install still gets the pattern rules.
        }

        $environment = array_merge(getenv() ?: [], $_ENV, array_filter($_SERVER, 'is_string'));

        foreach ($environment as $name => $value) {
            // A plain word (`AUTH_METHOD=password`) is a setting, not a secret, and masking it
            // would mask that word in every line.
            if (is_string($name) && is_string($value) && preg_match(self::SECRET_ENV_NAME, $name) && !preg_match('/^[a-z]{1,15}$/i', $value)) {
                $secrets[] = $value;
            }
        }

        return new self($secrets);
    }

    /**
     * Redacts a line (or a block of lines — newlines are kept) and caps it at `$maxLength`
     * characters.
     */
    public function redact(string $text, int $maxLength = 500): string
    {
        $text = mb_scrub($text, 'UTF-8');

        if ($this->secrets !== []) {
            $text = str_replace($this->secrets, self::MASK, $text);
        }

        $keys = self::SECRET_KEYS;
        $replacements = [
            // URL credentials: scheme://user:pass@host → scheme://••••@host
            '~\b([a-z][a-z0-9+.\-]*://)[^\s/@:]+:[^\s/@]+@~i' => '$1' . self::MASK . '@',
            // HTTP auth schemes — when what follows looks like a credential, so "CSRF token
            // expired" and "Basic authentication failed" keep their words.
            '/\b(Bearer|Basic|Digest|Token)\s+(?=[A-Za-z0-9\-._~+\/]*\d)[A-Za-z0-9\-._~+\/]{8,}=*/i' => '$1 ' . self::MASK,
            '/\b(Bearer|Basic|Digest|Token)\s+[A-Za-z0-9\-._~+\/]{20,}=*/i' => '$1 ' . self::MASK,
            // JSON web tokens anywhere.
            '/\beyJ[A-Za-z0-9_\-]{8,}\.[A-Za-z0-9_\-]{8,}\.[A-Za-z0-9_\-]*/' => self::MASK,
            // A quoted value after a secret name: "password": "x", 'token' => 'x', password="x".
            '/((?:["\']?)\b(?:' . $keys . ')\b["\']?\s*(?:=>|[:=])\s*)(["\'])(?:\\\\.|(?!\2).)*\2/i' => '$1$2' . self::MASK . '$2',
            // print_r: [password] => x
            '/(\[(?:' . $keys . ')\]\s*=>\s*)\S[^\n]*/i' => '$1' . self::MASK,
            // An unquoted value after `=`, spaced or not: password=x, ?api_key=x&, secret = x.
            '/(\b(?:' . $keys . ')\b\s*=(?!>)\s*)(?!["\'\s])[^\s"\'&,;}\]\)]+/i' => '$1' . self::MASK,
            // An unquoted value bound tight to a colon: token:x. Not `Token::find()`, not `://`.
            '/(\b(?:' . $keys . ')\b:(?![:\/]))(?!["\'\s])[^\s"\'&,;}\]\)]+/i' => '$1' . self::MASK,
            // An unquoted value after a colon and a space ("token: abc123…") only when it looks
            // like a credential — digits in it and 8+ characters, or 20+ unbroken. "Invalid CSRF
            // token: missing" keeps "missing".
            '/(\b(?:' . $keys . ')\b\s*:\s+)(?=[A-Za-z0-9\-._~+\/=]*\d)[A-Za-z0-9\-._~+\/=]{8,}/i' => '$1' . self::MASK,
            '/(\b(?:' . $keys . ')\b\s*:\s+)[A-Za-z0-9\-._~+\/=]{20,}/i' => '$1' . self::MASK,
            // Email addresses keep their domain: jo@example.com → •••@example.com.
            '/[A-Za-z0-9._%+\-]+@([A-Za-z0-9\-]+(?:\.[A-Za-z0-9\-]+)*\.[A-Za-z]{2,})/' => self::MASK . '@$1',
            // IPv4 addresses lose their last octet. Not inside version strings (1.2.3.4.5).
            '/(?<![\d.])((?:25[0-5]|2[0-4]\d|1?\d?\d)\.(?:25[0-5]|2[0-4]\d|1?\d?\d)\.(?:25[0-5]|2[0-4]\d|1?\d?\d)\.)(?:25[0-5]|2[0-4]\d|1?\d?\d)(?![\d.])/' => '$1x',
        ];

        $text = (string)preg_replace(array_keys($replacements), array_values($replacements), $text);

        // Card numbers: 13–19 digits, optionally spaced or dashed in groups, that pass Luhn.
        $text = (string)preg_replace_callback(
            '/(?<![\d])(?:\d[ \-]?){12,18}\d(?![\d])/',
            fn(array $m) => self::luhn((string)preg_replace('/\D/', '', $m[0])) ? self::MASK : $m[0],
            $text,
        );

        return mb_strlen($text) > $maxLength ? mb_substr($text, 0, $maxLength - 1) . '…' : $text;
    }

    private static function luhn(string $digits): bool
    {
        $length = strlen($digits);

        if ($length < 13 || $length > 19) {
            return false;
        }

        $sum = 0;

        for ($i = 0; $i < $length; $i++) {
            $digit = (int)$digits[$length - 1 - $i];

            if ($i % 2 === 1) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }

            $sum += $digit;
        }

        return $sum % 10 === 0;
    }
}
