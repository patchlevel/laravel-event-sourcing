<?php

namespace Patchlevel\LaravelEventSourcing\Tests\Fixtures;

use Doctrine\DBAL\Connection;
use Patchlevel\EventSourcing\Attribute\Answer;
use Patchlevel\EventSourcing\Attribute\Projector;
use Patchlevel\LaravelEventSourcing\Attribute\ProjectionConnection;

#[Projector('profile')]
class ProfileProjector
{
    public function __construct(
        #[ProjectionConnection]
        public Connection $connection,
    )
    {
    }

    #[Answer]
    public function query(QueryFoo $query): string
    {
        return $query->result;
    }
}
