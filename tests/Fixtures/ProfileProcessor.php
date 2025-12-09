<?php

namespace Patchlevel\LaravelEventSourcing\Tests\Fixtures;

use Patchlevel\EventSourcing\Attribute\Processor;
use Patchlevel\EventSourcing\Repository\Repository;
use Patchlevel\LaravelEventSourcing\Attribute\AggregateRepository;

#[Processor('profile')]
class ProfileProcessor
{
    public function __construct(
        #[AggregateRepository(Profile::class)]
        public Repository $repository
    )
    {
    }
}
