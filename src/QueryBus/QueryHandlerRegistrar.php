<?php

declare(strict_types=1);

namespace Patchlevel\LaravelEventSourcing\QueryBus;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Container\Container;
use Patchlevel\EventSourcing\QueryBus\HandlerFinder;
use Patchlevel\EventSourcing\QueryBus\InvalidQueryHandler;

use function array_key_exists;
use function sprintf;

/** Registers the `#[Answer]` methods of all subscribers as handlers on the laravel bus. */
final class QueryHandlerRegistrar
{
    public function __construct(
        private readonly Container $container,
        private readonly Dispatcher $dispatcher,
    ) {
    }

    /**
     * @param iterable<class-string> $subscriberClasses
     *
     * @throws InvalidQueryHandler
     */
    public function register(iterable $subscriberClasses): void
    {
        /** @var array<class-string, string> $map */
        $map = [];

        foreach ($subscriberClasses as $subscriberClass) {
            foreach (HandlerFinder::findInClass($subscriberClass) as $handler) {
                if (array_key_exists($handler->queryClass, $map)) {
                    throw InvalidQueryHandler::multipleHandler($handler->queryClass);
                }

                $serviceId = sprintf(
                    'event_sourcing.query_handler.%s.%s',
                    $subscriberClass,
                    $handler->method,
                );

                $this->registerHandler($serviceId, $subscriberClass, $handler->method, $handler->static);

                $map[$handler->queryClass] = $serviceId;
            }
        }

        $this->dispatcher->map($map);
    }

    /** @param class-string $subscriberClass */
    private function registerHandler(
        string $serviceId,
        string $subscriberClass,
        string $method,
        bool $static,
    ): void {
        $this->container->singleton(
            $serviceId,
            static fn (Container $container) => new QueryHandler(
                $container,
                $subscriberClass,
                $method,
                $static,
            ),
        );
    }
}
