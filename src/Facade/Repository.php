<?php

declare(strict_types=1);

namespace Patchlevel\LaravelEventSourcing\Facade;

use Illuminate\Support\Facades\Facade;
use Patchlevel\EventSourcing\Repository\Repository as EventSourcingRepository;
use Patchlevel\EventSourcing\Repository\RepositoryManager;

/** @method static EventSourcingRepository get(string $aggregateClass) */
class Repository extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return RepositoryManager::class;
    }
}
