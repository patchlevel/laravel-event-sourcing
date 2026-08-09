<?php

declare(strict_types=1);

namespace Patchlevel\LaravelEventSourcing\EventBus;

use Illuminate\Contracts\Events\Dispatcher;
use Patchlevel\EventSourcing\EventBus\EventBus;
use Patchlevel\EventSourcing\Message\Message;
use Psr\Log\LoggerInterface;

/**
 * Event bus that pushes every message through the laravel event dispatcher.
 *
 * The message is dispatched under the name of the concrete event class, so listeners can be
 * registered for the event they care about instead of for the generic message. Both the event
 * and the message are passed to the listener:
 *
 * ```php
 * Event::listen(ProfileCreated::class, function (ProfileCreated $event, Message $message): void {});
 * ```
 */
final class IlluminateEventBus implements EventBus
{
    public function __construct(
        private readonly Dispatcher $dispatcher,
        private readonly LoggerInterface|null $logger = null,
    ) {
    }

    /** @param Message<object> ...$messages */
    public function dispatch(Message ...$messages): void
    {
        foreach ($messages as $message) {
            $event = $message->event();

            $this->logger?->debug('EventBus: dispatch message', ['event' => $event::class]);

            $this->dispatcher->dispatch($event::class, [$event, $message]);
        }
    }
}
