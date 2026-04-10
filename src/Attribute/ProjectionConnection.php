<?php

declare(strict_types=1);

namespace Patchlevel\LaravelEventSourcing\Attribute;

use Attribute;
use Doctrine\DBAL\Connection as DBALConnection;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Container\ContextualAttribute;
use Illuminate\Database\Connection as IlluminateConnection;

#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class ProjectionConnection implements ContextualAttribute
{
    public static function resolve(self $attribute, Container $container): DBALConnection|IlluminateConnection
    {
        return $container->get('event_sourcing.public_connection');
    }
}
