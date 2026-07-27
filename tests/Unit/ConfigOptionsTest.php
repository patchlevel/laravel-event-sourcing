<?php

declare(strict_types=1);

namespace Patchlevel\LaravelEventSourcing\Tests\Unit;

use InvalidArgumentException;
use Patchlevel\EventSourcing\Console\Command\StoreMigrateCommand;
use Patchlevel\EventSourcing\Store\InMemoryStore;
use Patchlevel\EventSourcing\Store\Store;
use Patchlevel\EventSourcing\Store\StreamReadOnlyStore;
use Patchlevel\EventSourcing\Subscription\Engine\CatchUpSubscriptionEngine;
use Patchlevel\EventSourcing\Subscription\Engine\SubscriptionEngine;
use Patchlevel\EventSourcing\Subscription\Engine\ThrowOnErrorSubscriptionEngine;
use Patchlevel\EventSourcing\Subscription\RetryStrategy\ClockBasedRetryStrategy;
use Patchlevel\EventSourcing\Subscription\RetryStrategy\NoRetryStrategy;
use Patchlevel\EventSourcing\Subscription\RetryStrategy\RetryStrategyRepository;
use Patchlevel\Hydrator\Cryptography\Store\CipherKeyStore;
use Patchlevel\LaravelEventSourcing\Cryptography\IlluminateCipherKeyStore;
use Patchlevel\LaravelEventSourcing\Store\StreamIlluminateStore;

final class ConfigOptionsTest extends TestCase
{
    public function testStoreIsWritableByDefault(): void
    {
        self::assertInstanceOf(StreamIlluminateStore::class, $this->app->get(Store::class));
    }

    public function testReadonlyStore(): void
    {
        $this->setConfig('event-sourcing.store.read_only', true);

        self::assertInstanceOf(StreamReadOnlyStore::class, $this->app->get(Store::class));
    }

    public function testStoreMigrationIsNotRegisteredByDefault(): void
    {
        self::assertFalse($this->app->bound('event_sourcing.store.new_store'));
    }

    public function testStoreMigrationIsRegisteredUnderTheStoreKey(): void
    {
        $this->setConfig('event-sourcing.store.migrate_to_new_store', [
            'enabled' => true,
            'type' => 'in_memory',
            'service' => null,
            'options' => [],
            'translators' => [],
        ]);

        self::assertInstanceOf(InMemoryStore::class, $this->app->get('event_sourcing.store.new_store'));
        self::assertInstanceOf(StoreMigrateCommand::class, $this->app->get(StoreMigrateCommand::class));
    }

    public function testCatchUpIsEnabledByDefault(): void
    {
        self::assertInstanceOf(CatchUpSubscriptionEngine::class, $this->app->get(SubscriptionEngine::class));
    }

    public function testCatchUpCanBeDisabled(): void
    {
        $this->setConfig('event-sourcing.subscription.catch_up', ['enabled' => false, 'limit' => null]);

        self::assertNotInstanceOf(CatchUpSubscriptionEngine::class, $this->app->get(SubscriptionEngine::class));
    }

    public function testCatchUpSupportsTheLegacyBoolean(): void
    {
        $this->setConfig('event-sourcing.subscription.catch_up', true);

        self::assertInstanceOf(CatchUpSubscriptionEngine::class, $this->app->get(SubscriptionEngine::class));
    }

    public function testThrowOnErrorIsEnabledByDefault(): void
    {
        $this->setConfig('event-sourcing.subscription.catch_up', ['enabled' => false, 'limit' => null]);

        self::assertInstanceOf(ThrowOnErrorSubscriptionEngine::class, $this->app->get(SubscriptionEngine::class));
    }

    public function testThrowOnErrorCanBeDisabled(): void
    {
        $this->setConfig('event-sourcing.subscription.catch_up', ['enabled' => false, 'limit' => null]);
        $this->setConfig('event-sourcing.subscription.throw_on_error', ['enabled' => false]);

        self::assertNotInstanceOf(ThrowOnErrorSubscriptionEngine::class, $this->app->get(SubscriptionEngine::class));
    }

    public function testThrowOnErrorSupportsTheLegacyBoolean(): void
    {
        $this->setConfig('event-sourcing.subscription.catch_up', ['enabled' => false, 'limit' => null]);
        $this->setConfig('event-sourcing.subscription.throw_on_error', false);

        self::assertNotInstanceOf(ThrowOnErrorSubscriptionEngine::class, $this->app->get(SubscriptionEngine::class));
    }

    public function testLegacyRetryStrategyOptionIsStillSupported(): void
    {
        $this->setConfig('event-sourcing.subscription.retry_strategies', null);
        $this->setConfig('event-sourcing.subscription.retry_strategy', [
            'base_delay' => 10,
            'delay_factor' => 3,
            'max_attempts' => 7,
        ]);

        $repository = $this->app->get(RetryStrategyRepository::class);

        self::assertInstanceOf(ClockBasedRetryStrategy::class, $repository->get('default'));
        self::assertInstanceOf(NoRetryStrategy::class, $repository->get('no_retry'));
        self::assertInstanceOf(ClockBasedRetryStrategy::class, $repository->getDefaultRetryStrategy());
    }

    public function testLegacyRetryStrategyOptionWithoutOptions(): void
    {
        $this->setConfig('event-sourcing.subscription.retry_strategies', null);
        $this->setConfig('event-sourcing.subscription.retry_strategy', ['base_delay' => 10]);

        $repository = $this->app->get(RetryStrategyRepository::class);

        self::assertInstanceOf(ClockBasedRetryStrategy::class, $repository->get('default'));
    }

    public function testRetryStrategyAndRetryStrategiesCannotBeCombined(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->setConfig('event-sourcing.subscription.retry_strategy', ['base_delay' => 5]);
    }

    public function testRetryStrategies(): void
    {
        $repository = $this->app->get(RetryStrategyRepository::class);

        self::assertInstanceOf(ClockBasedRetryStrategy::class, $repository->get('default'));
        self::assertInstanceOf(NoRetryStrategy::class, $repository->get('no_retry'));
    }

    public function testCryptographyTableNameIsConfigurable(): void
    {
        $this->setConfig('event-sourcing.cryptography.enabled', true);
        $this->setConfig('event-sourcing.cryptography.options.table_name', 'my_keys');

        $store = $this->app->get(CipherKeyStore::class);

        self::assertInstanceOf(IlluminateCipherKeyStore::class, $store);
        self::assertSame('my_keys', (fn () => $this->tableName)->call($store));
    }

    public function testCryptographyDefaultTableNameMatchesTheMigration(): void
    {
        $this->setConfig('event-sourcing.cryptography.enabled', true);

        $store = $this->app->get(CipherKeyStore::class);

        self::assertInstanceOf(IlluminateCipherKeyStore::class, $store);
        self::assertSame('crypto_keys', (fn () => $this->tableName)->call($store));
    }
}
