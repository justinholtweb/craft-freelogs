<?php

declare(strict_types=1);

namespace JustinHoltWeb\Freelog\Mail;

use CraftCms\Cms\Email\Mailables\CraftMailable;
use CraftCms\Cms\View\TemplateMode;
use Symfony\Component\Mime\Email;

use function CraftCms\Cms\template;

/**
 * The error digest as an email: an HTML and a plain-text part, rendered from the plugin's Twig
 * templates (`resources/templates/_emails/digest.twig` and `digest.txt.twig`) in control-panel
 * template mode, so they resolve the same from a web request, the scheduler or a queue worker.
 *
 * A {@see CraftMailable}, so the from address, reply-to and mailer come from Craft's email
 * settings, like Craft's own system messages.
 */
class DigestMail extends CraftMailable
{
    /**
     * @param  array<string, mixed>  $variables
     */
    public function __construct(
        string $subject,
        public array $variables,
    ) {
        $this->subject = $subject;
    }

    public function build(): static
    {
        $html = template('freelog/_emails/digest', $this->variables, TemplateMode::Cp);
        $text = template('freelog/_emails/digest.txt', $this->variables, TemplateMode::Cp);

        // A Mailable takes a text part only as a view name, and Craft's template roots aren't
        // Laravel view paths; set it on the message itself.
        return $this
            ->html($html)
            ->withSymfonyMessage(fn (Email $message) => $message->text($text));
    }
}
