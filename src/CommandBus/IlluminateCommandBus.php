<?php

declare(strict_types=1);

namespace Patchlevel\LaravelEventSourcing\CommandBus;

use Illuminate\Contracts\Bus\Dispatcher;
use Patchlevel\EventSourcing\CommandBus\CommandBus;
use Patchlevel\EventSourcing\CommandBus\HandlerNotFound;
use Psr\Log\LoggerInterface;

/**
 * Command bus that hands the command over to the laravel bus. Commands that implement
 * `Illuminate\Contracts\Queue\ShouldQueue` are pushed to the queue by laravel, everything
 * else is handled in the current process.
 */
final class IlluminateCommandBus implements CommandBus
{
    public function __construct(
        private readonly Dispatcher $dispatcher,
        private readonly LoggerInterface|null $logger = null,
    ) {
    }

    /** @throws HandlerNotFound */
    public function dispatch(object $command): void
    {
        $this->logger?->debug('CommandBus: dispatch command', ['command' => $command::class]);

        // Without a mapped handler laravel falls back to calling the command itself. A command
        // is handled by its aggregate, so that is never what we want and reported as such.
        if (!$this->dispatcher->hasCommandHandler($command)) {
            throw new HandlerNotFound($command::class);
        }

        $this->dispatcher->dispatch($command);
    }
}
