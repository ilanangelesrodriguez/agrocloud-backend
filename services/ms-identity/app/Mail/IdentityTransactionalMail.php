<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Synchronous mailable, invoked inside the existing encrypted SendIdentityMail job.
 * Do not implement ShouldQueue here: the outer job owns retries and auditing.
 */
final class IdentityTransactionalMail extends Mailable
{
    public function __construct(
        private string $mailSubject,
        private string $mailKind,
        private string $plainBody,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->mailSubject);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.identity',
            text: 'emails.identity-text',
            with: IdentityMailPresenter::make($this->mailKind, $this->plainBody),
        );
    }
}
