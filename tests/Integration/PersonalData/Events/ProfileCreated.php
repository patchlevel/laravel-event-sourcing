<?php

declare(strict_types=1);

namespace Patchlevel\LaravelEventSourcing\Tests\Integration\PersonalData\Events;

use Patchlevel\EventSourcing\Attribute\Event;
use Patchlevel\LaravelEventSourcing\Tests\Integration\PersonalData\ProfileId;
use Patchlevel\Hydrator\Attribute\DataSubjectId;
use Patchlevel\Hydrator\Attribute\PersonalData;

#[Event('profile.created')]
final class ProfileCreated
{
    public function __construct(
        #[DataSubjectId]
        public ProfileId $profileId,
        #[PersonalData(fallback: 'unknown')]
        public string $name,
    ) {
    }
}
