<?php

declare(strict_types=1);

namespace Patchlevel\LaravelEventSourcing\Tests\Integration\Store\Header;

use Patchlevel\EventSourcing\Attribute\Header;

#[Header('trace')]
final class TraceHeader
{
    public function __construct(
        public readonly string $traceId,
    ) {
    }
}
