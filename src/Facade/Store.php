<?php

declare(strict_types=1);

namespace Patchlevel\LaravelEventSourcing\Facade;

use Illuminate\Support\Facades\Facade;
use Patchlevel\EventSourcing\Message\Message;
use Patchlevel\EventSourcing\Store\Criteria\Criteria;
use Patchlevel\EventSourcing\Store\Store as EventSourcingStore;
use Patchlevel\EventSourcing\Store\Stream;

/**
 * @method static Stream load(Criteria|null $criteria = null, int|null $limit = null, int|null $offset = null, bool $backwards = false)
 * @method static int count(Criteria|null $criteria = null)
 * @method static mixed transactional(callable $callback)
 * @method static void save(Message ...$messages)
 */
class Store extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return EventSourcingStore::class;
    }
}
