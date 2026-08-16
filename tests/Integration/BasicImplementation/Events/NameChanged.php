<?php

declare(strict_types=1);

namespace Patchlevel\LaravelEventSourcing\Tests\Integration\BasicImplementation\Events;

use Patchlevel\EventSourcing\Attribute\Event;
use Patchlevel\LaravelEventSourcing\Tests\Integration\BasicImplementation\ProfileId;

#[Event('profile.name_changed')]
final class NameChanged
{
    public function __construct(
        public ProfileId $id,
        public string $name,
    ) {
    }
}
