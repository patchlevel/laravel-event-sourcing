<?php

declare(strict_types=1);

namespace Patchlevel\LaravelEventSourcing\Tests\Application;

use Patchlevel\EventSourcing\Attribute\Aggregate;
use Patchlevel\EventSourcing\Attribute\Apply;
use Patchlevel\EventSourcing\Attribute\Handle;
use Patchlevel\EventSourcing\Attribute\Id;
use Patchlevel\LaravelEventSourcing\AggregateRoot;
use Patchlevel\LaravelEventSourcing\Tests\Application\Command\ChangeName;
use Patchlevel\LaravelEventSourcing\Tests\Application\Command\CreateProfile;
use Patchlevel\LaravelEventSourcing\Tests\Application\Events\NameChanged;
use Patchlevel\LaravelEventSourcing\Tests\Application\Events\ProfileCreated;

#[Aggregate('profile')]
final class Profile extends AggregateRoot
{
    #[Id]
    private ProfileId $id;
    private string $name;

    #[Handle]
    public static function create(CreateProfile $command): self
    {
        $self = new self();
        $self->recordThat(new ProfileCreated($command->profileId, $command->name, $command->email));

        return $self;
    }

    #[Handle]
    public function changeName(ChangeName $command): void
    {
        $this->recordThat(new NameChanged($this->id, $command->name));
    }

    #[Apply]
    protected function applyProfileCreated(ProfileCreated $event): void
    {
        $this->id = $event->profileId;
        $this->name = $event->name;
    }

    #[Apply]
    protected function applyNameChanged(NameChanged $event): void
    {
        $this->name = $event->name;
    }

    public function name(): string
    {
        return $this->name;
    }
}
