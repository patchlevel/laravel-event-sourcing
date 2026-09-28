<?php

declare(strict_types=1);

namespace Patchlevel\LaravelEventSourcing\Tests\Application\Command;

use Patchlevel\EventSourcing\Attribute\Id;
use Patchlevel\LaravelEventSourcing\Tests\Application\ProfileId;

final class ChangeName
{
    public function __construct(
        #[Id]
        public readonly ProfileId $profileId,
        public readonly string $name,
    ) {
    }
}
