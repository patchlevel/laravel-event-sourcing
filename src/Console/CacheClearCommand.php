<?php

declare(strict_types=1);

namespace Patchlevel\LaravelEventSourcing\Console;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Psr\SimpleCache\CacheInterface;

use function app;

#[Signature('event-sourcing:cache:clear')]
#[Description('Clear the event sourcing metadata cache')]
final class CacheClearCommand extends Command
{
    public function handle(): int
    {
        // the configured store is flushed as a whole, the metadata is kept under one key per class
        // and those keys cannot be enumerated. this is why the cache wants a store of its own.
        /** @var CacheInterface $cache */
        $cache = app('event_sourcing.cache');
        $cache->clear();

        $this->components->info('Event sourcing metadata cache cleared.');

        return self::SUCCESS;
    }
}
