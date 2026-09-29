<?php

declare(strict_types=1);

namespace Patchlevel\LaravelEventSourcing\Tests\Fixtures;

use Patchlevel\Hydrator\Guesser\Guesser;
use Patchlevel\Hydrator\Normalizer\Normalizer;
use Symfony\Component\TypeInfo\Type\ObjectType;

/** Normalizes the address to its city only, so it is visible whether this guesser was asked. */
final class DummyGuesser implements Guesser
{
    public function guess(ObjectType $type): Normalizer|null
    {
        if (!$type->isIdentifiedBy(HydratorAddress::class)) {
            return null;
        }

        return new class implements Normalizer {
            public function normalize(mixed $value): mixed
            {
                return $value instanceof HydratorAddress ? $value->city : null;
            }

            public function denormalize(mixed $value): mixed
            {
                return new HydratorAddress((string)$value);
            }
        };
    }
}
