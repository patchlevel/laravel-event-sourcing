<?php

declare(strict_types=1);

namespace Patchlevel\LaravelEventSourcing\Tests\Fixtures;

use Patchlevel\Hydrator\Extension;
use Patchlevel\Hydrator\Extension\Upcast\UpcastMiddleware;
use Patchlevel\Hydrator\StackHydratorBuilder;

final class DummyHydratorExtension implements Extension
{
    public function configure(StackHydratorBuilder $builder): void
    {
        $builder->addMiddleware(new UpcastMiddleware([]));
    }
}
