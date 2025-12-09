<?php

declare(strict_types=1);

namespace Patchlevel\LaravelEventSourcing\Facade;

use Illuminate\Support\Facades\Facade;
use Patchlevel\EventSourcing\QueryBus\QueryBus as EventSourcingQueryBus;

/** @method static mixed dispatch(object $command) */
class QueryBus extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return EventSourcingQueryBus::class;
    }
}
