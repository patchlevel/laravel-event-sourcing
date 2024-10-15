<?php

declare(strict_types=1);

namespace Patchlevel\LaravelEventSourcing;

use DateTimeImmutable;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Patchlevel\EventSourcing\Clock\FrozenClock;
use Patchlevel\EventSourcing\Clock\SystemClock;
use Patchlevel\EventSourcing\Console\Command\DatabaseCreateCommand;
use Patchlevel\EventSourcing\Console\Command\DatabaseDropCommand;
use Patchlevel\EventSourcing\Console\Command\DebugCommand;
use Patchlevel\EventSourcing\Console\Command\SchemaCreateCommand;
use Patchlevel\EventSourcing\Console\Command\SchemaDropCommand;
use Patchlevel\EventSourcing\Console\Command\SchemaUpdateCommand;
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
use Patchlevel\EventSourcing\Console\DoctrineHelper;
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
use Patchlevel\EventSourcing\Store\Store;
use Patchlevel\EventSourcing\Store\StreamDoctrineDbalStore;
use Patchlevel\EventSourcing\Subscription\Engine\CatchUpSubscriptionEngine;
use Patchlevel\EventSourcing\Subscription\Engine\DefaultSubscriptionEngine;
use Patchlevel\EventSourcing\Subscription\Engine\SubscriptionEngine;
use Patchlevel\EventSourcing\Subscription\Engine\ThrowOnErrorSubscriptionEngine;
use Patchlevel\EventSourcing\Subscription\Repository\RunSubscriptionEngineRepositoryManager;
use Patchlevel\EventSourcing\Subscription\RetryStrategy\ClockBasedRetryStrategy;
use Patchlevel\EventSourcing\Subscription\RetryStrategy\RetryStrategy;
use Patchlevel\EventSourcing\Subscription\Store\DoctrineSubscriptionStore;
use Patchlevel\EventSourcing\Subscription\Store\SubscriptionStore;
use Patchlevel\EventSourcing\Subscription\Subscriber\MetadataSubscriberAccessorRepository;
use Patchlevel\EventSourcing\Subscription\Subscriber\SubscriberAccessorRepository;
use Patchlevel\EventSourcing\Subscription\Subscriber\SubscriberHelper;
use Patchlevel\Hydrator\Hydrator;
use Patchlevel\Hydrator\Metadata\AttributeMetadataFactory;
use Patchlevel\Hydrator\MetadataHydrator;

use function array_key_exists;
use function sprintf;
use function str_starts_with;

