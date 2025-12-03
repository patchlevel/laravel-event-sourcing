<?php

namespace Patchlevel\LaravelEventSourcing\Tests\Fixtures;

use Patchlevel\EventSourcing\Attribute\Header;

#[Header('custom')]
class CustomHeader
{
    public function __construct(
        readonly string $value
    ) {}
}
