<?php

declare(strict_types=1);

namespace Patchlevel\LaravelEventSourcing;

use Patchlevel\EventSourcing\Aggregate\AggregateRootId;
use Patchlevel\EventSourcing\Aggregate\BasicAggregateRoot;
use Patchlevel\EventSourcing\Repository\Repository;
use Patchlevel\EventSourcing\Repository\RepositoryManager;

abstract class AggregateRoot extends BasicAggregateRoot
{
    public static function load(AggregateRootId $id): static
    {
        return self::repository()->load($id);
    }

    public static function has(AggregateRootId $id): bool
    {
        return self::repository()->has($id);
    }

    public function save(): void
    {
        self::repository()->save($this);
    }

    /** @return Repository<static> */
    public static function repository(): Repository
    {
        return app(RepositoryManager::class)->get(static::class);
    }
}
