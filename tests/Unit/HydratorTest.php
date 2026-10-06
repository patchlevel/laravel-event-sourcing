<?php

declare(strict_types=1);

namespace Patchlevel\LaravelEventSourcing\Tests\Unit;

use InvalidArgumentException;
use Patchlevel\EventSourcing\Cryptography\ExtensionDoctrineCipherKeyStore;
use Patchlevel\Hydrator\Extension\Cryptography\Cryptographer;
use Patchlevel\Hydrator\Extension\Cryptography\CryptographyMiddleware;
use Patchlevel\Hydrator\Extension\Cryptography\LegacyCryptographyDecryptMiddleware;
use Patchlevel\Hydrator\Extension\Cryptography\Store\CipherKeyStore;
use Patchlevel\Hydrator\Extension\Lifecycle\LifecycleMiddleware;
use Patchlevel\Hydrator\Extension\Upcast\UpcastMiddleware;
use Patchlevel\Hydrator\Hydrator;
use Patchlevel\Hydrator\MetadataHydrator;
use Patchlevel\Hydrator\Middleware\Middleware;
use Patchlevel\Hydrator\Middleware\TransformMiddleware;
use Patchlevel\Hydrator\StackHydrator;
use Patchlevel\Hydrator\StackHydratorBuilder;
use Patchlevel\LaravelEventSourcing\Cryptography\ExtensionIlluminateCipherKeyStore;
use Patchlevel\LaravelEventSourcing\Tests\Fixtures\DummyGuesser;
use Patchlevel\LaravelEventSourcing\Tests\Fixtures\DummyHydratorExtension;
use Patchlevel\LaravelEventSourcing\Tests\Fixtures\HydratorAddress;
use Patchlevel\LaravelEventSourcing\Tests\Fixtures\HydratorPerson;

use function array_map;

final class HydratorTest extends TestCase
{
    public function testLegacyHydratorByDefault(): void
    {
        self::assertInstanceOf(MetadataHydrator::class, $this->app->get(Hydrator::class));
        self::assertFalse($this->app->bound(StackHydratorBuilder::class));
    }

    public function testLegacyHydratorFallsBackToTheObjectNormalizer(): void
    {
        $data = $this->app->get(Hydrator::class)->extract(new HydratorPerson('John', new HydratorAddress('Berlin')));

        self::assertSame(['name' => 'John', 'address' => ['city' => 'Berlin']], $data);
    }

    public function testLegacyHydratorWithGuesserFromConfig(): void
    {
        $this->setConfig('event-sourcing.hydrator.guessers', [DummyGuesser::class]);

        $data = $this->app->get(Hydrator::class)->extract(new HydratorPerson('John', new HydratorAddress('Berlin')));

        self::assertSame(['name' => 'John', 'address' => 'Berlin'], $data);
    }

    public function testLegacyHydratorWithTaggedGuesser(): void
    {
        $this->app->tag(DummyGuesser::class, ['event_sourcing.hydrator.guesser']);

        $person = $this->app->get(Hydrator::class)->hydrate(
            HydratorPerson::class,
            ['name' => 'John', 'address' => 'Berlin'],
        );

        self::assertEquals(new HydratorPerson('John', new HydratorAddress('Berlin')), $person);
    }

    public function testStackHydratorFallsBackToTheObjectNormalizer(): void
    {
        $this->setConfig('event-sourcing.hydrator.enabled', true);

        $data = $this->app->get(Hydrator::class)->extract(new HydratorPerson('John', new HydratorAddress('Berlin')));

        self::assertSame(['name' => 'John', 'address' => ['city' => 'Berlin']], $data);
    }

    public function testStackHydrator(): void
    {
        $this->setConfig('event-sourcing.hydrator.enabled', true);

        self::assertInstanceOf(StackHydrator::class, $this->app->get(Hydrator::class));
        self::assertSame([TransformMiddleware::class], $this->middlewares());
        self::assertFalse($this->builder()->defaultLazy());
        self::assertNull($this->cache());
        self::assertFalse($this->app->bound(CipherKeyStore::class));
    }

    public function testMetadataCache(): void
    {
        $this->resetApplicationWithConfig([
            'event-sourcing.hydrator.enabled' => true,
            'event-sourcing.cache.enabled' => true,
        ]);

        self::assertSame($this->app->get('event_sourcing.cache'), $this->cache());
    }

