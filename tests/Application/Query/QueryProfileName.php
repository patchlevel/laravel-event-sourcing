<?php

declare(strict_types=1);

namespace Patchlevel\LaravelEventSourcing\Tests\Application\Query;

use Patchlevel\LaravelEventSourcing\Tests\Application\ProfileId;

final class QueryProfileName
{
    public function __construct(
        public readonly ProfileId $profileId,
    ) {
    }
}
