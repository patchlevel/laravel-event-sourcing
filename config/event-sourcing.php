<?php

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
        'url' => env('EVENT_SOURCING_DB_URL'),
        'connection' => env(
            'EVENT_SOURCING_DB_CONNECTION',
            env('DB_CONNECTION', 'sqlite')
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Store
    |--------------------------------------------------------------------------
    |
    | Here you can configure the event store.
    | You can choose between different types of stores.
    |
    | dbal_stream (default): Store events in a single table with a stream id.
    | dbal_aggregate: Store events in a single table with the aggregate and aggregate id.
    | in_memory: Store events in memory.
    | custom: Use a custom store, you need to provide a service.
    |
    */

    'store' => [
        'type' => 'dbal_stream',
        'service' => null,
        'options' => [
            'table_name' => 'eventstore',
        ]
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
    | You should also set the 'subscription.catch_up'
    | and 'subscription.throw_on_error' to false.
    |
    */

    'subscription' => [
        'throw_on_error' => true,
        'catch_up' => true,
        'retry_strategy' => [
            'base_delay' => 5,
            'delay_factor' => 2,
            'max_attempts' => 5,
        ],
        'run_after_aggregate_save' => [
            'enabled' => true,
            'ids' => null,
            'groups' => null,
            'limit' => null
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Cryptography
    |--------------------------------------------------------------------------
    |
    | Here you can enable or disable the cryptography.
    | You can also define the algorithm for the cryptography.
    |
    */

    'cryptography' => [
        'enabled' => true,
        'algorithm' => 'aes256'
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
