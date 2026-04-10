<?php

declare(strict_types=1);

namespace Patchlevel\LaravelEventSourcing\Tests\Integration\PersonalData\Events;

use Patchlevel\EventSourcing\Attribute\Event;
use Patchlevel\LaravelEventSourcing\Tests\Integration\PersonalData\ProfileId;

#[Event('profile.personal_data_removed')]
final class PersonalDataRemoved
{
    public function __construct(public ProfileId $profileId)
    {
    }
}
