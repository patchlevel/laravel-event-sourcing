<?php

declare(strict_types=1);

namespace Patchlevel\LaravelEventSourcing\Tests\Unit;

use Doctrine\DBAL\Connection as DBALConnection;
use Illuminate\Database\Connection as IlluminateConnection;
use Illuminate\Support\Facades\Facade;
use Patchlevel\EventSourcing\Repository\RepositoryManager;
use Patchlevel\LaravelEventSourcing\Facade\CommandBus;
use Patchlevel\LaravelEventSourcing\Facade\ProjectionConnection;
use Patchlevel\LaravelEventSourcing\Facade\QueryBus;
use Patchlevel\LaravelEventSourcing\Facade\Repository;
use Patchlevel\LaravelEventSourcing\Facade\Store;
use PHPUnit\Framework\Attributes\DataProvider;

final class FacadeTest extends TestCase
{
    /**
     * @param class-string<Facade> $facadeClass
     * @param class-string $serviceClass
     */
    #[DataProvider('provideFacades')]
    public function testFacadeIsResolved(string $facadeClass, string $serviceClass): void
    {
        $instance = $facadeClass::getFacadeRoot();

        self::assertNotNull($instance);
        self::assertInstanceOf($serviceClass, $instance);
    }

    public static function provideFacades(): iterable
    {
        yield [Repository::class, RepositoryManager::class];
        yield [Store::class, \Patchlevel\EventSourcing\Store\Store::class];
        yield [CommandBus::class, \Patchlevel\EventSourcing\CommandBus\CommandBus::class];
        yield [QueryBus::class, \Patchlevel\EventSourcing\QueryBus\QueryBus::class];
        yield [ProjectionConnection::class, IlluminateConnection::class];
    }

    /**
     * @param class-string<Facade> $facadeClass
     * @param class-string $serviceClass
     */
    #[DataProvider('provideDbalFacades')]
    public function testDbalFacadeIsResolved(string $facadeClass, string $serviceClass): void
    {
        $this->configureDbal();

        $instance = $facadeClass::getFacadeRoot();

        self::assertNotNull($instance);
        self::assertInstanceOf($serviceClass, $instance);
    }

    public static function provideDbalFacades(): iterable
    {
        yield [Repository::class, RepositoryManager::class];
        yield [Store::class, \Patchlevel\EventSourcing\Store\Store::class];
        yield [CommandBus::class, \Patchlevel\EventSourcing\CommandBus\CommandBus::class];
        yield [QueryBus::class, \Patchlevel\EventSourcing\QueryBus\QueryBus::class];
        yield [ProjectionConnection::class, DBALConnection::class];
    }
}
