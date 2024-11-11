<?php

declare(strict_types=1);

namespace Patchlevel\LaravelEventSourcing\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EventSourcingMiddleware
{
    public function __construct(
        private readonly AutoSetupMiddleware|null $autoSetupMiddleware = null,
        private readonly SubscriptionRebuildAfterFileChangeMiddleware|null $subscriptionRebuildAfterFileChangeMiddleware = null,
    ) {
    }

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $dummyNext = static function () {
            return new Response();
        };

        if ($this->autoSetupMiddleware !== null) {
            $this->autoSetupMiddleware->handle($request, $dummyNext);
        }

        if ($this->subscriptionRebuildAfterFileChangeMiddleware !== null) {
            $this->subscriptionRebuildAfterFileChangeMiddleware->handle($request, $dummyNext);
        }

        return $next($request);
    }
}
