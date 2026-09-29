<?php

declare(strict_types=1);

namespace Patchlevel\LaravelEventSourcing\Tests\Fixtures;

final class HydratorAddress
{
    public function __construct(
        public string $city,
    ) {
    }
}
