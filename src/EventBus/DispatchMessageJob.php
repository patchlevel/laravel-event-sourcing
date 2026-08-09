<?php

declare(strict_types=1);

namespace Patchlevel\LaravelEventSourcing\EventBus;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Patchlevel\EventSourcing\Message\Message;

/**
 * Carries a single message to the queue worker, where it is handed to the synchronous event bus.
 *
 * The message is transported with the php serializer, like every other laravel job, so events and
 * headers have to be serializable.
 */
final class DispatchMessageJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    /** @param Message<object> $message */
    public function __construct(
        public readonly Message $message,
    ) {
    }

    /**
     * The synchronous bus is required explicitly. Asking for the EventBus interface would resolve
     * back to the queued bus and push the message onto the queue again.
     */
    public function handle(IlluminateEventBus $eventBus): void
    {
        $eventBus->dispatch($this->message);
    }
}
