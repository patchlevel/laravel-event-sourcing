<?php

declare(strict_types=1);

namespace Patchlevel\LaravelEventSourcing\Facade;

use Illuminate\Support\Facades\Facade;
use Patchlevel\EventSourcing\CommandBus\CommandBus as EventSourcingCommandBus;

/** @method static void dispatch(object $command) */
class CommandBus extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return EventSourcingCommandBus::class;
    }
}
