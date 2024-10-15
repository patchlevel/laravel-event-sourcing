<?php

return [
    'connection' => [
        'url' => env('EVENT_SOURCING_DB_URL'),
        'connection' => env(
            'EVENT_SOURCING_DB_CONNECTION',
            env('DB_CONNECTION', 'sqlite')
        ),
    ],
    'store' => [
        'type' => 'dbal_aggregate',
        'service' => null,
        'options' => []
    ],
    '' => '',
    'events' => [app_path()],
    'aggregates' => [app_path()],
    'headers' => [app_path()],
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
    'upcaster' => [
        // App\Upcaster\YourUpcaster::class
    ],
    'message_decorator' => [
        // App\MessageDecorator\YourUpcaster::class
    ],
    'listeners' => [
        // App\Listener\YourListener::class
    ],
    'subscribers' => [
        // App\Subscribers\YourListener::class
    ],
    'argument_resolvers' => [
        // App\ArgumentResolvers\YourListener::class
    ],
];
