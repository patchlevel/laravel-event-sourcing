<?php

use Patchlevel\EventSourcing\Repository\AggregateOutdated;

return [
    /*
    |--------------------------------------------------------------------------
    | Connection
    |--------------------------------------------------------------------------
    |
    | The dbal connection configuration for the event store.
    | Default is the default database connection,
    | that is configured in the laravel database configuration.
    |
    */
    'connection' => [
        'type' => 'illuminate',
        'url' => env('EVENT_SOURCING_DB_URL'),
        'connection' => env(
            'EVENT_SOURCING_DB_CONNECTION',
            env('DB_CONNECTION', 'sqlite')
        ),
        'provide_dedicated_connection' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Store
    |--------------------------------------------------------------------------
    |
    | Here you can configure the event store.
    | You can choose between different types of stores.
    | dbal_aggregate (legacy): Store events in a single table with the aggregate and aggregate id.
    | dbal_stream: Store events in a single table with a stream id using dbal.
    | illuminate_stream (default, based on dbal_stream): Store events in a single table with a stream id using illuminate.
    | in_memory: Store events in memory.
    | custom: Use a custom store, you need to provide a service.
    |
    */
    'store' => [
        'type' => 'illuminate_stream',
        'service' => null,
        'options' => [
            'table_name' => 'event_store',
        ],
        'read_only' => false,

        /*
        | Here you can configure the migration options for the event store.
        | If you enable this option you can use our migration services for a smooth migration.
        | You can specify which translators should be used for the migration process and also
        | to which store you want to migrate.
        | The same store types as above are available.
        */
        'migrate_to_new_store' => [
            'enabled' => false,
            'type' => '',
            'service' => null,
            'options' => [],
            'translators' => [],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Events, Aggregates, Headers
    |--------------------------------------------------------------------------
    |
    | Here you can define the paths where the package should look for
    | events, aggregates and headers.
    |
    */
    'events' => [app_path()],
    'aggregates' => [app_path()],
    'headers' => [app_path()],

    /*
    |--------------------------------------------------------------------------
    | Subscription
    |--------------------------------------------------------------------------
    |
    | Here you can configure the subscription.
    | The subscription engine is default in pseudo sync mode.
    | You can change it to full async mode,
    | by setting 'subscription.run_after_aggregate_save.enabled' to false.
    | In this case you need to use the `event-sourcing:subscription:run` command.
    | You should also disable 'subscription.catch_up'
    | and 'subscription.throw_on_error'.
    |
    */
    'subscription' => [
        'throw_on_error' => [
            'enabled' => true,
        ],
        'catch_up' => [
            'enabled' => true,
            'limit' => null,
        ],
        'retry_strategies' => [
            'default' => [
                'type' => 'clock_based',
                'options' => [
                    'base_delay' => 5,
                    'delay_factor' => 2,
                    'max_attempts' => 5,
                ],
            ],
            'no_retry' => [
                'type' => 'no_retry',
            ],
        ],
        'default_retry_strategy' => 'default',
        'store' => [
            'type' => 'illuminate',
            'service' => null,
            'options' => [
                'table_name' => 'subscriptions',
            ],
        ],
        'run_after_aggregate_save' => [
            'enabled' => true,
            'ids' => null,
            'groups' => null,
            'limit' => null,
        ],
        'rebuild_after_file_change' => [
            'enabled' => true,
        ],
        'auto_setup' => [
            'enabled' => true,
            'ids' => null,
            'groups' => null,
        ],
        'gap_detection' => [
            'enabled' => true,
            'retries_in_ms' => [0, 5, 50, 500],
            'detection_window' => 'PT5M',
        ],
        'cleanup_task_handlers' => [
            // App\Subscription\Cleanup\YourCleanupTaskHandler::class
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Cryptography
    |--------------------------------------------------------------------------
    |
    | Here you can enable or disable the cryptography.
    | You can also define the algorithm for the cryptography.
    | It is disabled by default, because it requires the openssl extension
    | and has a performance impact due to registered listeners.
    |
    */
    'cryptography' => [
        'enabled' => false,
        'store' => 'illuminate',
        'options' => [
            'table_name' => 'crypto_keys',
        ],
        'algorithm' => 'aes256',
        'use_encrypted_field_name' => true,
        'fallback_to_field_name' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | CommandBus
    |--------------------------------------------------------------------------
    |
    | Here you can enable or disable the command bus.
    | You can also configure the command bus regarding the retries and the handlers.
    |
    */
    'command_bus' => [
        'enabled' => true,
        'instant_retry' => [
            'max_retries' => 3,
            'exceptions' => [
                AggregateOutdated::class,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | QueryBus
    |--------------------------------------------------------------------------
    |
    | Here you can enable or disable the query bus.
    |
    */
    'query_bus' => [
        'enabled' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | EventBus
    |--------------------------------------------------------------------------
    |
    | Here you can enable or disable the event bus.
    | The subscription engine is highly recommended to use instead of the event bus.
    |
    */
    'event_bus' => [
        'enabled' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Clock
    |--------------------------------------------------------------------------
    |
    | Here you can enable or disable the freeze clock or set a custom clock.
    | This is useful for testing purposes.
    |
    */
    'clock' => [
        'freeze' => null,
        'service' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Services
    |--------------------------------------------------------------------------
    |
    | Here you can define your own services.
    |
    | Upcaster: Upcasters are used to convert old events to new events.
    | Message Decorator: Message Decorators are used to decorate messages.
    | Listener: Listeners are used to listen to events in the event bus.
    | Subscriber: Subscribers are used to subscribe to events for subscription engine.
    | Argument Resolver: Argument Resolvers are used to resolve arguments for subscribers.
    |
    */
    'upcaster' => [
        // App\Upcaster\YourUpcaster::class
    ],
    'message_decorator' => [
        // App\MessageDecorator\YourMessageDecorator::class
    ],
    'listeners' => [
        // App\Listener\YourListener::class
    ],
    'subscribers' => [
        // App\Subscribers\YourSubscriber::class
    ],
    'argument_resolvers' => [
        // App\ArgumentResolvers\YourResolver::class
    ],
];
