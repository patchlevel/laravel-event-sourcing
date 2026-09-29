<?php

declare(strict_types=1);

namespace Patchlevel\LaravelEventSourcing\QueryBus;

use Illuminate\Contracts\Bus\Dispatcher;
use Patchlevel\EventSourcing\QueryBus\InvalidQueryHandler;
use Patchlevel\EventSourcing\QueryBus\QueryBus;
use Psr\Log\LoggerInterface;

/**
 * Query bus that resolves the handler over the laravel bus. A query always needs its result,
 * so it is never sent to a queue.
 */
final class IlluminateQueryBus implements QueryBus
{
    public function __construct(
        private readonly Dispatcher $dispatcher,
        private readonly LoggerInterface|null $logger = null,
    ) {
    }

    /** @throws InvalidQueryHandler */
    public function dispatch(object $query): mixed
    {
        $this->logger?->debug('QueryBus: dispatch query', ['query' => $query::class]);

        // Without a mapped handler laravel falls back to calling the query itself. A query is
        // answered by a subscriber, so that is never what we want and reported as such.
        if (!$this->dispatcher->hasCommandHandler($query)) {
            throw InvalidQueryHandler::noHandler($query::class);
        }

        return $this->dispatcher->dispatchNow($query);
    }
}
