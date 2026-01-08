<?php

declare(strict_types=1);

namespace Patchlevel\LaravelEventSourcing\Attribute;

use Attribute;
use Doctrine\DBAL\Connection;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Container\ContextualAttribute;

#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class ProjectionConnection implements ContextualAttribute
{
    public static function resolve(self $attribute, Container $container): Connection
    {
        return $container->get('event_sourcing.dbal_public_connection');
    }
}
