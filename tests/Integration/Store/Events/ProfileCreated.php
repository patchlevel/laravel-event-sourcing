<?php

declare(strict_types=1);

namespace Patchlevel\LaravelEventSourcing\Tests\Integration\Store\Events;

use Patchlevel\EventSourcing\Attribute\Event;
use Patchlevel\LaravelEventSourcing\Tests\Integration\Store\ProfileId;

#[Event('profile.created')]
final class ProfileCreated
{
    public function __construct(
        public ProfileId $profileId,
        public string $name,
    ) {
    }
}
