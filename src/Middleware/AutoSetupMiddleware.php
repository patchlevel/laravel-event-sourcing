<?php

declare(strict_types=1);

namespace Patchlevel\LaravelEventSourcing\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Patchlevel\EventSourcing\Subscription\Engine\SubscriptionEngine;
use Patchlevel\EventSourcing\Subscription\Engine\SubscriptionEngineCriteria;
use Patchlevel\EventSourcing\Subscription\Status;

final class AutoSetupMiddleware
{
    /**
     * @param list<string>|null $ids
     * @param list<string>|null $groups
     */
    public function __construct(
        private readonly SubscriptionEngine $subscriptionEngine,
        private readonly array|null $ids,
        private readonly array|null $groups,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $subscriptions = $this->subscriptionEngine->subscriptions(
            new SubscriptionEngineCriteria(
                $this->ids,
                $this->groups,
            ),
        );

        $ids = [];

        foreach ($subscriptions as $subscription) {
            if ($subscription->status() !== Status::New) {
                continue;
            }

            $ids[] = $subscription->id();
        }

        $this->subscriptionEngine->setup(
            new SubscriptionEngineCriteria($ids),
            true,
        );

        return $next($request);
    }
}
