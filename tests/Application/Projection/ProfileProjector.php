<?php

declare(strict_types=1);

namespace Patchlevel\LaravelEventSourcing\Tests\Application\Projection;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Patchlevel\EventSourcing\Attribute\Answer;
use Patchlevel\EventSourcing\Attribute\Projector;
use Patchlevel\EventSourcing\Attribute\Setup;
use Patchlevel\EventSourcing\Attribute\Subscribe;
use Patchlevel\EventSourcing\Attribute\Teardown;
use Patchlevel\LaravelEventSourcing\Attribute\ProjectionConnection;
use Patchlevel\LaravelEventSourcing\Tests\Application\Events\NameChanged;
use Patchlevel\LaravelEventSourcing\Tests\Application\Events\ProfileCreated;
use Patchlevel\LaravelEventSourcing\Tests\Application\Query\QueryProfileName;

#[Projector('profile')]
final class ProfileProjector
{
    public function __construct(
        #[ProjectionConnection]
        public readonly Connection $connection,
    ) {
    }

    /** @return list<string> */
    public function names(): array
    {
        return $this->connection->table('projection_profile')->orderBy('name')->pluck('name')->all();
    }

    #[Setup]
    public function create(): void
    {
        $this->connection->getSchemaBuilder()->create('projection_profile', static function (Blueprint $table): void {
            $table->string('id', 36)->primary();
            $table->string('name', 255);
        });
    }

    #[Teardown]
    public function drop(): void
    {
        $this->connection->getSchemaBuilder()->dropIfExists('projection_profile');
    }

    #[Subscribe(ProfileCreated::class)]
    public function handleProfileCreated(ProfileCreated $event): void
    {
        $this->connection->table('projection_profile')->insert([
            'id' => $event->profileId->toString(),
            'name' => $event->name,
        ]);
    }

    #[Subscribe(NameChanged::class)]
    public function handleNameChanged(NameChanged $event): void
    {
        $this->connection->table('projection_profile')
            ->where('id', $event->profileId->toString())
            ->update(['name' => $event->name]);
    }

    #[Answer]
    public function profileName(QueryProfileName $query): string|null
    {
        return $this->connection->table('projection_profile')
            ->where('id', $query->profileId->toString())
            ->value('name');
    }
}
