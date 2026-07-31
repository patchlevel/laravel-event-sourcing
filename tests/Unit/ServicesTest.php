<?php

declare(strict_types=1);

namespace Patchlevel\LaravelEventSourcing\Tests\Unit;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Connection;
use Patchlevel\EventSourcing\Clock\SystemClock;
use Patchlevel\EventSourcing\CommandBus\CommandBus;
use Patchlevel\EventSourcing\CommandBus\InstantRetryCommandBus;
use Patchlevel\EventSourcing\Console\Command\DebugCommand;
use Patchlevel\EventSourcing\Console\Command\ShowAggregateCommand;
use Patchlevel\EventSourcing\Console\Command\ShowCommand;
use Patchlevel\EventSourcing\Console\Command\SubscriptionBootCommand;
use Patchlevel\EventSourcing\Console\Command\SubscriptionPauseCommand;
use Patchlevel\EventSourcing\Console\Command\SubscriptionReactivateCommand;
use Patchlevel\EventSourcing\Console\Command\SubscriptionRemoveCommand;
use Patchlevel\EventSourcing\Console\Command\SubscriptionRunCommand;
use Patchlevel\EventSourcing\Console\Command\SubscriptionSetupCommand;
use Patchlevel\EventSourcing\Console\Command\SubscriptionStatusCommand;
use Patchlevel\EventSourcing\Console\Command\SubscriptionTeardownCommand;
use Patchlevel\EventSourcing\Console\Command\WatchCommand;
use Patchlevel\EventSourcing\EventBus\AttributeListenerProvider;
use Patchlevel\EventSourcing\EventBus\Consumer;
use Patchlevel\EventSourcing\EventBus\DefaultConsumer;
use Patchlevel\EventSourcing\EventBus\DefaultEventBus;
use Patchlevel\EventSourcing\EventBus\EventBus;
use Patchlevel\EventSourcing\EventBus\ListenerProvider;
use Patchlevel\EventSourcing\Message\Serializer\DefaultHeadersSerializer;
use Patchlevel\EventSourcing\Message\Serializer\HeadersSerializer;
use Patchlevel\EventSourcing\Metadata\AggregateRoot\AggregateRootMetadataAwareMetadataFactory;
use Patchlevel\EventSourcing\Metadata\AggregateRoot\AggregateRootMetadataFactory;
use Patchlevel\EventSourcing\Metadata\AggregateRoot\AggregateRootRegistry;
use Patchlevel\EventSourcing\Metadata\Event\AttributeEventMetadataFactory;
use Patchlevel\EventSourcing\Metadata\Event\EventMetadataFactory;
use Patchlevel\EventSourcing\Metadata\Event\EventRegistry;
use Patchlevel\EventSourcing\Metadata\Message\AttributeMessageHeaderRegistryFactory;
use Patchlevel\EventSourcing\Metadata\Message\MessageHeaderRegistry;
use Patchlevel\EventSourcing\Metadata\Message\MessageHeaderRegistryFactory;
use Patchlevel\EventSourcing\Metadata\Subscriber\AttributeSubscriberMetadataFactory;
use Patchlevel\EventSourcing\Metadata\Subscriber\SubscriberMetadataFactory;
use Patchlevel\EventSourcing\QueryBus\QueryBus;
use Patchlevel\EventSourcing\QueryBus\SyncQueryBus;
use Patchlevel\EventSourcing\Repository\MessageDecorator\ChainMessageDecorator;
use Patchlevel\EventSourcing\Repository\MessageDecorator\MessageDecorator;
use Patchlevel\EventSourcing\Repository\MessageDecorator\SplitStreamDecorator;
use Patchlevel\EventSourcing\Repository\RepositoryManager;
use Patchlevel\EventSourcing\Serializer\DefaultEventSerializer;
use Patchlevel\EventSourcing\Serializer\Encoder\Encoder;
use Patchlevel\EventSourcing\Serializer\Encoder\JsonEncoder;
use Patchlevel\EventSourcing\Serializer\EventSerializer;
use Patchlevel\EventSourcing\Serializer\Upcast\Upcaster;
use Patchlevel\EventSourcing\Serializer\Upcast\UpcasterChain;
use Patchlevel\EventSourcing\Snapshot\DefaultSnapshotStore;
use Patchlevel\EventSourcing\Snapshot\SnapshotStore;
use Patchlevel\EventSourcing\Store\Store;
use Patchlevel\EventSourcing\Subscription\Engine\CatchUpSubscriptionEngine;
use Patchlevel\EventSourcing\Subscription\Cleanup\Cleaner;
use Patchlevel\EventSourcing\Subscription\Cleanup\DefaultCleaner;
use Patchlevel\EventSourcing\Subscription\Engine\GapResolverStoreMessageLoader;
use Patchlevel\EventSourcing\Subscription\Engine\MessageLoader;
use Patchlevel\EventSourcing\Subscription\Engine\SubscriptionEngine;
use Patchlevel\EventSourcing\Subscription\Repository\RunSubscriptionEngineRepositoryManager;
use Patchlevel\EventSourcing\Subscription\RetryStrategy\RetryStrategyRepository;
use Patchlevel\EventSourcing\Subscription\Store\SubscriptionStore;
use Patchlevel\EventSourcing\Subscription\Subscriber\MetadataSubscriberAccessorRepository;
use Patchlevel\EventSourcing\Subscription\Subscriber\SubscriberAccessorRepository;
use Patchlevel\EventSourcing\Subscription\Subscriber\SubscriberHelper;
use Patchlevel\Hydrator\Cryptography\Cipher\Cipher;
use Patchlevel\Hydrator\Cryptography\Cipher\CipherKeyFactory;
use Patchlevel\Hydrator\Cryptography\Cipher\OpensslCipher;
use Patchlevel\Hydrator\Cryptography\Cipher\OpensslCipherKeyFactory;
use Patchlevel\Hydrator\Cryptography\PayloadCryptographer;
use Patchlevel\Hydrator\Cryptography\PersonalDataPayloadCryptographer;
use Patchlevel\Hydrator\Cryptography\Store\CipherKeyStore;
use Patchlevel\Hydrator\Hydrator;
use Patchlevel\Hydrator\MetadataHydrator;
use Patchlevel\LaravelEventSourcing\Cryptography\IlluminateCipherKeyStore;
use Patchlevel\LaravelEventSourcing\Middleware\AutoSetupMiddleware;
use Patchlevel\LaravelEventSourcing\Middleware\EventSourcingMiddleware;
use Patchlevel\LaravelEventSourcing\Middleware\SubscriptionRebuildAfterFileChangeMiddleware;
use Patchlevel\LaravelEventSourcing\Store\StreamIlluminateStore;
use Patchlevel\LaravelEventSourcing\Subscription\Cleanup\IlluminateCleanupTaskHandler;
use Patchlevel\LaravelEventSourcing\Subscription\Store\IlluminateSubscriptionStore;
use Patchlevel\LaravelEventSourcing\Tests\Fixtures\Profile;
use Patchlevel\LaravelEventSourcing\Tests\Fixtures\ProfileProcessor;
use Patchlevel\LaravelEventSourcing\Tests\Fixtures\ProfileProjector;
use PHPUnit\Framework\Attributes\DataProvider;

