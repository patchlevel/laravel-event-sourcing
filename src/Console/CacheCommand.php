<?php

declare(strict_types=1);

namespace Patchlevel\LaravelEventSourcing\Console;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Patchlevel\EventSourcing\Metadata\AggregateRoot\AggregateRootMetadataFactory;
use Patchlevel\EventSourcing\Metadata\AggregateRoot\AggregateRootRegistry;
use Patchlevel\EventSourcing\Metadata\Event\EventMetadataFactory;
use Patchlevel\EventSourcing\Metadata\Event\EventRegistry;
use Patchlevel\EventSourcing\Metadata\Subscriber\SubscriberMetadataFactory;

use function app;
use function config;
use function count;
use function sprintf;

#[Signature('event-sourcing:cache')]
#[Description('Cache the event sourcing metadata')]
final class CacheCommand extends Command
{
    public function handle(): int
    {
        if (!config('event-sourcing.cache.enabled')) {
            $this->components->error(
                'The event sourcing cache is disabled. Enable "event-sourcing.cache.enabled" first.',
            );

            return self::FAILURE;
        }

        $this->callSilent('event-sourcing:cache:clear');

        $aggregateClasses = app(AggregateRootRegistry::class)->aggregateClasses();
        $eventClasses = app(EventRegistry::class)->eventClasses();

        /** @var list<class-string> $subscriberClasses */
        $subscriberClasses = config('event-sourcing.subscribers');

        $aggregateRootMetadataFactory = app(AggregateRootMetadataFactory::class);
        $eventMetadataFactory = app(EventMetadataFactory::class);
        $subscriberMetadataFactory = app(SubscriberMetadataFactory::class);

        foreach ($aggregateClasses as $aggregateClass) {
            $aggregateRootMetadataFactory->metadata($aggregateClass);
        }

        foreach ($eventClasses as $eventClass) {
            $eventMetadataFactory->metadata($eventClass);
        }

        foreach ($subscriberClasses as $subscriberClass) {
            $subscriberMetadataFactory->metadata($subscriberClass);
        }

        $this->components->info(
            sprintf(
                'Cached %d aggregates, %d events and %d subscribers.',
                count($aggregateClasses),
                count($eventClasses),
                count($subscriberClasses),
            ),
        );

        return self::SUCCESS;
    }
}
