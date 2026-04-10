<?php

declare(strict_types=1);

namespace Patchlevel\LaravelEventSourcing\Facade;

use Illuminate\Support\Facades\Facade;

class ProjectionConnection extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'event_sourcing.public_connection';
    }
}
