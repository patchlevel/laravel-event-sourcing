<?php

declare(strict_types=1);

namespace Patchlevel\LaravelEventSourcing\Facade;

use Illuminate\Support\Facades\Facade;
use Patchlevel\EventSourcing\Aggregate\AggregateRoot;
use Patchlevel\EventSourcing\Repository\Repository as EventSourcingRepository;
use Patchlevel\EventSourcing\Repository\RepositoryManager;

/**
 * @template T of AggregateRoot
 * @method static EventSourcingRepository<T> get(class-string<T> $aggregateClass): T
 */
class Repository extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return RepositoryManager::class;
    }
}
