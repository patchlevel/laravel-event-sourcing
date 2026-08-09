<?php

declare(strict_types=1);

namespace Patchlevel\LaravelEventSourcing\QueryBus;

use Illuminate\Contracts\Container\Container;

/**
 * Adapter between the laravel bus and an `#[Answer]` method.
 *
 * The laravel bus calls `handle()` or `__invoke()` on the handler, while an answer method can have
 * any name. The subscriber is resolved on invocation, so registering the handlers does not build
 * every subscriber.
 */
final class QueryHandler
{
    /** @param class-string $subscriberClass */
    public function __construct(
        private readonly Container $container,
        private readonly string $subscriberClass,
        private readonly string $method,
        private readonly bool $static,
    ) {
    }

    public function __invoke(object $query): mixed
    {
        if ($this->static) {
            $subscriberClass = $this->subscriberClass;

            return $subscriberClass::{$this->method}($query);
        }

        return $this->container->get($this->subscriberClass)->{$this->method}($query);
    }
}
