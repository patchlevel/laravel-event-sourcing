<?php

declare(strict_types=1);

namespace Patchlevel\LaravelEventSourcing\Tests\Unit;

use Illuminate\Support\ServiceProvider;
use Patchlevel\EventSourcing\Metadata\AggregateRoot\AggregateRootMetadataAwareMetadataFactory;
use Patchlevel\EventSourcing\Metadata\AggregateRoot\AggregateRootMetadataFactory;
use Patchlevel\EventSourcing\Metadata\AggregateRoot\AggregateRootRegistry;
use Patchlevel\EventSourcing\Metadata\AggregateRoot\Psr16AggregateRootMetadataFactory;
use Patchlevel\EventSourcing\Metadata\Event\AttributeEventMetadataFactory;
use Patchlevel\EventSourcing\Metadata\Event\EventMetadataFactory;
use Patchlevel\EventSourcing\Metadata\Event\EventRegistry;
use Patchlevel\EventSourcing\Metadata\Event\Psr16EventMetadataFactory;
use Patchlevel\EventSourcing\Metadata\Subscriber\AttributeSubscriberMetadataFactory;
use Patchlevel\EventSourcing\Metadata\Subscriber\Psr16SubscriberMetadataFactory;
use Patchlevel\EventSourcing\Metadata\Subscriber\SubscriberMetadataFactory;
use Patchlevel\LaravelEventSourcing\Tests\Fixtures\Profile;
use Patchlevel\LaravelEventSourcing\Tests\Fixtures\ProfileCreated;
use Psr\SimpleCache\CacheInterface;

final class CacheTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private array|null $cacheConfig = null;

    /** The optimize commands live in a static, which survives the application rebuilds. */
    public function setUp(): void
    {
        ServiceProvider::$optimizeCommands = [];
        ServiceProvider::$optimizeClearCommands = [];

        parent::setUp();
    }

    public function testMetadataFactoriesAreNotDecoratedByDefault(): void
    {
        self::assertInstanceOf(
            AggregateRootMetadataAwareMetadataFactory::class,
            $this->app->get(AggregateRootMetadataFactory::class),
        );
        self::assertInstanceOf(
            AttributeEventMetadataFactory::class,
            $this->app->get(EventMetadataFactory::class),
        );
        self::assertInstanceOf(
            AttributeSubscriberMetadataFactory::class,
            $this->app->get(SubscriberMetadataFactory::class),
        );
    }

    public function testMetadataFactoriesAreDecoratedWhenEnabled(): void
    {
        $this->enableCache();

        self::assertInstanceOf(
            Psr16AggregateRootMetadataFactory::class,
            $this->app->get(AggregateRootMetadataFactory::class),
        );
        self::assertInstanceOf(
            Psr16EventMetadataFactory::class,
            $this->app->get(EventMetadataFactory::class),
        );
        self::assertInstanceOf(
            Psr16SubscriberMetadataFactory::class,
            $this->app->get(SubscriberMetadataFactory::class),
        );
    }

    public function testCacheStoreIsAPsr16Cache(): void
    {
        self::assertInstanceOf(CacheInterface::class, $this->app->get('event_sourcing.cache'));
    }

    public function testRegistriesAreStoredInTheCache(): void
    {
        $this->enableCache();

        $aggregateRootRegistry = $this->app->get(AggregateRootRegistry::class);
        $eventRegistry = $this->app->get(EventRegistry::class);

        self::assertEquals($aggregateRootRegistry, $this->cache()->get('aggregate_root_registry'));
        self::assertEquals($eventRegistry, $this->cache()->get('event_registry'));
    }

    public function testCachedRegistryIsReused(): void
    {
        $this->enableCache();

        $this->cache()->set('aggregate_root_registry', new AggregateRootRegistry(['foo' => Profile::class]));

        $registry = $this->app->get(AggregateRootRegistry::class);

        self::assertSame(['foo' => Profile::class], $registry->aggregateClasses());
    }

    public function testCacheCommandWarmsRegistriesAndMetadata(): void
    {
        $this->enableCache();

        $this->artisan('event-sourcing:cache')->assertSuccessful();

        self::assertNotNull($this->cache()->get('aggregate_root_registry'));
        self::assertNotNull($this->cache()->get('event_registry'));
        self::assertNotNull($this->cache()->get(ProfileCreated::class));
    }

    public function testCacheCommandFailsWhenDisabled(): void
    {
        $this->artisan('event-sourcing:cache')->assertFailed();
    }

    public function testCacheClearCommandEmptiesTheStore(): void
    {
        $this->enableCache();

        $this->cache()->set('aggregate_root_registry', new AggregateRootRegistry([]));

        $this->artisan('event-sourcing:cache:clear')->assertSuccessful();

        self::assertNull($this->cache()->get('aggregate_root_registry'));
    }

    public function testOptimizeOnlyRunsTheCacheCommandWhenEnabled(): void
    {
        self::assertArrayNotHasKey('event-sourcing', ServiceProvider::$optimizeCommands);

        $this->enableCache();

        self::assertSame('event-sourcing:cache', ServiceProvider::$optimizeCommands['event-sourcing'] ?? null);
    }

    /**
     * The provider decides while it registers whether it decorates the metadata factories, and
     * `defineEnvironment()` runs after that. So the cache config is seeded here, which runs before
     * the providers are registered.
     *
     * @param \Illuminate\Foundation\Application $app
     */
    protected function resolveApplicationConfiguration($app): void
    {
        parent::resolveApplicationConfiguration($app);

        if ($this->cacheConfig === null) {
            return;
        }

        $app['config']->set('event-sourcing.cache', $this->cacheConfig);
    }

    private function enableCache(): void
    {
        $this->cacheConfig = ['enabled' => true, 'store' => 'array'];

        $this->reloadApplication();
    }

    private function cache(): CacheInterface
    {
        /** @var CacheInterface $cache */
        $cache = $this->app->get('event_sourcing.cache');

        return $cache;
    }
}
