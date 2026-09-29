<?php

declare(strict_types=1);

namespace Patchlevel\LaravelEventSourcing\Tests\Application\Events;

use Patchlevel\EventSourcing\Attribute\Event;
use Patchlevel\LaravelEventSourcing\Tests\Application\ProfileId;

#[Event('profile.created')]
final class ProfileCreated
{
    public function __construct(
        public ProfileId $profileId,
        public string $name,
        public string $email,
    ) {
    }
}
