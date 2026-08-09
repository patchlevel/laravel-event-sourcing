<?php

declare(strict_types=1);

namespace Patchlevel\LaravelEventSourcing\CommandBus;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Container\Container;
use Patchlevel\EventSourcing\Aggregate\AggregateRoot;
use Patchlevel\EventSourcing\CommandBus\Handler\CreateAggregateHandler;
use Patchlevel\EventSourcing\CommandBus\Handler\DefaultParameterResolver;
use Patchlevel\EventSourcing\CommandBus\Handler\UpdateAggregateHandler;
use Patchlevel\EventSourcing\CommandBus\HandlerFinder;
use Patchlevel\EventSourcing\CommandBus\MultipleHandlersFound;
use Patchlevel\EventSourcing\Metadata\AggregateRoot\AggregateRootRegistry;
use Patchlevel\EventSourcing\Repository\RepositoryManager;

use function array_key_exists;
use function sprintf;
use function strtolower;

/**
 * Registers the `#[Handle]` methods of all aggregates as handlers on the laravel bus.
 *
 * Each handler gets its own binding, because the laravel bus maps a command to a container key
 * and one handler class is used for many aggregates and methods.
 */
final class AggregateHandlerRegistrar
{
    public function __construct(
        private readonly Container $container,
        private readonly Dispatcher $dispatcher,
    ) {
    }

    /** @throws MultipleHandlersFound */
    public function register(AggregateRootRegistry $aggregateRootRegistry): void
    {
        /** @var array<class-string, string> $map */
        $map = [];

        foreach ($aggregateRootRegistry->aggregateClasses() as $aggregateName => $aggregateClass) {
            foreach (HandlerFinder::findInClass($aggregateClass) as $handler) {
                if (array_key_exists($handler->commandClass, $map)) {
                    throw new MultipleHandlersFound($handler->commandClass);
                }

                $serviceId = sprintf(
                    'event_sourcing.command_handler.%s.%s',
                    $aggregateName,
                    strtolower($handler->method),
                );

                $this->registerHandler($serviceId, $aggregateClass, $handler->method, $handler->static);

                $map[$handler->commandClass] = $serviceId;
            }
        }

        $this->dispatcher->map($map);
    }

    /** @param class-string<AggregateRoot> $aggregateClass */
    private function registerHandler(
        string $serviceId,
        string $aggregateClass,
        string $method,
        bool $static,
    ): void {
        $this->container->singleton(
            $serviceId,
            static function (Container $container) use ($aggregateClass, $method, $static) {
                $repositoryManager = $container->get(RepositoryManager::class);
                $parameterResolver = new DefaultParameterResolver($container);

                if ($static) {
                    return new CreateAggregateHandler(
                        $repositoryManager,
                        $aggregateClass,
                        $method,
                        $parameterResolver,
                    );
                }

                return new UpdateAggregateHandler(
                    $repositoryManager,
                    $aggregateClass,
                    $method,
                    $parameterResolver,
                );
            },
        );
    }
}
