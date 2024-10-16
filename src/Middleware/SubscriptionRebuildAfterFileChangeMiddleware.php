<?php

declare(strict_types=1);

namespace Patchlevel\LaravelEventSourcing\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Patchlevel\EventSourcing\Metadata\Subscriber\AttributeSubscriberMetadataFactory;
use Patchlevel\EventSourcing\Metadata\Subscriber\SubscriberMetadataFactory;
use Patchlevel\EventSourcing\Subscription\Engine\SubscriptionEngine;
use Patchlevel\EventSourcing\Subscription\Engine\SubscriptionEngineCriteria;
use Patchlevel\EventSourcing\Subscription\RunMode;
use ReflectionClass;

use function filemtime;

final class SubscriptionRebuildAfterFileChangeMiddleware
{
    /** @param iterable<object> $subscribers */
    public function __construct(
        private readonly SubscriptionEngine $subscriptionEngine,
        private readonly iterable $subscribers,
        private readonly SubscriberMetadataFactory $metadataFactory = new AttributeSubscriberMetadataFactory(),
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $toRemove = [];
        $cacheData = [];

        foreach ($this->subscribers as $subscriber) {
            $metadata = $this->metadataFactory->metadata($subscriber::class);

            if ($metadata->runMode !== RunMode::FromBeginning) {
                continue;
            }

            /** @var int|null $lastModified */
            $lastModified = cache($metadata->id);

            if ($lastModified === null) {
                cache()->put($metadata->id, $this->getLastModifiedTime($subscriber));

                continue;
            }

            $currentModified = $this->getLastModifiedTime($subscriber);

            if ($lastModified === $currentModified) {
                continue;
            }

            $toRemove[] = $metadata->id;
            $cacheData[$metadata->id] = $currentModified;
        }

        $criteria = new SubscriptionEngineCriteria($toRemove);

        $this->subscriptionEngine->remove($criteria);
        $this->subscriptionEngine->setup($criteria);
        $this->subscriptionEngine->boot($criteria);

        foreach ($cacheData as $id => $lastModified) {
            cache()->put($id, $lastModified);
        }

        return $next($request);
    }

    private function getLastModifiedTime(object $subscriber): int|null
    {
        $filename = (new ReflectionClass($subscriber))->getFileName();

        if ($filename === false) {
            return null;
        }

        $lastModified = filemtime($filename);

        if ($lastModified === false) {
            return null;
        }

        return $lastModified;
    }
}
