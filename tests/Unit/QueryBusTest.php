<?php

declare(strict_types=1);

namespace Patchlevel\LaravelEventSourcing\Tests\Unit;

use Patchlevel\LaravelEventSourcing\Facade\QueryBus;
use Patchlevel\LaravelEventSourcing\Tests\Fixtures\ProfileProjector;
use Patchlevel\LaravelEventSourcing\Tests\Fixtures\QueryFoo;

final class QueryBusTest extends TestCase
{
    public function testRepositoryAvailable(): void
    {
        $this->setConfig('event-sourcing.subscribers', [ProfileProjector::class]);

        $result = QueryBus::dispatch(new QueryFoo('bar'));

        self::assertEquals('bar', $result);
    }
}
