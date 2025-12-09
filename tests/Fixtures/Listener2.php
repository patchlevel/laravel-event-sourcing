<?php

namespace Patchlevel\LaravelEventSourcing\Tests\Fixtures;

use Patchlevel\EventSourcing\Attribute\Subscribe;
use Patchlevel\EventSourcing\Message\Message;

class Listener2
{
    #[Subscribe(ProfileCreated::class)]
    public function __invoke(Message $message): void
    {
        // do nothing
    }
}