final class ServicesTest extends TestCase
{
    /**
     * @param class-string|string $serviceClass
     * @param class-string $concreteClass
     */
    #[DataProvider('provideServices')]
    public function testServiceIsAvailable(string $serviceClass, string $concreteClass): void
    {
        $this->setConfig('event-sourcing.event_bus.enabled', true);
        $this->setConfig('event-sourcing.cryptography.enabled', true);

        $service = $this->app->get($serviceClass);

        self::assertNotNull($service);
        self::assertInstanceOf($concreteClass, $service);
    }

    /**
     * @return iterable<array{0: class-string|string, 1: class-string}>
     */
    public static function provideServices(): iterable
    {
        yield [EventMetadataFactory::class, AttributeEventMetadataFactory::class];
        yield [Encoder::class, JsonEncoder::class];
        yield [MessageHeaderRegistryFactory::class, AttributeMessageHeaderRegistryFactory::class];
        yield [AggregateRootMetadataFactory::class, AggregateRootMetadataAwareMetadataFactory::class];
        yield [SubscriberMetadataFactory::class, AttributeSubscriberMetadataFactory::class];
        yield ['event_sourcing.connection', Connection::class];
        yield ['event_sourcing.public_connection', Connection::class];
        yield ['event_sourcing.illuminate_connection', Connection::class];
        yield ['event_sourcing.illuminate_public_connection', Connection::class];
        yield [Store::class, StreamIlluminateStore::class];
        yield [EventRegistry::class, EventRegistry::class];
        yield [EventSerializer::class, DefaultEventSerializer::class];
        yield [MessageHeaderRegistry::class, MessageHeaderRegistry::class];
        yield [HeadersSerializer::class, DefaultHeadersSerializer::class];
        yield [Hydrator::class, MetadataHydrator::class];
        yield ['event_sourcing.clock', SystemClock::class];
        yield [AggregateRootRegistry::class, AggregateRootRegistry::class];
        yield [RepositoryManager::class, RunSubscriptionEngineRepositoryManager::class];
        yield [ShowCommand::class, ShowCommand::class];
        yield [ShowAggregateCommand::class, ShowAggregateCommand::class];
        yield [WatchCommand::class, WatchCommand::class];
        yield [DebugCommand::class, DebugCommand::class];
        yield [Upcaster::class, UpcasterChain::class];
        yield [MessageDecorator::class, ChainMessageDecorator::class];
        yield [SplitStreamDecorator::class, SplitStreamDecorator::class];
        yield [CommandBus::class, InstantRetryCommandBus::class];
        yield [QueryBus::class, SyncQueryBus::class];
        yield [MessageLoader::class, GapResolverStoreMessageLoader::class];
        yield [ListenerProvider::class, AttributeListenerProvider::class];
        yield [Consumer::class, DefaultConsumer::class];
        yield [EventBus::class, DefaultEventBus::class];
        yield [SnapshotStore::class, DefaultSnapshotStore::class];
        yield [RetryStrategyRepository::class, RetryStrategyRepository::class];
        yield [SubscriberHelper::class, SubscriberHelper::class];
        yield [SubscriptionStore::class, IlluminateSubscriptionStore::class];
        yield [Cleaner::class, DefaultCleaner::class];
        yield [IlluminateCleanupTaskHandler::class, IlluminateCleanupTaskHandler::class];
        yield [SubscriberAccessorRepository::class, MetadataSubscriberAccessorRepository::class];
        yield [SubscriptionEngine::class, CatchUpSubscriptionEngine::class];
        yield [AutoSetupMiddleware::class, AutoSetupMiddleware::class];
        yield [SubscriptionRebuildAfterFileChangeMiddleware::class, SubscriptionRebuildAfterFileChangeMiddleware::class];
        yield [EventSourcingMiddleware::class, EventSourcingMiddleware::class];
        yield [SubscriptionSetupCommand::class, SubscriptionSetupCommand::class];
        yield [SubscriptionBootCommand::class, SubscriptionBootCommand::class];
        yield [SubscriptionRunCommand::class, SubscriptionRunCommand::class];
        yield [SubscriptionTeardownCommand::class, SubscriptionTeardownCommand::class];
        yield [SubscriptionRemoveCommand::class, SubscriptionRemoveCommand::class];
        yield [SubscriptionStatusCommand::class, SubscriptionStatusCommand::class];
        yield [SubscriptionPauseCommand::class, SubscriptionPauseCommand::class];
        yield [SubscriptionReactivateCommand::class, SubscriptionReactivateCommand::class];
        yield [CipherKeyFactory::class, OpensslCipherKeyFactory::class];
        yield [CipherKeyStore::class, IlluminateCipherKeyStore::class];
        yield [Cipher::class, OpensslCipher::class];
        yield [PayloadCryptographer::class, PersonalDataPayloadCryptographer::class];
    }

