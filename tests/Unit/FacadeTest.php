<?php

declare(strict_types=1);

namespace Patchlevel\LaravelEventSourcing\Tests\Unit;

use Illuminate\Support\Facades\Facade;
use Patchlevel\EventSourcing\Repository\RepositoryManager;
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
    }
}
