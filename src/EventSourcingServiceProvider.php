<?php

declare(strict_types=1);

namespace Patchlevel\LaravelEventSourcing;

use DateInterval;
use DateTimeImmutable;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Patchlevel\EventSourcing\Clock\FrozenClock;
use Patchlevel\EventSourcing\Clock\SystemClock;
use Patchlevel\EventSourcing\CommandBus\AggregateHandlerProvider;
use Patchlevel\EventSourcing\CommandBus\CommandBus;
use Patchlevel\EventSourcing\CommandBus\InstantRetryCommandBus;
use Patchlevel\EventSourcing\CommandBus\SyncCommandBus;
use Patchlevel\EventSourcing\Console\Command\DatabaseCreateCommand;
use Patchlevel\EventSourcing\Console\Command\DatabaseDropCommand;
use Patchlevel\EventSourcing\Console\Command\DebugCommand;
use Patchlevel\EventSourcing\Console\Command\SchemaCreateCommand;
use Patchlevel\EventSourcing\Console\Command\SchemaDropCommand;
use Patchlevel\EventSourcing\Console\Command\SchemaUpdateCommand;
use Patchlevel\EventSourcing\Console\Command\ShowAggregateCommand;
use Patchlevel\EventSourcing\Console\Command\ShowCommand;
use Patchlevel\EventSourcing\Console\Command\StoreMigrateCommand;
use Patchlevel\EventSourcing\Console\Command\SubscriptionBootCommand;
use Patchlevel\EventSourcing\Console\Command\SubscriptionPauseCommand;
use Patchlevel\EventSourcing\Console\Command\SubscriptionReactivateCommand;
use Patchlevel\EventSourcing\Console\Command\SubscriptionRemoveCommand;
use Patchlevel\EventSourcing\Console\Command\SubscriptionRunCommand;
use Patchlevel\EventSourcing\Console\Command\SubscriptionSetupCommand;
use Patchlevel\EventSourcing\Console\Command\SubscriptionStatusCommand;
use Patchlevel\EventSourcing\Console\Command\SubscriptionTeardownCommand;
use Patchlevel\EventSourcing\Console\Command\WatchCommand;
use Patchlevel\EventSourcing\Console\DoctrineHelper;
use Patchlevel\EventSourcing\Cryptography\DoctrineCipherKeyStore;
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
use Patchlevel\EventSourcing\Metadata\AggregateRoot\AttributeAggregateRootRegistryFactory;
use Patchlevel\EventSourcing\Metadata\Event\AttributeEventMetadataFactory;
use Patchlevel\EventSourcing\Metadata\Event\AttributeEventRegistryFactory;
use Patchlevel\EventSourcing\Metadata\Event\EventMetadataFactory;
use Patchlevel\EventSourcing\Metadata\Event\EventRegistry;
use Patchlevel\EventSourcing\Metadata\Message\AttributeMessageHeaderRegistryFactory;
use Patchlevel\EventSourcing\Metadata\Message\MessageHeaderRegistry;
use Patchlevel\EventSourcing\Metadata\Message\MessageHeaderRegistryFactory;
use Patchlevel\EventSourcing\Metadata\Subscriber\AttributeSubscriberMetadataFactory;
use Patchlevel\EventSourcing\Metadata\Subscriber\SubscriberMetadataFactory;
use Patchlevel\EventSourcing\QueryBus\QueryBus;
use Patchlevel\EventSourcing\QueryBus\ServiceHandlerProvider;
use Patchlevel\EventSourcing\QueryBus\SyncQueryBus;
use Patchlevel\EventSourcing\Repository\DefaultRepositoryManager;
use Patchlevel\EventSourcing\Repository\MessageDecorator\ChainMessageDecorator;
use Patchlevel\EventSourcing\Repository\MessageDecorator\MessageDecorator;
use Patchlevel\EventSourcing\Repository\MessageDecorator\SplitStreamDecorator;
use Patchlevel\EventSourcing\Repository\RepositoryManager;
use Patchlevel\EventSourcing\Schema\ChainDoctrineSchemaConfigurator;
use Patchlevel\EventSourcing\Schema\DoctrineSchemaConfigurator;
use Patchlevel\EventSourcing\Schema\DoctrineSchemaDirector;
use Patchlevel\EventSourcing\Schema\SchemaDirector;
use Patchlevel\EventSourcing\Serializer\DefaultEventSerializer;
use Patchlevel\EventSourcing\Serializer\Encoder\Encoder;
use Patchlevel\EventSourcing\Serializer\Encoder\JsonEncoder;
use Patchlevel\EventSourcing\Serializer\EventSerializer;
use Patchlevel\EventSourcing\Serializer\Upcast\Upcaster;
use Patchlevel\EventSourcing\Serializer\Upcast\UpcasterChain;
use Patchlevel\EventSourcing\Snapshot\DefaultSnapshotStore;
use Patchlevel\EventSourcing\Snapshot\SnapshotStore;
use Patchlevel\EventSourcing\Store\DoctrineDbalStore;
use Patchlevel\EventSourcing\Store\InMemoryStore;
use Patchlevel\EventSourcing\Store\ReadOnlyStore;
use Patchlevel\EventSourcing\Store\Store;
use Patchlevel\EventSourcing\Store\StreamDoctrineDbalStore;
use Patchlevel\EventSourcing\Store\StreamReadOnlyStore;
use Patchlevel\EventSourcing\Subscription\Cleanup\Cleaner;
use Patchlevel\EventSourcing\Subscription\Cleanup\Dbal\DbalCleanupTaskHandler;
use Patchlevel\EventSourcing\Subscription\Cleanup\DefaultCleaner;
use Patchlevel\EventSourcing\Subscription\Engine\CatchUpSubscriptionEngine;
use Patchlevel\EventSourcing\Subscription\Engine\DefaultSubscriptionEngine;
use Patchlevel\EventSourcing\Subscription\Engine\GapResolverStoreMessageLoader;
use Patchlevel\EventSourcing\Subscription\Engine\MessageLoader;
use Patchlevel\EventSourcing\Subscription\Engine\StoreMessageLoader;
use Patchlevel\EventSourcing\Subscription\Engine\SubscriptionEngine;
use Patchlevel\EventSourcing\Subscription\Engine\ThrowOnErrorSubscriptionEngine;
use Patchlevel\EventSourcing\Subscription\Repository\RunSubscriptionEngineRepositoryManager;
use Patchlevel\EventSourcing\Subscription\RetryStrategy\ClockBasedRetryStrategy;
use Patchlevel\EventSourcing\Subscription\RetryStrategy\NoRetryStrategy;
use Patchlevel\EventSourcing\Subscription\RetryStrategy\RetryStrategyRepository;
use Patchlevel\EventSourcing\Subscription\Store\DoctrineSubscriptionStore;
use Patchlevel\EventSourcing\Subscription\Store\InMemorySubscriptionStore;
use Patchlevel\EventSourcing\Subscription\Store\SubscriptionStore;
use Patchlevel\EventSourcing\Subscription\Subscriber\ArgumentResolver\EventArgumentResolver;
use Patchlevel\EventSourcing\Subscription\Subscriber\ArgumentResolver\LookupResolver;
use Patchlevel\EventSourcing\Subscription\Subscriber\ArgumentResolver\MessageArgumentResolver;
use Patchlevel\EventSourcing\Subscription\Subscriber\ArgumentResolver\RecordedOnArgumentResolver;
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
use Patchlevel\Hydrator\Metadata\AttributeMetadataFactory;
use Patchlevel\Hydrator\MetadataHydrator;
use Patchlevel\LaravelEventSourcing\Cryptography\IlluminateCipherKeyStore;
use Patchlevel\LaravelEventSourcing\Middleware\AutoSetupMiddleware;
use Patchlevel\LaravelEventSourcing\Middleware\EventSourcingMiddleware;
use Patchlevel\LaravelEventSourcing\Middleware\SubscriptionRebuildAfterFileChangeMiddleware;
use Patchlevel\LaravelEventSourcing\Store\StreamIlluminateStore;
use Patchlevel\LaravelEventSourcing\Subscription\Cleanup\IlluminateCleanupTaskHandler;
use Patchlevel\LaravelEventSourcing\Subscription\StaticInMemorySubscriptionStoreFactory;
use Patchlevel\LaravelEventSourcing\Subscription\Store\IlluminateSubscriptionStore;

