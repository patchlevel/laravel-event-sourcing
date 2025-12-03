<?php

namespace Patchlevel\LaravelEventSourcing\Tests\Fixtures;

use Patchlevel\EventSourcing\Attribute\Subscribe;
use Patchlevel\EventSourcing\Message\Message;
use Patchlevel\LaravelEventSourcing\Attribute\AsListener;

#[AsListener]
class Listener1
{
    #[Subscribe(ProfileCreated::class)]
    public function __invoke(Message $message): void
    {
        // do nothing
    }
}
