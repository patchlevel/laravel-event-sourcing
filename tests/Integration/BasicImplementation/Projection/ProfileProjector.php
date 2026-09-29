<?php

declare(strict_types=1);

namespace Patchlevel\LaravelEventSourcing\Tests\Integration\BasicImplementation\Projection;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Patchlevel\EventSourcing\Attribute\Answer;
use Patchlevel\EventSourcing\Attribute\Projector;
use Patchlevel\EventSourcing\Attribute\Setup;
use Patchlevel\EventSourcing\Attribute\Subscribe;
use Patchlevel\EventSourcing\Attribute\Teardown;
use Patchlevel\LaravelEventSourcing\Tests\Integration\BasicImplementation\Events\NameChanged;
use Patchlevel\LaravelEventSourcing\Tests\Integration\BasicImplementation\Events\ProfileCreated;
use Patchlevel\LaravelEventSourcing\Tests\Integration\BasicImplementation\Query\QueryProfileName;

#[Projector('profile-1')]
final class ProfileProjector
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    #[Setup]
    public function create(): void
    {
        $this->connection->getSchemaBuilder()->create('projection_profile', function (Blueprint $table): void {
            $table->string('id', 36);
            $table->string('name', 255);
            $table->primary('id');
        });
    }

    #[Teardown]
    public function drop(): void
    {
        $this->connection->getSchemaBuilder()->drop('projection_profile');
    }

    #[Subscribe(ProfileCreated::class)]
    public function handleProfileCreated(ProfileCreated $profileCreated): void
    {
        $this->connection->statement(
            'INSERT INTO projection_profile (id, name) VALUES(:id, :name);',
            [
                'id' => $profileCreated->profileId->toString(),
                'name' => $profileCreated->name,
            ],
        );
    }

    #[Subscribe(NameChanged::class)]
    public function handleNameChanged(NameChanged $nameChanged): void
    {
        $this->connection->statement(
            'UPDATE projection_profile SET name = :name WHERE id = :id;',
            [
                'id' => $nameChanged->id->toString(),
                'name' => $nameChanged->name,
            ],
        );
    }

    #[Answer]
    public function getProfileName(QueryProfileName $queryProfileName): string
    {
        return $this->connection->selectOne(
            'SELECT name FROM projection_profile WHERE id = :id',
            ['id' => $queryProfileName->id->toString()],
        )->name;
    }
}
