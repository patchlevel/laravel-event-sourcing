<?php

declare(strict_types=1);

namespace Patchlevel\LaravelEventSourcing\Tests\Application\Processor;

use Illuminate\Support\Facades\Mail;
use Patchlevel\EventSourcing\Attribute\Processor;
use Patchlevel\EventSourcing\Attribute\Subscribe;
use Patchlevel\LaravelEventSourcing\Tests\Application\Events\ProfileCreated;
use Patchlevel\LaravelEventSourcing\Tests\Application\Mail\WelcomeMail;

/**
 * Uses the facade and not an injected mailer: the subscribers are created once and kept by the
 * subscription engine, so an injected mailer would not be replaced by `Mail::fake()` afterwards.
 */
#[Processor('welcome_mail')]
final class SendWelcomeMailProcessor
{
    #[Subscribe(ProfileCreated::class)]
    public function onProfileCreated(ProfileCreated $event): void
    {
        Mail::to($event->email)->send(new WelcomeMail($event->name));
    }
}
