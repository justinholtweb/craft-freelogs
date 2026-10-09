<?php

namespace justinholtweb\freelog\models;

use Craft;
use craft\base\Model;
use craft\helpers\App;
use ReflectionNamedType;
use ReflectionProperty;

/**
 * Plugin settings, in project config. The digest's per-file read positions are state, not
 * configuration, and live in the `freelog_digests` table.
 *
 * Nothing here is marked `required`. A `required` rule fails `savePluginSettings()` wholesale, so
 * one unfilled box would block saving every other setting on a fresh install.
 */
class Settings extends Model
{
    public const DIGEST_DAILY = 'daily';
    public const DIGEST_WEEKLY = 'weekly';

    /** Every level the viewer knows, most severe first. */
    public const LEVELS = ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'];

    /** What the digest reports by default: the levels that mean something broke. */
    public const DIGEST_DEFAULT_LEVELS = ['emergency', 'alert', 'critical', 'error'];

    /** Email a digest of new error-level log entries on a schedule. */
    public bool $digestEnabled = false;

    /** `daily` or `weekly`. */
    public string $digestFrequency = self::DIGEST_DAILY;

    /** ISO day of the week for a weekly digest: 1 is Monday, 7 is Sunday. */
    public int $digestWeekday = 1;

    /**
     * Hour of the day, 0–23, in the system time zone, after which the digest for the day or week
     * becomes due. "After", not "at": a site whose cron missed the hour still sends later in the
     * same period, once.
     */
    public int $digestHour = 8;

    /**
     * Who gets it: email addresses separated by commas or new lines, or an environment variable
     * (`$FREELOG_DIGEST_RECIPIENTS`) holding the same. Set by an admin — the digest carries log
     * contents off the server.
     */
    public string $digestRecipients = '';

    /**
     * Send a digest even when no new entries were logged. Off by default: an "all clear" every
     * morning is an email people learn to stop opening.
     */
    public bool $digestSendWhenEmpty = false;

    /**
     * Levels the digest reports. Empty means the default: emergency, alert, critical and error.
     *
     * @var string[]
     */
    public array $digestLevels = self::DIGEST_DEFAULT_LEVELS;

    /**
     * Leave out 4xx HTTP exceptions (`yii\web\HttpException:404` and friends). Craft logs every
     * not-found, forbidden and bad request at error level; on a public site they are most of the
     * error log and almost none of them are the site's fault.
     */
    public bool $digestIgnoreClientErrors = true;

    /**
     * Include the first lines of each entry's stack trace in the email. Off by default: a trace
     * carries server paths and function arguments — posted values among them — and an email
     * outlives the log it came from. Traces are redacted like everything else when on.
     */
    public bool $digestIncludeTraces = false;

    /**
     * Also check whether a digest is due at the end of web requests (at most every five minutes)
     * and queue it, for sites without a cron job running `freelog/digest/send`.
     */
    public bool $digestWebTrigger = true;

    public function attributeLabels(): array
    {
        return [
            'digestEnabled' => Craft::t('freelog', 'Email an error digest'),
            'digestFrequency' => Craft::t('freelog', 'How often'),
            'digestWeekday' => Craft::t('freelog', 'Day of the week'),
            'digestHour' => Craft::t('freelog', 'Hour'),
            'digestRecipients' => Craft::t('freelog', 'Recipients'),
            'digestSendWhenEmpty' => Craft::t('freelog', 'Send even when there is nothing new'),
            'digestLevels' => Craft::t('freelog', 'Levels to report'),
            'digestIgnoreClientErrors' => Craft::t('freelog', 'Leave out 4xx HTTP errors'),
            'digestIncludeTraces' => Craft::t('freelog', 'Include stack traces'),
            'digestWebTrigger' => Craft::t('freelog', 'Check from web requests too'),
        ];
    }

