<?php

declare(strict_types=1);

namespace Patchlevel\LaravelEventSourcing\Tests\Integration\Subscription\Subscriber;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Patchlevel\EventSourcing\Attribute\Setup;
use Patchlevel\EventSourcing\Attribute\Subscribe;
use Patchlevel\EventSourcing\Attribute\Subscriber;
use Patchlevel\EventSourcing\Attribute\Teardown;
use Patchlevel\EventSourcing\Message\Message;
use Patchlevel\EventSourcing\Message\Reducer;
use Patchlevel\EventSourcing\Subscription\Lookup\Lookup;
use Patchlevel\EventSourcing\Subscription\RunMode;
use Patchlevel\EventSourcing\Subscription\Subscriber\SubscriberUtil;
use Patchlevel\LaravelEventSourcing\Tests\Integration\Subscription\Events\AdminPromoted;
use Patchlevel\LaravelEventSourcing\Tests\Integration\Subscription\Events\NameChanged;
use Patchlevel\LaravelEventSourcing\Tests\Integration\Subscription\Events\ProfileCreated;

#[Subscriber('lookup', RunMode::FromBeginning)]
final class LookupSubscriber
{
    use SubscriberUtil;

    public function __construct(
        private Connection $connection,
    ) {
    }

    #[Subscribe(AdminPromoted::class)]
    public function onAdminPromoted(AdminPromoted $event, Lookup $lookup): void
    {
        $messages = $lookup
            ->currentStream()
            ->events(
                ProfileCreated::class,
                NameChanged::class,
            )
            ->fetchAll();

        $state = (new Reducer())
            ->initState(['name' => null])
            ->when(ProfileCreated::class, static function (Message $message): array {
                return ['name' => $message->event()->name];
            })
            ->when(NameChanged::class, static function (Message $message): array {
                return ['name' => $message->event()->name];
            })
            ->reduce($messages);

        $this->connection->statement(<<<SQL
INSERT INTO {$this->tableName()} (id, name) VALUES (:id, :name);
SQL,
            [
                'id' => $event->profileId->toString(),
                'name' => $state['name'],
            ],
        );
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

    private function tableName(): string
    {
        return 'projection_' . $this->subscriberId();
    }
}
