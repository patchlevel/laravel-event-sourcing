<?php

declare(strict_types=1);

namespace Patchlevel\LaravelEventSourcing\Tests\Integration\Subscription\Subscriber;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Patchlevel\EventSourcing\Attribute\Projector;
use Patchlevel\EventSourcing\Attribute\Setup;
use Patchlevel\EventSourcing\Attribute\Subscribe;
use Patchlevel\EventSourcing\Attribute\Teardown;
use Patchlevel\EventSourcing\Subscription\Subscriber\BatchableSubscriber;
use Patchlevel\EventSourcing\Subscription\Subscriber\SubscriberUtil;
use Patchlevel\LaravelEventSourcing\Tests\Integration\Subscription\Events\ProfileCreated;

#[Projector('profile_1')]
final class ProfileProjection implements BatchableSubscriber
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
            $table->string('name', 255);
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
            'INSERT INTO ' . $this->tableName() . ' (id, name) VALUES(:id, :name);',
            [
                'id' => $profileCreated->profileId->toString(),
                'name' => $profileCreated->name,
            ],
        );
    }

    private function tableName(): string
    {
        return 'projection_' . $this->subscriberId();
    }

    public function beginBatch(): void
    {
        $this->connection->beginTransaction();
    }

    public function commitBatch(): void
    {
        $this->connection->commit();
    }

    public function rollbackBatch(): void
    {
        $this->connection->rollBack();
    }

    public function forceCommit(): bool
    {
        return false;
    }
}
