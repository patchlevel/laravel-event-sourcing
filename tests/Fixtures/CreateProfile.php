<?php

namespace Patchlevel\LaravelEventSourcing\Tests\Fixtures;

use Patchlevel\EventSourcing\Aggregate\CustomId;

class CreateProfile
{
    public function __construct(
        public readonly CustomId $id,
    ) {

    }
}