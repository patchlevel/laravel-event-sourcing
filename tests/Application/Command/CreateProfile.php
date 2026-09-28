<?php

declare(strict_types=1);

namespace Patchlevel\LaravelEventSourcing\Tests\Application\Command;

use Patchlevel\LaravelEventSourcing\Tests\Application\ProfileId;

final class CreateProfile
{
    public function __construct(
        public readonly ProfileId $profileId,
        public readonly string $name,
        public readonly string $email,
    ) {
    }
}