    public function testDefaultLazy(): void
    {
        $this->resetApplicationWithConfig([
            'event-sourcing.hydrator.enabled' => true,
            'event-sourcing.hydrator.default_lazy' => true,
        ]);

        self::assertTrue($this->builder()->defaultLazy());
    }

    public function testCryptography(): void
    {
        $this->resetApplicationWithConfig([
            'event-sourcing.hydrator.enabled' => true,
            'event-sourcing.hydrator.cryptography.enabled' => true,
        ]);

        $store = $this->app->get(CipherKeyStore::class);

        self::assertInstanceOf(ExtensionIlluminateCipherKeyStore::class, $store);
        self::assertSame('cryptography_keys', (fn () => $this->tableName)->call($store));
        self::assertInstanceOf(Cryptographer::class, $this->app->get(Cryptographer::class));
        self::assertSame([CryptographyMiddleware::class, TransformMiddleware::class], $this->middlewares());
    }

    public function testCryptographyTableNameIsConfigurable(): void
    {
        $this->resetApplicationWithConfig([
            'event-sourcing.hydrator.enabled' => true,
            'event-sourcing.hydrator.cryptography.enabled' => true,
            'event-sourcing.hydrator.cryptography.options.table_name' => 'my_keys',
        ]);

        $store = $this->app->get(CipherKeyStore::class);

        self::assertSame('my_keys', (fn () => $this->tableName)->call($store));
    }

    public function testCryptographyWithDbalStore(): void
    {
        $this->configureDbal();
        $this->resetApplicationWithConfig([
            'event-sourcing.hydrator.enabled' => true,
            'event-sourcing.hydrator.cryptography.enabled' => true,
            'event-sourcing.hydrator.cryptography.store' => 'dbal',
        ]);

        self::assertInstanceOf(ExtensionDoctrineCipherKeyStore::class, $this->app->get(CipherKeyStore::class));
        self::assertContains(
            ExtensionDoctrineCipherKeyStore::class,
            array_map(
                static fn (object $service) => $service::class,
                [...$this->app->tagged('event_sourcing.doctrine_schema_configurator')],
            ),
        );
    }

    public function testCryptographyWithUnknownStore(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Hydrator cryptography store type is unknown.');

        $this->resetApplicationWithConfig([
            'event-sourcing.hydrator.enabled' => true,
            'event-sourcing.hydrator.cryptography.enabled' => true,
            'event-sourcing.hydrator.cryptography.store' => 'unknown',
        ]);
    }

    public function testCryptographyDecryptsLegacyDataWhenTheLegacyCryptographyIsEnabled(): void
    {
        $this->resetApplicationWithConfig([
            'event-sourcing.cryptography.enabled' => true,
            'event-sourcing.hydrator.enabled' => true,
            'event-sourcing.hydrator.cryptography.enabled' => true,
        ]);

        self::assertSame(
            [LegacyCryptographyDecryptMiddleware::class, CryptographyMiddleware::class, TransformMiddleware::class],
            $this->middlewares(),
        );
    }

    public function testLifecycle(): void
    {
        $this->resetApplicationWithConfig([
            'event-sourcing.hydrator.enabled' => true,
            'event-sourcing.hydrator.lifecycle.enabled' => true,
        ]);

        self::assertSame([LifecycleMiddleware::class, TransformMiddleware::class], $this->middlewares());
    }

    public function testExtensionFromConfig(): void
    {
        $this->resetApplicationWithConfig([
            'event-sourcing.hydrator.enabled' => true,
            'event-sourcing.hydrator.extensions' => [DummyHydratorExtension::class],
        ]);

        self::assertSame([UpcastMiddleware::class, TransformMiddleware::class], $this->middlewares());
    }

    public function testTaggedExtension(): void
    {
        $this->setConfig('event-sourcing.hydrator.enabled', true);

        $this->app->tag(DummyHydratorExtension::class, ['event_sourcing.hydrator.extension']);

        self::assertSame([UpcastMiddleware::class, TransformMiddleware::class], $this->middlewares());
    }

    private function builder(): StackHydratorBuilder
    {
        return $this->app->get(StackHydratorBuilder::class);
    }

    private function cache(): object|null
    {
        return (fn () => $this->cache)->call($this->builder());
    }

    /** @return list<class-string<Middleware>> */
    private function middlewares(): array
    {
        return array_map(
            static fn (Middleware $middleware) => $middleware::class,
            $this->builder()->middlewares(),
        );
    }
}
