<?php

declare(strict_types=1);

namespace Patchlevel\LaravelEventSourcing\Tests\Fixtures;

/** Has a nested object without a normalizer attribute, so a guesser has to pick one. */
final class HydratorPerson
{
    public function __construct(
        public string $name,
        public HydratorAddress $address,
    ) {
    }
}
