<?php

declare(strict_types=1);

namespace Patchlevel\LaravelEventSourcing\Tests\Application\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class WelcomeMail extends Mailable
{
    public function __construct(
        public readonly string $name,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Welcome');
    }

    public function content(): Content
    {
        return new Content(htmlString: '<p>Welcome ' . e($this->name) . '!</p>');
    }
}
