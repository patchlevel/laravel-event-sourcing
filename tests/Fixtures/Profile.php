<?php

namespace Patchlevel\LaravelEventSourcing\Tests\Fixtures;

use Patchlevel\EventSourcing\Aggregate\CustomId;
use Patchlevel\EventSourcing\Attribute\Aggregate;
use Patchlevel\EventSourcing\Attribute\Apply;
use Patchlevel\EventSourcing\Attribute\Handle;
use Patchlevel\EventSourcing\Attribute\Id;
use Patchlevel\Hydrator\Hydrator;
use Patchlevel\LaravelEventSourcing\AggregateRoot;

#[Aggregate('profile')]
class Profile extends AggregateRoot
{
    #[Id]
    private CustomId $id;

    #[Handle]
    public static function create(
        CreateProfile $command,
        Hydrator $hydrator,
    ): self
    {
        $profile = new self();
        $profile->recordThat(new ProfileCreated($command->id));

        return $profile;
    }

    #[Apply]
    protected function applyProfileCreated(ProfileCreated $event): void
    {
        $this->id = $event->id;
    }
}
