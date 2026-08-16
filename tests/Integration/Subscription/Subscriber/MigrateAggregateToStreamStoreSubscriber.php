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
use Patchlevel\EventSourcing\Message\Pipe;
use Patchlevel\EventSourcing\Message\Translator\AggregateToStreamHeaderTranslator;
use Patchlevel\EventSourcing\Message\Translator\Translator;
use Patchlevel\EventSourcing\Store\StreamStore;
use Patchlevel\EventSourcing\Subscription\RunMode;
use Patchlevel\EventSourcing\Subscription\Subscriber\BatchableSubscriber;

use function count;

#[Subscriber('migrate', RunMode::Once)]
final class MigrateAggregateToStreamStoreSubscriber implements BatchableSubscriber
{
    /** @var list<Message> */
    private array $messages = [];

    /** @var list<Translator> */
    private readonly array $middlewares;

    public function __construct(
        private readonly StreamStore $targetStore,
        private readonly Connection $connection,
    ) {
        $this->middlewares = [new AggregateToStreamHeaderTranslator()];
    }

    #[Subscribe('*')]
    public function handle(Message $message): void
    {
        $this->messages[] = $message;
    }

    public function beginBatch(): void
    {
        $this->messages = [];
    }

    public function commitBatch(): void
    {
        $pipeline = new Pipe($this->messages, ...$this->middlewares);
        $this->messages = [];

        $this->targetStore->save(...$pipeline);
    }

    public function rollbackBatch(): void
    {
        $this->messages = [];
    }

    public function forceCommit(): bool
    {
        return count($this->messages) >= 10_000;
    }

    #[Setup]
    public function setup(): void
    {
        $this->connection->getSchemaBuilder()->create('new_eventstore', function (Blueprint $table): void {
            $table->bigInteger('id', true);
            $table->string('stream', 255);
            $table->integer('playhead')->nullable();
            $table->string('event_id', 255);
            $table->string('event_name', 255);
            $table->json('event_payload');
            $table->dateTime('recorded_on');
            $table->boolean('archived')->default(false);
            $table->json('custom_headers');

            $table->unique('event_id');
            $table->unique(['stream', 'playhead']);
            $table->unique(['stream', 'playhead', 'archived']);
        });
    }

    #[Teardown]
    public function teardown(): void
    {
        $this->connection->getSchemaBuilder()->drop('new_eventstore');
    }
}
