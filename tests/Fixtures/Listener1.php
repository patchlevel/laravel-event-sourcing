<?php

namespace Patchlevel\LaravelEventSourcing\Tests\Fixtures;

use Patchlevel\EventSourcing\Attribute\Subscribe;
use Patchlevel\EventSourcing\Message\Message;

class Listener1
{
    public Message|null $lastMessage = null;

    #[Subscribe(ProfileCreated::class)]
    public function __invoke(Message $message): void
    {
        $this->lastMessage = $message;
    }
}
