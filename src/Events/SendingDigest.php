<?php

declare(strict_types=1);

namespace JustinHoltWeb\Freelog\Events;

use CraftCms\Cms\Shared\Concerns\ValidatableEvent;

/**
 * Dispatched just before an error digest is sent — scheduled or test.
 *
 * Change who gets it, retitle it, add to what the templates see, or set `isValid` to false to
 * stop it. A cancelled scheduled digest leaves the "last sent" marker where it was, so the next
 * run asks again.
 *
 * ```php
 * Event::listen(SendingDigest::class, function (SendingDigest $event) {
 *     $event->recipients[] = 'ops@example.com';
 * });
 * ```
 *
 * Replaces Freelog 5's `Digest::EVENT_BEFORE_SEND` / `DigestEvent`.
 */
class SendingDigest
{
    use ValidatableEvent;

    /**
     * @param  list<string>  $recipients
     * @param  array<string, mixed>  $variables  What `_emails/digest.twig` and `_emails/digest.txt.twig` receive.
     * @param  bool  $isTest  True for "Send a test digest now".
     */
    public function __construct(
        public array $recipients,
        public string $subject,
        public array $variables,
        public bool $isTest = false,
    ) {}
}
