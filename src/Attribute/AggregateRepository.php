<?php

declare(strict_types=1);

namespace Patchlevel\LaravelEventSourcing\Attribute;

use Attribute;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Container\ContextualAttribute;
use Patchlevel\EventSourcing\Aggregate\AggregateRoot;
use Patchlevel\EventSourcing\Repository\Repository;
use Patchlevel\EventSourcing\Repository\RepositoryManager;

/** @template T of AggregateRoot */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class AggregateRepository implements ContextualAttribute
{
    /** @param class-string<T> $aggregateClass */
    public function __construct(
        private string $aggregateClass,
    ) {
    }

    /**
     * @param self<T> $attribute
     *
     * @return Repository<T>
     */
    public static function resolve(self $attribute, Container $container): Repository
    {
        return $container->get(RepositoryManager::class)->get($attribute->aggregateClass);
    }
}