    public function testIlluminateConnectionIdsAreAliases(): void
    {
        self::assertSame(
            $this->app->get('event_sourcing.connection'),
            $this->app->get('event_sourcing.illuminate_connection'),
        );
        self::assertSame(
            $this->app->get('event_sourcing.public_connection'),
            $this->app->get('event_sourcing.illuminate_public_connection'),
        );
    }

    public function testDbalConnectionIdsAreNotRegistered(): void
    {
        self::assertFalse($this->app->bound('event_sourcing.dbal_connection'));
        self::assertFalse($this->app->bound('event_sourcing.dbal_public_connection'));
    }

    public function testConnectionIsRegisteredWithoutDedicatedConnection(): void
    {
        $this->setConfig('event-sourcing.connection.provide_dedicated_connection', false);

        self::assertInstanceOf(Connection::class, $this->app->get('event_sourcing.connection'));
        self::assertInstanceOf(Connection::class, $this->app->get('event_sourcing.illuminate_connection'));
    }

    public function testPublicConnectionIsNotSameAsPrivate(): void
    {
        /** @var Connection $private */
        $private = $this->app->get('event_sourcing.connection');
        /** @var Connection $public */
        $public = $this->app->get('event_sourcing.public_connection');

        self::assertNotSame($public, $private);
        self::assertEquals($public->getConfig(), $public->getConfig());
    }

    public function testAttributeProjectionConnectionInjection(): void
    {
        $public = $this->app->get('event_sourcing.public_connection');
        $service = $this->app->get(ProfileProjector::class);

        self::assertSame($public, $service->connection);
    }

    public function testAttributeAggregateRepositoryInjection(): void
    {
        $service = $this->app->get(ProfileProcessor::class);
        $public = $this->app->get(RepositoryManager::class)->get(Profile::class);

        self::assertEquals($public, $service->repository);
    }

    /**
     * Every registered console command has to be resolvable, otherwise artisan cannot even build
     * its command list. The doctrine only commands must therefore stay unregistered here.
     */
    public function testConsoleCommandsAreResolvable(): void
    {
        $this->artisan('list')->assertSuccessful();

        $commands = $this->app[Kernel::class]->all();

        self::assertArrayHasKey('event-sourcing:subscription:setup', $commands);
        self::assertArrayNotHasKey('event-sourcing:database:create', $commands);
        self::assertArrayNotHasKey('event-sourcing:database:drop', $commands);
        self::assertArrayNotHasKey('event-sourcing:schema:create', $commands);
        self::assertArrayNotHasKey('event-sourcing:schema:update', $commands);
        self::assertArrayNotHasKey('event-sourcing:schema:drop', $commands);
    }
}
