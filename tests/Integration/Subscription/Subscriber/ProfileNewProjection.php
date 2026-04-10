<?php

declare(strict_types=1);

namespace Patchlevel\LaravelEventSourcing\Tests\Integration\Subscription\Subscriber;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Patchlevel\EventSourcing\Attribute\Projector;
use Patchlevel\EventSourcing\Attribute\Setup;
use Patchlevel\EventSourcing\Attribute\Subscribe;
use Patchlevel\EventSourcing\Attribute\Teardown;
use Patchlevel\EventSourcing\Subscription\Subscriber\SubscriberUtil;
use Patchlevel\LaravelEventSourcing\Tests\Integration\Subscription\Events\ProfileCreated;

#[Projector('profile_2')]
final class ProfileNewProjection
{
    use SubscriberUtil;

    public function __construct(
        private Connection $connection,
    ) {
    }

    #[Setup]
    public function create(): void
    {
        $this->connection->getSchemaBuilder()->create($this->tableName(), function (Blueprint $table): void {
            $table->string('id', 36);
            $table->string('firstname', 255);
            $table->primary('id');
        });
    }

    #[Teardown]
    public function drop(): void
    {
        $this->connection->getSchemaBuilder()->drop($this->tableName());
    }

    #[Subscribe(ProfileCreated::class)]
    public function handleProfileCreated(ProfileCreated $profileCreated): void
    {
        $this->connection->statement(
            'INSERT INTO ' . $this->tableName() . ' (id, firstname) VALUES(:id, :firstname);',
            [
                'id' => $profileCreated->profileId->toString(),
                'firstname' => $profileCreated->name,
            ],
        );
    }

    private function tableName(): string
    {
        return 'projection_' . $this->subscriberId();
    }
}