class EventSourcingServiceProvider extends ServiceProvider
{
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
        ]);

        if (!$this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            DatabaseCreateCommand::class,
            DatabaseDropCommand::class,
            SchemaCreateCommand::class,
            SchemaUpdateCommand::class,
            SchemaDropCommand::class,
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
        ]);
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/event-sourcing.php', 'event-sourcing');

        $this->registerConnection();
        $this->registerStore();
        $this->registerSerializer();
        $this->registerHydrator();
        $this->registerClock();
        $this->registerAggregates();
        $this->registerDebugCommands();
        $this->registerSchema();
        $this->registerUpcaster();
        $this->registerMessageDecorator();
        $this->registerEventBus();
        $this->registerSnapshots();
        $this->registerSubscription();
    }

    private function registerConnection(): void
    {
        $this->app->singleton('event_sourcing.dbal_connection', static function () {
            if (config('event-sourcing.connection.url')) {
                return DriverManager::getConnection(
                    (new DsnParser())->parse(config('event-sourcing.connection.url')),
                );
            }

            $connections = config('database.connections');
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

            $driver = match ($connectionParams['driver']) {
                'mysql', 'mariadb' => 'pdo_mysql',
                'pgsql' => 'pdo_pgsql',
                'sqlite' => 'pdo_sqlite',
                default => $connectionParams['driver'],
            };

            return DriverManager::getConnection(
                [
                    'driver' => $driver,
                    'dbname' => $connectionParams['database'],
                    'user' => $connectionParams['username'],
                    'password' => $connectionParams['password'],
                    'host' => $connectionParams['host'],
                    'port' => $connectionParams['port'],
                ],
            );
        });
    }

    private function registerStore(): void
    {
        $this->app->singleton(Store::class, static function () {
            $type = config('event-sourcing.store.type');

            if ($type === 'custom') {
                return app(config('event-sourcing.store.service'));
            }

            if ($type === 'in_memory') {
                return new InMemoryStore();
            }

            if ($type === 'dbal_aggregate') {
                return new DoctrineDbalStore(
                    app('event_sourcing.dbal_connection'),
                    app(EventSerializer::class),
                    app(HeadersSerializer::class),
                    config('event-sourcing.store.options'),
                );
            }

            if ($type === 'dbal_stream') {
                return new StreamDoctrineDbalStore(
                    app('event_sourcing.dbal_connection'),
                    app(EventSerializer::class),
                    app(HeadersSerializer::class),
                    app('event_sourcing.clock'),
                    config('event-sourcing.store.options'),
                );
            }

            throw new InvalidArgumentException(sprintf('Unknown store type "%s"', $type));
        });

        if (!str_starts_with(config('event-sourcing.store.type'), 'dbal_')) {
            return;
        }

        $this->app->tag(Store::class, ['event_sourcing.doctrine_schema_configurator']);
    }

    private function registerSerializer(): void
    {
        $this->app->singleton(EventRegistry::class, static function () {
            return (new AttributeEventRegistryFactory())->create(config('event-sourcing.events'));
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
                null, //app(PayloadCryptographer::class),
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
                app(EventBus::class),
                app(SnapshotStore::class),
                app(MessageDecorator::class),
                app('event_sourcing.clock'),
                app(AggregateRootMetadataFactory::class),
                null, // app('logger', null),
            );
        });
    }

    private function registerSchema(): void
    {
        $this->app->singleton(DoctrineSchemaConfigurator::class, function () {
            return new ChainDoctrineSchemaConfigurator(
                $this->app->tagged('event_sourcing.doctrine_schema_configurator'),
            );
        });

        $this->app->singleton(SchemaDirector::class, static function () {
            return new DoctrineSchemaDirector(
                app('event_sourcing.dbal_connection'),
                app(DoctrineSchemaConfigurator::class),
            );
        });

        $this->app->singleton(DatabaseCreateCommand::class, static function () {
            return new DatabaseCreateCommand(
                app('event_sourcing.dbal_connection'),
                new DoctrineHelper(),
            );
        });

        $this->app->singleton(DatabaseDropCommand::class, static function () {
            return new DatabaseDropCommand(
                app('event_sourcing.dbal_connection'),
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
                null, // app('logger', null),
            );
        });

        $this->app->singleton(EventBus::class, static function () {
            return new DefaultEventBus(
                app(Consumer::class),
                null, // app('logger', null),
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

    private function registerSubscription(): void
    {
        foreach (config('event-sourcing.subscribers') as $class) {
            $this->app->tag($class, 'event_sourcing.subscriber');
        }

        $this->app->singleton(RetryStrategy::class, static function () {
            return new ClockBasedRetryStrategy(
                app('event_sourcing.clock'),
                config('event-sourcing.subscription.retry_strategy.base_delay'),
                config('event-sourcing.subscription.retry_strategy.delay_factor'),
                config('event-sourcing.subscription.retry_strategy.max_attempts'),
            );
        });

        $this->app->singleton(SubscriberHelper::class, static function () {
            return new SubscriberHelper(
                app(SubscriberMetadataFactory::class),
            );
        });

        $this->app->singleton(SubscriptionStore::class, static function () {
            return new DoctrineSubscriptionStore(
                app('event_sourcing.dbal_connection'),
            );
        });

        $this->app->tag(SubscriptionStore::class, ['event_sourcing.doctrine_schema_configurator']);

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
                app(RetryStrategy::class),
                null, // app('logger', null),
            );
        });

        if (config('event-sourcing.subscription.throw_on_error')) {
            $this->app->extend(SubscriptionEngine::class, static function (SubscriptionEngine $engine) {
                return new ThrowOnErrorSubscriptionEngine($engine);
            });
        }

        if (config('event-sourcing.subscription.catch_up')) {
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

        if (config('event-sourcing.subscription.auto_setup.enabled')) {
            /*
                      $container->register(AutoSetupListener::class)
                ->setArguments([
                    new Reference(SubscriptionEngine::class),
                    $config['subscription']['auto_setup']['ids'] ?: null,
                    $config['subscription']['auto_setup']['groups'] ?: null,
                ])
                ->addTag('kernel.event_listener', [
                    'event' => 'kernel.request',
                    'priority' => 200,
                    'method' => 'onKernelRequest',
                ]);
             */
        }

        if (config('event-sourcing.subscription.rebuild_after_file_change')) {
            /*
                     $container->register(SubscriptionRebuildAfterFileChangeListener::class)
            ->setArguments([
                new Reference(SubscriptionEngine::class),
                new TaggedIteratorArgument('event_sourcing.subscriber'),
                new Reference('cache.app'),
                new Reference(SubscriberMetadataFactory::class),
            ])
            ->addTag('kernel.event_listener', [
                'event' => 'kernel.request',
                'priority' => 100,
                'method' => 'onKernelRequest',
            ]);
             */
        }

        $this->app->singleton(SubscriptionSetupCommand::class, static function () {
            return new SubscriptionSetupCommand(
                app(SubscriptionEngine::class),
            );
        });

        $this->app->singleton(SubscriptionBootCommand::class, static function () {
            return new SubscriptionBootCommand(
                app(SubscriptionEngine::class),
            );
        });

        $this->app->singleton(SubscriptionRunCommand::class, static function () {
            return new SubscriptionRunCommand(
                app(SubscriptionEngine::class),
                app(Store::class),
            );
        });

        $this->app->singleton(SubscriptionTeardownCommand::class, static function () {
            return new SubscriptionTeardownCommand(
                app(SubscriptionEngine::class),
            );
        });

        $this->app->singleton(SubscriptionRemoveCommand::class, static function () {
            return new SubscriptionRemoveCommand(
                app(SubscriptionEngine::class),
            );
        });

        $this->app->singleton(SubscriptionStatusCommand::class, static function () {
            return new SubscriptionStatusCommand(
                app(SubscriptionEngine::class),
            );
        });

        $this->app->singleton(SubscriptionPauseCommand::class, static function () {
            return new SubscriptionPauseCommand(
                app(SubscriptionEngine::class),
            );
        });

        $this->app->singleton(SubscriptionReactivateCommand::class, static function () {
            return new SubscriptionReactivateCommand(
                app(SubscriptionEngine::class),
            );
        });
    }
}
