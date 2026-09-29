<?php

declare(strict_types=1);

namespace Patchlevel\LaravelEventSourcing\Tests\Integration\BasicImplementation\Processor;

use Patchlevel\EventSourcing\Attribute\Processor;
use Patchlevel\EventSourcing\Attribute\Subscribe;
use Patchlevel\EventSourcing\Message\Message;
use Patchlevel\LaravelEventSourcing\Tests\Integration\BasicImplementation\Events\ProfileCreated;
use Patchlevel\LaravelEventSourcing\Tests\Integration\BasicImplementation\SendEmailMock;

#[Processor('send_email')]
final class SendEmailProcessor
{
    #[Subscribe(ProfileCreated::class)]
    public function onProfileCreated(Message $message): void
    {
        SendEmailMock::send();
    }
}
