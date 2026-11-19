<?php

declare(strict_types=1);

namespace JustinHoltWeb\Freelog\Models;

use CraftCms\Cms\Plugin\PluginSettings;
use CraftCms\Cms\Support\Env;
use Illuminate\Validation\Rule;

use function CraftCms\Cms\t;

/**
 * Plugin settings, in project config under the same keys as Freelog 5. The digest's per-file read
 * positions are state, not configuration, and live in the `freelog_digests` table.
 *
 * Nothing here is `required`. A `required` rule fails the whole settings save, so one unfilled
 * box would block saving every other setting on a fresh install.
 */
class Settings extends PluginSettings
{
    public const string DIGEST_DAILY = 'daily';

    public const string DIGEST_WEEKLY = 'weekly';

    /** Every level the viewer knows, most severe first. */
    public const array LEVELS = ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'];

    /** What the digest reports by default: the levels that mean something broke. */
    public const array DIGEST_DEFAULT_LEVELS = ['emergency', 'alert', 'critical', 'error'];

    /** Email a digest of new error-level log entries on a schedule. */
    public bool $digestEnabled = false;

    /** `daily` or `weekly`. */
    public string $digestFrequency = self::DIGEST_DAILY;

    /** ISO day of the week for a weekly digest: 1 is Monday, 7 is Sunday. */
    public int $digestWeekday = 1;

    /**
     * Hour of the day, 0–23, in the system time zone, after which the digest for the day or week
     * becomes due. "After", not "at": a site whose scheduler missed the hour still sends later in
     * the same period, once.
     */
    public int $digestHour = 8;

    /**
     * Who gets it: email addresses separated by commas or new lines, or an environment variable
     * (`$FREELOG_DIGEST_RECIPIENTS`) holding the same. Set by an admin — the digest carries log
     * contents off the server.
     *
     * Nullable because Laravel's ConvertEmptyStringsToNull middleware turns a blank box into null.
     */
    public ?string $digestRecipients = null;

    /**
     * Send a digest even when no new entries were logged. Off by default: an "all clear" every
     * morning is an email people learn to stop opening.
     */
    public bool $digestSendWhenEmpty = false;

    /**
     * Levels the digest reports. Empty means the default: emergency, alert, critical and error.
     *
     * @var list<string>
     */
    public array $digestLevels = self::DIGEST_DEFAULT_LEVELS;

    /**
     * Leave out 4xx HTTP exceptions. Craft 5 logged every not-found, forbidden and bad request at
     * error level (Yii's `HttpException:404` category); Craft 6 leaves HTTP exceptions unreported, so
     * this now matters for Craft 5 logs kept after an upgrade and for a site whose exception
     * handler reports them anyway.
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
     * and queue it, for sites where nothing runs Laravel's scheduler.
     */
    public bool $digestWebTrigger = true;

    public function getRules(): array
    {
        return [
            'digestEnabled' => ['boolean'],
            'digestSendWhenEmpty' => ['boolean'],
            'digestIgnoreClientErrors' => ['boolean'],
            'digestIncludeTraces' => ['boolean'],
            'digestWebTrigger' => ['boolean'],
            'digestFrequency' => [Rule::in([self::DIGEST_DAILY, self::DIGEST_WEEKLY])],
            'digestWeekday' => ['integer', 'min:1', 'max:7'],
            'digestHour' => ['integer', 'min:0', 'max:23'],
            'digestRecipients' => ['nullable', 'string'],
            'digestLevels' => ['array'],
            'digestLevels.*' => [Rule::in(self::LEVELS)],
        ];
    }

    public function getMessages(): array
    {
        return [
            'digestLevels.*.in' => t('“:input” is not a log level Freelog knows.', category: 'freelog'),
        ];
    }

    public function attributeLabels(): array
    {
        return [
            'digestEnabled' => t('Email an error digest', category: 'freelog'),
            'digestFrequency' => t('How often', category: 'freelog'),
            'digestWeekday' => t('Day of the week', category: 'freelog'),
            'digestHour' => t('Hour', category: 'freelog'),
            'digestRecipients' => t('Recipients', category: 'freelog'),
            'digestSendWhenEmpty' => t('Send even when there is nothing new', category: 'freelog'),
            'digestLevels' => t('Levels to report', category: 'freelog'),
            'digestIgnoreClientErrors' => t('Leave out 4xx HTTP errors', category: 'freelog'),
            'digestIncludeTraces' => t('Include stack traces', category: 'freelog'),
            'digestWebTrigger' => t('Check from web requests too', category: 'freelog'),
        ];
    }

    /**
     * The rules, then the recipients — which a rule string can't check, because the value may be
     * an environment variable. It is checked for what it resolves to here, and again at send
     * time, because the value can differ between this environment and the one that sends.
     */
    public function validate(array|string|null $attributeNames = null, bool $clearErrors = true, bool $throw = false): bool
    {
        parent::validate($attributeNames, $clearErrors, $throw);

        if ($attributeNames === null || in_array('digestRecipients', (array) $attributeNames, true)) {
            foreach ($this->recipientList(true) as $address) {
                $this->errors()->add('digestRecipients', t('“{address}” is not an email address.', ['address' => $address], category: 'freelog'));
            }
        }

        return $this->errors()->isEmpty();
    }

    /**
     * The digest's recipients, parsed: environment variable resolved, split on commas, semicolons
     * and whitespace, de-duplicated.
     *
     * @param  bool  $invalid  Return the entries that are *not* email addresses instead.
     * @return list<string>
     */
    public function recipientList(bool $invalid = false): array
    {
        $raw = (string) Env::parse($this->digestRecipients);
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
     * @return list<string>
     */
    public function levelList(): array
    {
        return $this->digestLevels !== [] ? $this->digestLevels : self::DIGEST_DEFAULT_LEVELS;
    }

    /**
     * Posted and project-config values arrive in every shape: numbers as strings, an unticked
     * checkbox group as `null` or `['']`, a blank number box as `null`. Normalise them before
     * Craft assigns them to typed properties.
     *
     * @param  array<string, mixed>|mixed  $values
     */
    public function setAttributes($values): void
    {
        parent::setAttributes(is_array($values) ? self::normalize($values) : $values);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public static function normalize(array $values): array
    {
        if (array_key_exists('digestLevels', $values)) {
            $levels = $values['digestLevels'];
            $values['digestLevels'] = array_values(array_unique(array_filter(
                array_map(
                    fn ($value) => is_string($value) ? strtolower(trim($value)) : '',
                    is_array($levels) ? $levels : [$levels],
                ),
                fn (string $value) => $value !== '',
            )));
        }

        foreach (['digestWeekday', 'digestHour'] as $name) {
            if (array_key_exists($name, $values)) {
                if (is_numeric($values[$name])) {
                    $values[$name] = (int) $values[$name];
                } else {
                    // A blank number box: keep the current value rather than a TypeError.
                    unset($values[$name]);
                }
            }
        }

        foreach (['digestEnabled', 'digestSendWhenEmpty', 'digestIgnoreClientErrors', 'digestIncludeTraces', 'digestWebTrigger'] as $name) {
            if (array_key_exists($name, $values) && ! is_bool($values[$name])) {
                $values[$name] = in_array(strtolower(trim((string) $values[$name])), ['1', 'true', 'on', 'yes'], true);
            }
        }

        if (array_key_exists('digestFrequency', $values) && ! is_string($values['digestFrequency'])) {
            unset($values['digestFrequency']);
        }

        return $values;
    }
}