use function app;
use function array_filter;
use function array_key_exists;
use function config;
use function config_path;
use function database_path;
use function is_array;
use function is_string;
use function sprintf;
use function str_starts_with;

class EventSourcingServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $singletons = [
        EventMetadataFactory::class => AttributeEventMetadataFactory::class,
        Encoder::class => JsonEncoder::class,
        MessageHeaderRegistryFactory::class => AttributeMessageHeaderRegistryFactory::class,
        AggregateRootMetadataFactory::class => AggregateRootMetadataAwareMetadataFactory::class,
        SubscriberMetadataFactory::class => AttributeSubscriberMetadataFactory::class,
    ];

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/event-sourcing.php' => config_path('event-sourcing.php'),
        ], 'patchlevel-config');

        $this->publishesMigrations([
            __DIR__ . '/../database/migrations/' => database_path('migrations'),
        ], 'patchlevel-migrations');

        if (!$this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            DatabaseCreateCommand::class,
            DatabaseDropCommand::class,
            ShowCommand::class,
            ShowAggregateCommand::class,
            WatchCommand::class,
            DebugCommand::class,
            SubscriptionSetupCommand::class,
            SubscriptionBootCommand::class,
            SubscriptionRunCommand::class,
            SubscriptionTeardownCommand::class,
            SubscriptionRemoveCommand::class,
            SubscriptionStatusCommand::class,
            SubscriptionPauseCommand::class,
            SubscriptionReactivateCommand::class,
            StoreMigrateCommand::class,
        ]);

        if (config('event-sourcing.connection.type') !== 'dbal') {
            return;
        }

        $this->commands([
            SchemaCreateCommand::class,
            SchemaUpdateCommand::class,
            SchemaDropCommand::class,
        ]);
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/event-sourcing.php', 'event-sourcing');

        $this->registerHydrator();
        $this->registerUpcaster();
        $this->registerSerializer();
        $this->registerMessageDecorator();
        $this->registerCommandBus();
        $this->registerEventBus();
        $this->registerQueryBus();
        $this->registerConnection();
        $this->registerStore();
        $this->registerSnapshots();
        $this->registerAggregates();
        $this->registerDebugCommands();
        // add maybe telescope integration?
        $this->registerClock();
        $this->registerSchema();
        $this->registerMessageLoader();
        $this->registerSubscription();
        $this->registerCryptography();
        // do we want to add doctrine migrations?
        // $this->registerValueResolver(); in symf bundle we have an id route resolver - this seems not easy possible
        $this->registerStoreMigration();
    }

    private function registerCommandBus(): void
    {
        if (!config('event-sourcing.command_bus.enabled')) {
            return;
        }

        $this->app->singleton(
            CommandBus::class,
            static fn ($app) => new InstantRetryCommandBus(
                new SyncCommandBus(app(AggregateHandlerProvider::class)),
                config('event-sourcing.command_bus.instant_retry.max_retries'),
                config('event-sourcing.command_bus.instant_retry.exceptions'),
            ),
        );
    }

    private function registerQueryBus(): void
    {
        if (!config('event-sourcing.query_bus.enabled')) {
            return;
        }

        $this->app->singleton(
            QueryBus::class,
            static fn ($app) => new SyncQueryBus(
                new ServiceHandlerProvider($app->tagged('event_sourcing.subscriber')),
                app('log'),
            ),
        );
    }

    private function registerConnection(): void
    {
        /** @var string $type */
        $type = config('event-sourcing.connection.type');
        $provideDedicatedConnection = (bool)config('event-sourcing.connection.provide_dedicated_connection');

        if ($type === 'dbal') {
            $connectionCreationCallback = static function () {
                $url = config('event-sourcing.connection.url');

                if (is_string($url)) {
                    return DriverManager::getConnection(
                        (new DsnParser())->parse($url),
                    );
                }

                /** @var array<string, array{url: string|null, driver: string, database?: string|null, username?: string|null, password?: string|null, host?: string|null, port?: int|null}> $connections */
                $connections = config('database.connections');

                /** @var string $connectionKey */
                $connectionKey = config('event-sourcing.connection.connection');

                if (!array_key_exists($connectionKey, $connections)) {
                    throw new InvalidArgumentException(sprintf('Connection "%s" not found', $connectionKey));
                }

                $connectionParams = $connections[$connectionKey];

                if ($connectionParams['url'] ?? false) {
                    return DriverManager::getConnection(
                        (new DsnParser())->parse($connectionParams['url']),
                    );
                }

                /** @var 'pdo_mysql'|'pdo_pgsql'|'pdo_sqlite' $driver */
                $driver = match ($connectionParams['driver']) {
                    'mysql', 'mariadb' => 'pdo_mysql',
                    'pgsql' => 'pdo_pgsql',
                    'sqlite' => 'pdo_sqlite',
                    default => $connectionParams['driver'],
                };

                return DriverManager::getConnection(
                    array_filter(
                        [
                            'driver' => $driver,
                            'dbname' => $connectionParams['database'] ?? null,
                            'path' => $connectionParams['database'] ?? null,
                            'user' => $connectionParams['username'] ?? null,
                            'password' => $connectionParams['password'] ?? null,
                            'host' => $connectionParams['host'] ?? null,
                            'port' => $connectionParams['port'] ?? null,
                        ],
                        static fn (mixed $value) => $value !== null,
                    ),
                );
            };

            $publicConnectionCreationCallback = $connectionCreationCallback;
        } elseif ($type === 'illuminate') {
            $connectionCreationCallback = static fn () => DB::connection();

            if ($provideDedicatedConnection) {
                $base = config('database.connections.' . config('database.default'));
                Config::set('database.connections.event_sourcing_public', $base);
                DB::purge('event_sourcing_public');
            }

            $publicConnectionCreationCallback = static fn () => DB::connection('event_sourcing_public');
        } else {
            throw new InvalidArgumentException(sprintf('Unknown connection type "%s"', $type));
        }

        // the generic ids are always available, the type specific one only for the active type
        $this->app->singleton('event_sourcing.connection', $connectionCreationCallback);
        $this->app->alias('event_sourcing.connection', sprintf('event_sourcing.%s_connection', $type));

        if (!$provideDedicatedConnection) {
            return;
        }

        $this->app->singleton('event_sourcing.public_connection', $publicConnectionCreationCallback);
        $this->app->alias('event_sourcing.public_connection', sprintf('event_sourcing.%s_public_connection', $type));
    }

    private function registerStore(): void
    {
        $this->app->singleton(Store::class, static function () {
            /** @var string $type */
            $type = config('event-sourcing.store.type');

            if ($type === 'custom') {
                if (config('event-sourcing.store.read_only')) {
                    throw new InvalidArgumentException('Custom store type does not support read only');
                }

                /** @var string $service */
                $service = config('event-sourcing.store.service');

                return app($service);
            }

            if ($type === 'in_memory') {
                if (config('event-sourcing.store.read_only')) {
                    throw new InvalidArgumentException('In memory store type does not support read only');
                }

                return new InMemoryStore(
                    [],
                    app(EventRegistry::class),
                    app('event_sourcing.clock'),
                );
            }

            /** @var array<string, mixed> $options */
            $options = config('event-sourcing.store.options');

            if ($type === 'dbal_aggregate') {
                $store = new DoctrineDbalStore(
                    app('event_sourcing.connection'),
                    app(EventSerializer::class),
                    app(HeadersSerializer::class),
                    $options,
                );

                if (config('event-sourcing.store.read_only')) {
                    $store = new ReadOnlyStore($store, app('log'));
                }

                return $store;
            }

            if ($type === 'dbal_stream') {
                $store = new StreamDoctrineDbalStore(
                    app('event_sourcing.connection'),
                    app(EventSerializer::class),
                    app(HeadersSerializer::class),
                    app('event_sourcing.clock'),
                    $options,
                );

                if (config('event-sourcing.store.read_only')) {
                    $store = new StreamReadOnlyStore($store, app('log'));
                }

                return $store;
            }

            if ($type === 'illuminate_stream') {
                $store = new StreamIlluminateStore(
                    app('event_sourcing.connection'),
                    app(EventSerializer::class),
                    app(HeadersSerializer::class),
                    app('event_sourcing.clock'),
                    $options,
                );

                if (config('event-sourcing.store.read_only')) {
                    $store = new StreamReadOnlyStore($store, app('log'));
                }

                return $store;
            }

            throw new InvalidArgumentException(sprintf('Unknown store type "%s"', $type));
        });

        /** @var string $type */
        $type = config('event-sourcing.store.type');

        if (!str_starts_with($type, 'dbal_')) {
            return;
        }

        $this->app->tag(Store::class, ['event_sourcing.doctrine_schema_configurator']);
    }

    private function registerSerializer(): void
    {
        $this->app->singleton(EventRegistry::class, static function () {
            /** @var list<string> $paths */
            $paths = config('event-sourcing.events');

            return (new AttributeEventRegistryFactory())->create($paths);
        });

        $this->app->singleton(EventSerializer::class, static function () {
            return new DefaultEventSerializer(
                app(EventRegistry::class),
                app(Hydrator::class),
                app(Encoder::class),
                app(Upcaster::class),
            );
        });

        $this->app->singleton(MessageHeaderRegistry::class, static function () {
            return (new AttributeMessageHeaderRegistryFactory())->create(config('event-sourcing.headers'));
        });

        $this->app->singleton(HeadersSerializer::class, static function () {
            return new DefaultHeadersSerializer(
                app(MessageHeaderRegistry::class),
                app(Hydrator::class),
                app(Encoder::class),
            );
        });
    }

    private function registerHydrator(): void
    {
        $this->app->singleton(Hydrator::class, static function () {
            return new MetadataHydrator(
                new AttributeMetadataFactory(),
                config('event-sourcing.cryptography.enabled') ? app(PayloadCryptographer::class) : null,
            );
        });
    }

    private function registerClock(): void
    {
        $this->app->singleton('event_sourcing.clock', static function () {
            $freeze = config('event-sourcing.clock.freeze');

            if ($freeze !== null) {
                return new FrozenClock(new DateTimeImmutable($freeze));
            }

            $service = config('event-sourcing.clock.service');

            if ($service !== null) {
                return app($service);
            }

            return new SystemClock();
        });
    }

    private function registerAggregates(): void
    {
        $this->app->singleton(AggregateRootRegistry::class, static function () {
            return (new AttributeAggregateRootRegistryFactory())->create(config('event-sourcing.aggregates'));
        });

        $this->app->singleton(RepositoryManager::class, static function () {
            return new DefaultRepositoryManager(
                app(AggregateRootRegistry::class),
                app(Store::class),
                config('event-sourcing.event_bus.enabled') ? app(EventBus::class) : null,
                app(SnapshotStore::class),
                app(MessageDecorator::class),
                app('event_sourcing.clock'),
                app(AggregateRootMetadataFactory::class),
                app('log'),
            );
        });
    }

    private function registerSchema(): void
    {
        if (config('event-sourcing.connection.type') !== 'dbal') {
            return;
        }

        $this->app->singleton(DoctrineSchemaConfigurator::class, function () {
            return new ChainDoctrineSchemaConfigurator(
                $this->app->tagged('event_sourcing.doctrine_schema_configurator'),
            );
        });

        $this->app->singleton(SchemaDirector::class, static function () {
            return new DoctrineSchemaDirector(
                app('event_sourcing.connection'),
                app(DoctrineSchemaConfigurator::class),
            );
        });

        $this->app->singleton(DatabaseCreateCommand::class, static function () {
            return new DatabaseCreateCommand(
                app('event_sourcing.connection'),
                new DoctrineHelper(),
            );
        });

        $this->app->singleton(DatabaseDropCommand::class, static function () {
            return new DatabaseDropCommand(
                app('event_sourcing.connection'),
                new DoctrineHelper(),
            );
        });

        $this->app->singleton(SchemaCreateCommand::class, static function () {
            return new SchemaCreateCommand(
                app(SchemaDirector::class),
            );
        });

        $this->app->singleton(SchemaUpdateCommand::class, static function () {
            return new SchemaUpdateCommand(
                app(SchemaDirector::class),
            );
        });

        $this->app->singleton(SchemaDropCommand::class, static function () {
            return new SchemaDropCommand(
                app(SchemaDirector::class),
            );
        });
    }

    private function registerDebugCommands(): void
    {
        $this->app->singleton(ShowCommand::class, static function () {
            return new ShowCommand(
                app(Store::class),
                app(EventSerializer::class),
                app(HeadersSerializer::class),
            );
        });

        $this->app->singleton(ShowAggregateCommand::class, static function () {
            return new ShowAggregateCommand(
                app(Store::class),
                app(EventSerializer::class),
                app(HeadersSerializer::class),
                app(AggregateRootRegistry::class),
            );
        });

        $this->app->singleton(WatchCommand::class, static function () {
            return new WatchCommand(
                app(Store::class),
                app(EventSerializer::class),
                app(HeadersSerializer::class),
            );
        });

        $this->app->singleton(DebugCommand::class, static function () {
            return new DebugCommand(
                app(AggregateRootRegistry::class),
                app(EventRegistry::class),
            );
        });
    }

    private function registerUpcaster(): void
    {
        /** @var class-string $class */
        foreach (config('event-sourcing.upcaster') as $class) {
            $this->app->tag($class, 'event_sourcing.upcaster');
        }

        $this->app->singleton(Upcaster::class, function () {
            return new UpcasterChain(
                $this->app->tagged('event_sourcing.upcaster'),
            );
        });
    }

    private function registerMessageDecorator(): void
    {
        /** @var class-string $class */
        foreach (config('event-sourcing.message_decorator') as $class) {
            $this->app->tag($class, 'event_sourcing.message_decorator');
        }

        $this->app->singleton(MessageDecorator::class, function () {
            return new ChainMessageDecorator(
                $this->app->tagged('event_sourcing.message_decorator'),
            );
        });

        $this->app->singleton(SplitStreamDecorator::class, static function () {
            return new SplitStreamDecorator(
                app(EventMetadataFactory::class),
            );
        });

        $this->app->tag(SplitStreamDecorator::class, ['event_sourcing.message_decorator']);
    }

    private function registerEventBus(): void
    {
        if (!config('event-sourcing.event_bus.enabled')) {
            return;
        }

        /** @var class-string $class */
        foreach (config('event-sourcing.listeners') as $class) {
            $this->app->tag($class, 'event_sourcing.listener');
        }

        $this->app->singleton(ListenerProvider::class, function () {
            return new AttributeListenerProvider(
                $this->app->tagged('event_sourcing.listener'),
            );
        });

        $this->app->singleton(Consumer::class, static function () {
            return new DefaultConsumer(
                app(ListenerProvider::class),
                app('log'),
            );
        });

        $this->app->singleton(EventBus::class, static function () {
            return new DefaultEventBus(
                app(Consumer::class),
                app('log'),
            );
        });
    }

    private function registerSnapshots(): void
    {
        $this->app->singleton(SnapshotStore::class, static function () {
            return new DefaultSnapshotStore(
                new LaravelSnapshotAdapterRepository(),
                app(Hydrator::class),
                app(AggregateRootMetadataFactory::class),
            );
        });
    }

    private function registerMessageLoader(): void
    {
        if (config('event-sourcing.subscription.gap_detection.enabled')) {
            $this->app->singleton(MessageLoader::class, static function () {
                return new GapResolverStoreMessageLoader(
                    app(Store::class),
                    app('event_sourcing.clock'),
                    config('event-sourcing.subscription.gap_detection.retries_in_ms'),
                    new DateInterval(config('event-sourcing.subscription.gap_detection.detection_window')),
                );
            });

            return;
        }

        $this->app->singleton(MessageLoader::class, static function () {
            return new StoreMessageLoader(app(Store::class));
        });
    }

    private function registerSubscription(): void
    {
        /** @var class-string $class */
        foreach (config('event-sourcing.subscribers') as $class) {
            $this->app->tag($class, 'event_sourcing.subscriber');
        }

        if (config('event-sourcing.subscription.retry_strategy') && config('event-sourcing.subscription.retry_strategies')) {
            throw new InvalidArgumentException('Cannot use "retry_strategies" and "retry_strategy" at the same time. Use only "retry_strategies".');
        }

        $strategies = [];

        if (config('event-sourcing.subscription.retry_strategy')) {
            $strategies['default'] = new ClockBasedRetryStrategy(
                app('event_sourcing.clock'),
                config('event-sourcing.subscription.retry_strategy.base_delay') ?? ClockBasedRetryStrategy::DEFAULT_BASE_DELAY,
                config('event-sourcing.subscription.retry_strategy.delay_factor') ?? ClockBasedRetryStrategy::DEFAULT_DELAY_FACTOR,
                config('event-sourcing.subscription.retry_strategy.max_attempts') ?? ClockBasedRetryStrategy::DEFAULT_MAX_ATTEMPTS,
            );
            $strategies['no_retry'] = new NoRetryStrategy();
        }

        foreach (config('event-sourcing.subscription.retry_strategies') ?? [] as $name => $config) {
            if ($config['type'] === 'custom') {
                $strategies[$name] = app($config['service']);

                continue;
            }

            if ($config['type'] === 'clock_based') {
                $strategies[$name] = new ClockBasedRetryStrategy(
                    app('event_sourcing.clock'),
                    $config['options']['base_delay'] ?? 5,
                    $config['options']['delay_factor'] ?? 2,
                    $config['options']['max_attempts'] ?? 5,
                );

                continue;
            }

            if ($config['type'] === 'no_retry') {
                $strategies[$name] = new NoRetryStrategy();

                continue;
            }

            throw new InvalidArgumentException(sprintf('Unknown retry strategy type "%s"', $config['type']));
        }

        $this->app->singleton(
            RetryStrategyRepository::class,
            static fn () => new RetryStrategyRepository(
                $strategies,
                config('event-sourcing.subscription.default_retry_strategy'),
            ),
        );

        $this->app->singleton(SubscriberHelper::class, static function () {
            return new SubscriberHelper(
                app(SubscriberMetadataFactory::class),
            );
        });

        $subscriptionStoreType = config('event-sourcing.subscription.store.type');

        if ($subscriptionStoreType === 'custom') {
            if (config('event-sourcing.subscription.store.service') === null) {
                throw new InvalidArgumentException('Custom subscription store type requires a service');
            }

            $storeCallback = static fn () => app(config('event-sourcing.subscription.store.service'));
        } elseif ($subscriptionStoreType === 'in_memory') {
            $storeCallback = static fn () => new InMemorySubscriptionStore([], app('event_sourcing.clock'));
        } elseif ($subscriptionStoreType === 'static_in_memory') {
            $storeCallback = static fn () => StaticInMemorySubscriptionStoreFactory::create();
        } elseif ($subscriptionStoreType === 'dbal') {
            $storeCallback = static fn () => new DoctrineSubscriptionStore(
                app('event_sourcing.connection'),
                app('event_sourcing.clock'),
                config('event-sourcing.subscription.store.options.table_name'),
            );
        } elseif ($subscriptionStoreType === 'illuminate') {
            $storeCallback = static fn () => new IlluminateSubscriptionStore(
                app('event_sourcing.connection'),
                app('event_sourcing.clock'),
                config('event-sourcing.subscription.store.options.table_name'),
            );
        } else {
            throw new InvalidArgumentException('Subscription store type is unknown.');
        }

        $this->app->singleton(SubscriptionStore::class, $storeCallback);

        if ($subscriptionStoreType === 'dbal') {
            $this->app->tag(SubscriptionStore::class, ['event_sourcing.doctrine_schema_configurator']);
        }

        $this->registerCleaner();

        $this->app->tag(
            [
                LookupResolver::class,
                RecordedOnArgumentResolver::class,
                EventArgumentResolver::class,
                MessageArgumentResolver::class,
            ],
            'event_sourcing.argument_resolver',
        );

        /** @var class-string $class */
        foreach (config('event-sourcing.argument_resolvers') as $class) {
            $this->app->tag($class, 'event_sourcing.argument_resolver');
        }

        $this->app->singleton(SubscriberAccessorRepository::class, function () {
            return new MetadataSubscriberAccessorRepository(
                $this->app->tagged('event_sourcing.subscriber'),
                app(SubscriberMetadataFactory::class),
                $this->app->tagged('event_sourcing.argument_resolver'),
            );
        });

        $this->app->singleton(SubscriptionEngine::class, static function () {
            return new DefaultSubscriptionEngine(
                app(Store::class),
                app(SubscriptionStore::class),
                app(SubscriberAccessorRepository::class),
                app(RetryStrategyRepository::class),
                app('log'),
                app(Cleaner::class),
            );
        });

        if ($this->optionEnabled('event-sourcing.subscription.throw_on_error')) {
            $this->app->extend(SubscriptionEngine::class, static function (SubscriptionEngine $engine) {
                return new ThrowOnErrorSubscriptionEngine($engine);
            });
        }

        if ($this->optionEnabled('event-sourcing.subscription.catch_up')) {
            $this->app->extend(SubscriptionEngine::class, static function (SubscriptionEngine $engine) {
                return new CatchUpSubscriptionEngine($engine, config('event-sourcing.subscription.catch_up.limit'));
            });
        }

        if (config('event-sourcing.subscription.run_after_aggregate_save.enabled')) {
            $this->app->extend(RepositoryManager::class, static function (RepositoryManager $manager) {
                return new RunSubscriptionEngineRepositoryManager(
                    $manager,
                    app(SubscriptionEngine::class),
                    config('event-sourcing.subscription.run_after_aggregate_save.ids'),
                    config('event-sourcing.subscription.run_after_aggregate_save.groups'),
                    config('event-sourcing.subscription.run_after_aggregate_save.limit'),
                );
            });
        }

        $this->app->singleton(AutoSetupMiddleware::class, static function () {
            return new AutoSetupMiddleware(
                app(SubscriptionEngine::class),
                config('event-sourcing.subscription.auto_setup.ids'),
                config('event-sourcing.subscription.auto_setup.groups'),
            );
        });

        $this->app->singleton(SubscriptionRebuildAfterFileChangeMiddleware::class, function () {
            return new SubscriptionRebuildAfterFileChangeMiddleware(
                app(SubscriptionEngine::class),
                $this->app->tagged('event_sourcing.subscriber'),
                app(SubscriberMetadataFactory::class),
            );
        });

        $this->app->singleton(EventSourcingMiddleware::class, static function () {
            $autoSetup = config('event-sourcing.subscription.auto_setup.enabled');
            $rebuildAfterFileChange = config('event-sourcing.subscription.rebuild_after_file_change.enabled');

            return new EventSourcingMiddleware(
                $autoSetup ? app(AutoSetupMiddleware::class) : null,
                $rebuildAfterFileChange ? app(SubscriptionRebuildAfterFileChangeMiddleware::class) : null,
            );
        });

        $this->app->singleton(
            SubscriptionSetupCommand::class,
            static fn () => new SubscriptionSetupCommand(
                app(SubscriptionEngine::class),
            ),
        );

        $this->app->singleton(
            SubscriptionBootCommand::class,
            static fn () => new SubscriptionBootCommand(
                app(SubscriptionEngine::class),
            ),
        );

        $this->app->singleton(
            SubscriptionRunCommand::class,
            static fn () => new SubscriptionRunCommand(
                app(SubscriptionEngine::class),
                app(Store::class),
            ),
        );

        $this->app->singleton(
            SubscriptionTeardownCommand::class,
            static fn () => new SubscriptionTeardownCommand(
                app(SubscriptionEngine::class),
            ),
        );

        $this->app->singleton(
            SubscriptionRemoveCommand::class,
            static fn () => new SubscriptionRemoveCommand(
                app(SubscriptionEngine::class),
            ),
        );

        $this->app->singleton(
            SubscriptionStatusCommand::class,
            static fn () => new SubscriptionStatusCommand(
                app(SubscriptionEngine::class),
            ),
        );

        $this->app->singleton(
            SubscriptionPauseCommand::class,
            static fn () => new SubscriptionPauseCommand(
                app(SubscriptionEngine::class),
            ),
        );

        $this->app->singleton(
            SubscriptionReactivateCommand::class,
            static fn () => new SubscriptionReactivateCommand(app(SubscriptionEngine::class)),
        );
    }

    /**
     * Reads an "enabled" flag from an option that is configured as an array.
     * Older published configs may still hold a plain bool, so both shapes are accepted.
     */
    private function optionEnabled(string $key): bool
    {
        $value = config($key);

        if (is_array($value)) {
            return (bool)($value['enabled'] ?? false);
        }

        return (bool)$value;
    }

    private function registerCleaner(): void
    {
        if (config('event-sourcing.connection.type') === 'dbal') {
            $this->app->singleton(
                DbalCleanupTaskHandler::class,
                static fn () => new DbalCleanupTaskHandler(app('event_sourcing.connection')),
            );

            $this->app->tag(DbalCleanupTaskHandler::class, ['event_sourcing.cleanup_task_handler']);
        } else {
            $this->app->singleton(
                IlluminateCleanupTaskHandler::class,
                static fn () => new IlluminateCleanupTaskHandler(app('db')),
            );

            $this->app->tag(IlluminateCleanupTaskHandler::class, ['event_sourcing.cleanup_task_handler']);
        }

        /** @var class-string $class */
        foreach (config('event-sourcing.subscription.cleanup_task_handlers') ?? [] as $class) {
            $this->app->tag($class, 'event_sourcing.cleanup_task_handler');
        }

        $this->app->singleton(Cleaner::class, function () {
            return new DefaultCleaner(
                $this->app->tagged('event_sourcing.cleanup_task_handler'),
            );
        });
    }

    private function registerCryptography(): void
    {
        if (!config('event-sourcing.cryptography.enabled')) {
            return;
        }

        $this->app->singleton(
            CipherKeyFactory::class,
            static fn () => new OpensslCipherKeyFactory(config('event-sourcing.cryptography.algorithm')),
        );

        /** @var string $tableName */
        $tableName = config('event-sourcing.cryptography.options.table_name') ?? 'crypto_keys';

        $cryptographyStoreType = config('event-sourcing.cryptography.store');

        if ($cryptographyStoreType === 'dbal') {
            $storeCallback = static fn () => new DoctrineCipherKeyStore(
                app('event_sourcing.connection'),
                $tableName,
            );
        } elseif ($cryptographyStoreType === 'illuminate') {
            $storeCallback = static fn () => new IlluminateCipherKeyStore(
                app('event_sourcing.connection'),
                $tableName,
            );
        } else {
            throw new InvalidArgumentException('Cryptography store type is unknown.');
        }

        $this->app->singleton(CipherKeyStore::class, $storeCallback);

        if ($cryptographyStoreType === 'dbal') {
            $this->app->tag(CipherKeyStore::class, ['event_sourcing.doctrine_schema_configurator']);
        }

        $this->app->singleton(Cipher::class, static fn () => new OpensslCipher());

        $this->app->singleton(
            PayloadCryptographer::class,
            static fn () => new PersonalDataPayloadCryptographer(
                app(CipherKeyStore::class),
                app(CipherKeyFactory::class),
                app(Cipher::class),
                config('event-sourcing.cryptography.use_encrypted_field_name'),
                config('event-sourcing.cryptography.fallback_to_field_name'),
            ),
        );
    }

    private function registerStoreMigration(): void
    {
        if (!config('event-sourcing.store.migrate_to_new_store.enabled')) {
            return;
        }

        $id = 'event_sourcing.store.new_store';

        foreach (config('event-sourcing.store.migrate_to_new_store.translators') as $class) {
            $this->app->tag($class, 'event_sourcing.translator');
        }

        $storeType = config('event-sourcing.store.migrate_to_new_store.type');
        if ($storeType === 'custom') {
            if (config('event-sourcing.store.migrate_to_new_store.service') === null) {
                throw new InvalidArgumentException('Custom store type requires a service');
            }

            $this->app->singleton($id, static fn () => app(config('event-sourcing.store.migrate_to_new_store.service')));
        } elseif ($storeType === 'in_memory') {
            $this->app->singleton(
                $id,
                static fn () => new InMemoryStore(
                    [],
                    app(EventRegistry::class),
                    app('event_sourcing.clock'),
                ),
            );
        } elseif ($storeType === 'dbal_aggregate') {
            $this->app->singleton(
                $id,
                static fn () => new DoctrineDbalStore(
                    app('event_sourcing.connection'),
                    app(EventSerializer::class),
                    app(HeadersSerializer::class),
                    config('event-sourcing.store.migrate_to_new_store.options'),
                ),
            );
        } elseif ($storeType === 'dbal_stream') {
            $this->app->singleton(
                $id,
                static fn () => new StreamDoctrineDbalStore(
                    app('event_sourcing.connection'),
                    app(EventSerializer::class),
                    app(HeadersSerializer::class),
                    app('event_sourcing.clock'),
                    config('event-sourcing.store.migrate_to_new_store.options'),
                ),
            );
        } elseif ($storeType === 'illuminate_stream') {
            $this->app->singleton(
                $id,
                static fn () => new StreamIlluminateStore(
                    app('event_sourcing.connection'),
                    app(EventSerializer::class),
                    app(HeadersSerializer::class),
                    app('event_sourcing.clock'),
                    config('event-sourcing.store.migrate_to_new_store.options'),
                ),
            );
        } else {
            throw new InvalidArgumentException(sprintf('Unknown store type "%s"', $storeType));
        }

        $this->app->singleton(
            StoreMigrateCommand::class,
            static fn ($app) => new StoreMigrateCommand(
                app(Store::class),
                app($id),
                $app->tagged('event_sourcing.translator'),
            ),
        );
    }
}
