<?php

declare(strict_types=1);

namespace Patchlevel\LaravelEventSourcing\Tests\Integration\BasicImplementation\Events;

use Patchlevel\EventSourcing\Attribute\Event;
use Patchlevel\LaravelEventSourcing\Tests\Integration\BasicImplementation\ProfileId;

#[Event('profile.created', aliases: ['profile_was_created'])]
final class ProfileCreated
{
    public function __construct(
        public ProfileId $profileId,
        public string $name,
    ) {
    }
}
