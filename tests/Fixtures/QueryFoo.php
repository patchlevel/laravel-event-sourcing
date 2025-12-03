<?php

declare(strict_types=1);

namespace Patchlevel\LaravelEventSourcing\Tests\Fixtures;

final readonly class QueryFoo
{
    public function __construct(public string $result)
    {
    }
}