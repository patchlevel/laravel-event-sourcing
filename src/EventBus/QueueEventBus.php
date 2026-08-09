<?php

declare(strict_types=1);

namespace Patchlevel\LaravelEventSourcing\EventBus;

use Illuminate\Contracts\Queue\Factory;
use Patchlevel\EventSourcing\EventBus\EventBus;
use Patchlevel\EventSourcing\Message\Message;
use Psr\Log\LoggerInterface;

use function array_map;
use function count;

/**
 * Event bus that pushes the messages onto a laravel queue, where they are picked up by a worker
 * and handed to the synchronous event bus.
 *
 * A repository save records all messages of one aggregate at once, so they are pushed in bulk:
 * the whole batch needs a single round trip to the queue backend instead of one per message.
 */
final class QueueEventBus implements EventBus
{
    public function __construct(
        private readonly Factory $queue,
        private readonly string|null $connection = null,
        private readonly string|null $queueName = null,
        private readonly LoggerInterface|null $logger = null,
    ) {
    }

    /** @param Message<object> ...$messages */
    public function dispatch(Message ...$messages): void
    {
        if ($messages === []) {
            return;
        }

        $this->logger?->debug('EventBus: push messages to queue', ['count' => count($messages)]);

        $this->queue->connection($this->connection)->bulk(
            array_map(
                static fn (Message $message) => new DispatchMessageJob($message),
                $messages,
            ),
            '',
            $this->queueName,
        );
    }
}