    /**
     * @return array<int, array<int|string, mixed>>
     */
    protected function defineRules(): array
    {
        return [
            [['digestEnabled', 'digestSendWhenEmpty', 'digestIgnoreClientErrors', 'digestIncludeTraces', 'digestWebTrigger'], 'boolean'],
            [['digestFrequency'], 'in', 'range' => [self::DIGEST_DAILY, self::DIGEST_WEEKLY]],
            [['digestWeekday'], 'integer', 'min' => 1, 'max' => 7],
            [['digestHour'], 'integer', 'min' => 0, 'max' => 23],
            [['digestRecipients'], 'validateRecipients'],
            [['digestLevels'], 'validateLevels', 'skipOnEmpty' => false],
        ];
    }

    public function validateRecipients(string $attribute): void
    {
        // An environment variable is checked for what it resolves to here, and again at send
        // time, because the value can differ between this environment and the one that sends.
        foreach ($this->recipientList(true) as $address) {
            $this->addError($attribute, Craft::t('freelog', '“{address}” is not an email address.', [
                'address' => $address,
            ]));
        }
    }

    public function validateLevels(string $attribute): void
    {
        foreach ($this->$attribute as $level) {
            if (!in_array($level, self::LEVELS, true)) {
                $this->addError($attribute, Craft::t('freelog', '“{name}” is not a log level Freelog knows.', [
                    'name' => $level,
                ]));
            }
        }
    }

    /**
     * The digest's recipients, parsed: environment variable resolved, split on commas, semicolons
     * and whitespace, de-duplicated.
     *
     * @param bool $invalid Return the entries that are *not* email addresses instead.
     * @return string[]
     */
    public function recipientList(bool $invalid = false): array
    {
        $raw = (string)App::parseEnv($this->digestRecipients);
        $parts = preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $valid = [];
        $bad = [];

        foreach (array_unique($parts) as $part) {
            if (filter_var($part, FILTER_VALIDATE_EMAIL) !== false) {
                $valid[] = $part;
            } else {
                $bad[] = $part;
            }
        }

        return $invalid ? $bad : $valid;
    }

    /**
     * The levels to report, lowercased, with the default standing in for "none ticked".
     *
     * @return string[]
     */
    public function levelList(): array
    {
        return $this->digestLevels !== [] ? $this->digestLevels : self::DIGEST_DEFAULT_LEVELS;
    }

    /**
     * Craft's control panel posts everything as a string, and a checkbox group with nothing ticked
     * posts `['']`. Both arrive here.
     *
     * @param mixed $values
     */
    public function setAttributes($values, $safeOnly = true): void
    {
        parent::setAttributes(is_array($values) ? $this->normalize($values) : $values, $safeOnly);
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function normalize(array $values): array
    {
        if (array_key_exists('digestLevels', $values)) {
            $levels = $values['digestLevels'];
            $values['digestLevels'] = array_values(array_unique(array_filter(
                array_map(
                    fn($value) => is_string($value) ? strtolower(trim($value)) : '',
                    is_array($levels) ? $levels : [$levels],
                ),
                fn(string $value) => $value !== '',
            )));
        }

        foreach ($values as $name => $value) {
            if (!property_exists($this, $name) || is_array($value)) {
                continue;
            }

            $cast = self::cast($name, $value);

            if ($cast === null) {
                unset($values[$name]);
                continue;
            }

            $values[$name] = $cast[0];
        }

        return $values;
    }

    /**
     * @return array{0: mixed}|null Null when the posted value cannot be cast and the default
     *                              should stand — an empty string in a number box, most often,
     *                              which would otherwise be a TypeError against a typed int.
     */
    private static function cast(string $property, mixed $value): ?array
    {
        $type = (new ReflectionProperty(self::class, $property))->getType();

        if (!$type instanceof ReflectionNamedType) {
            return [$value];
        }

        return match ($type->getName()) {
            'bool' => [is_bool($value) ? $value : in_array(strtolower((string)$value), ['1', 'true', 'on', 'yes'], true)],
            'int' => is_numeric($value) ? [(int)$value] : null,
            'string' => is_scalar($value) || $value === null ? [(string)$value] : null,
            default => [$value],
        };
    }
}
