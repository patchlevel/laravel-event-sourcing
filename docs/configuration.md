# Configuration

:::note
You can find out more about event sourcing in the library
[documentation](/docs/event-sourcing/latest).
This documentation is limited to the laravel integration and configuration.
:::

:::tip
We provide a [default configuration](installation.md#configuration) that should work for most projects.
:::

## Aggregate

A path must be specified for Event Sourcing to know where to look for your aggregates.
If you want you can use glob patterns to specify multiple paths.

```php
return [
    'aggregates' => [app_path()],
];
```
Or use an array to specify multiple paths.

```php
return [
    'aggregates' => [
        app_path() . 'src/Hotel/Domain',
        app_path() . 'src/Room/Domain',
    ],
];
```
:::note
The library will automatically register all classes marked with the `#[Aggregate]` attribute in the specified paths.
:::

:::tip
If you want to learn more about aggregates, read the [library documentation](/docs/event-sourcing/latest/aggregate).
:::

## Events

A path must be specified for Event Sourcing to know where to look for your events.
If you want you can use glob patterns to specify multiple paths.

```php
return [
    'events' => [app_path()],
];
```
Or use an array to specify multiple paths.

```php
return [
    'events' => [
        app_path() . 'src/Hotel/Domain/Event',
        app_path() . 'src/Room/Domain/Event',
    ],
];
```
:::tip
If you want to learn more about events, read the [library documentation](/docs/event-sourcing/latest/events).
:::

## Custom Headers

If you want to implement custom headers for your application, you must specify the
paths to look for those headers.
If you want you can use glob patterns to specify multiple paths.

```php
return [
    'headers' => [app_path()],
];
```
Or use an array to specify multiple paths.

```php
return [
    'headers' => [
        app_path() . 'src/Hotel/Domain/Header',
        app_path() . 'src/Room/Domain/Header',
    ],
];
```
:::tip
If you want to learn more about custom headers, read the [library documentation](/docs/event-sourcing/latest/message#custom-headers).
:::

## Connection

By default the event store uses the laravel database connection.
You can pick which connection from `database.connections` should be used.

```php
return [
    'connection' => [
        'type' => 'illuminate',
        'connection' => env('EVENT_SOURCING_DB_CONNECTION', env('DB_CONNECTION', 'sqlite')),
    ],
];
```

Following connection types are available:

- `illuminate` *default*: uses the laravel database connection
- `dbal`: creates a dedicated doctrine dbal connection

The `dbal` type expects a connection url instead:

```php
return [
    'connection' => [
        'type' => 'dbal',
        'url' => env('EVENT_SOURCING_DB_URL'),
    ],
];
```
:::note
You can find out more about how to create a
[dbal connection](https://www.doctrine-project.org/projects/doctrine-dbal/en/latest/reference/configuration.html).
:::

:::warning
The connection type has to fit the store type.
The `illuminate_stream` store needs an `illuminate` connection,
the `dbal_*` stores need a `dbal` connection.
:::

The connection type also decides how the tables are created.
The `event-sourcing:schema:*` and `event-sourcing:database:*` commands are only registered
for the `dbal` connection type.
With `illuminate` you manage the schema with the shipped laravel migration instead.
:::tip
How to create the tables for both connection types is described in the
[installation guide](installation.md#schema).
:::

### Connection for Projections

Per default, our event sourcing connection is not available to use in your application.
But you can create a dedicated connection that you can use for your projections.

```php
return [
    'connection' => [
        'url' => env('EVENT_SOURCING_DB_URL'),
        'provide_dedicated_connection' => true,
    ],
];
```
:::warning
If you use doctrine migrations, you should exclude you projection tables from the schema generation.
The schema is managed by the subscription engine and should not be managed by doctrine.
:::

:::tip
You can autowire the connection in your services like this:

```php
use Doctrine\DBAL\Connection;
use Patchlevel\LaravelEventSourcing\Attribute\ProjectionConnection;

final class MyService
{
    public function __construct(
        #[ProjectionConnection]
        private readonly Connection $connection,
    ) {
    }
}
```
:::

## Store

The store and schema is configurable.

### Change Store type

You can change the store type of the event store.

```php
return [
    'store' => ['type' => 'dbal_stream'],
];
```
Following store types are available:

- `illuminate_stream` *default*
- `dbal_stream`
- `dbal_aggregate` *(deprecated)*
- `in_memory`
- `custom`

:::note
`illuminate_stream` and `dbal_stream` use the same table layout.
They only differ in the database abstraction they build on:
`illuminate_stream` uses the laravel connection, `dbal_stream` uses doctrine dbal.
:::

:::note
If you use `custom` store type, you need to set the service id under `store.service`.
:::

### Change table Name

You can change the table name of the event store.

```php
return [
    'store' => [
        'type' => 'dbal_stream',
        'options' => ['table_name' => 'my_event_store'],
    ],
];
```
:::warning
With the `illuminate` connection type, the table is created by the published migration.
If you change the table name here, you have to change it in the migration as well.
:::

### Read Only Mode

For the `illuminate_stream`, `dbal_aggregate` and `dbal_stream` store types you can activate the read only mode.
Readings are possible, but if you try to write, an exception `StoreIsReadOnly` is thrown.

```php
return [
    'store' => [
        'type' => 'dbal_stream',
        'read_only' => true,
    ],
];
```
:::tip
This is useful if you have maintenance work on the event store and you want to avoid side effects.
:::

### Data Migration

If you want to migrate from your current store to a new store, you can use the following configuration.
This registers a new store and a new cli command `event-sourcing:store:migrate`.
You can define translators to translate the old events to the new store.
Here is an example for a migration from `dbal_aggregate` to `dbal_stream`.

```php
use Patchlevel\EventSourcing\Message\Translator\AggregateToStreamHeaderTranslator;

return [
    'store' => [
        'type' => 'dbal_aggregate',
        'read_only' => true,
        'options' => ['table_name' => 'old_store'],
        'migrate_to_new_store' => [
            'enabled' => true,
            'type' => 'dbal_stream',
            'options' => ['table_name' => 'my_stream_store'],
            'translators' => [
                AggregateToStreamHeaderTranslator::class,
            ],
        ],
    ],
];
```
:::danger
Make sure that you use different table names for the old and new store.
Otherwise your event store will be destroyed.
:::

:::tip
Set the `read_only` flag to `true` for the old store to avoid side effects
and missing events during the migration.
:::

## Subscription

:::tip
You can find out more about subscriptions in the library
[documentation](/docs/event-sourcing/latest/subscription).
:::

### Store

You can change where the subscription engine stores its necessary information about the subscription.
Default is `illuminate`, which means it stores it in the same DB that is used by the event store.

Otherwise you can choose between the following stores:

- `illuminate` *default*
- `dbal`
- `in_memory`
- `static_in_memory`
- `custom`

```php
return [
    'subscription' => [
        'store' => [
            'type' => 'custom', // default is 'illuminate'
            'service' => 'my_subscription_store',
            'options' => ['table_name' => 'my_subscription_store'],
        ],
    ],
];
```
:::tip
You can use the `static_in_memory` store for testing, if you are using transactions to rollback changes.
:::

### Catch Up

If aggregates are used in the processors and new events are generated there,
then they are not part of the current subscription engine `run` and will only be processed during the next run or boot.
This is usually not a problem in prod environment because a worker is used
and these events will be processed at some point. But in testing it is not so easy.
For this reason, you can activate the `catch_up` option. For local dev this is also very handy.

```php
return [
    'subscription' => [
        'catch_up' => [
            'enabled' => true,
            'limit' => null, // define a limit to catch up only a limited number of events
        ],
    ],
];
```
### Throw on Error

You can activate the `throw_on_error` option to throw an exception if a subscription engine run has an error.
This is useful for testing and development to get direct feedback if something is wrong.

```php
return [
    'subscription' => [
        'throw_on_error' => ['enabled' => true],
    ],
];
```
:::warning
This option should not be used in production. The normal behavior is to log the error and continue.
:::

### Run After Aggregate Save

If you want to run the subscription engine after an aggregate is saved, you can activate this option.
This is useful for testing and development, so you don't have to run a worker to process the events.

```php
return [
    'subscription' => [
        'run_after_aggregate_save' => [
            'enabled' => true,
            'ids' => null, // limit to specific subscriptions ids
            'groups' => null, // limit to specific subscriptions groups
            'limit' => null, // limit how many events should be processed
        ],
    ],
];
```
### Auto Setup

If you want to automatically setup the subscription engine, you can activate this option.
This is useful for development, so you don't have to setup the subscription engine manually.

```php
return [
    'subscription' => [
        'auto_setup' => [
            'enabled' => true,
            'ids' => null, // limit to specific subscriptions ids
            'groups' => null, // limit to specific subscriptions groups
        ],
    ],
];
```
:::note
This works only before each http requests and not if you use the console commands.
:::

### Rebuild After File Change

If you want to rebuild the subscription engine after a file change, you can activate this option.
This is also useful for development, so you don't have to rebuild the projections manually.

```php
return [
    'subscription' => [
        'rebuild_after_file_change' => ['enabled' => true],
    ],
];
```
:::note
This works only before each http requests and not if you use the console commands.
:::

:::tip
This is using the cache system to store the latest file change time. You can change the cache pool with the `cache_pool` option.
:::

### Gap Detection

Depending on the database you are using for the eventstore it may be happening that your subscriptions are skipping some
events. This is due to how auto-increments work in these databases in combination with e.g. longer open transactions.
Even when not working with longer open transactions, this may occur if load is high on the database. We already have a
locking mechanism in place to prevent this behavior which throttles write speed. Gap Detection operates differently, it
checks if a gap between the last message handled and the current message is present. If so it waits a reasonable amount
of time and re-fetches the message. This results in slower updates for the subscriptions but creates more resilience.

```php
return [
    'subscription' => [
        'gap_detection' => ['enabled' => true],
    ],
];
```
:::note
For more context you can read more about this in [this issue](https://github.com/patchlevel/event-sourcing/issues/727#issuecomment-2757297536).
:::

:::tip
You can use both techniques locking and gap detecion to mitigate gaps happening in the subscriptions.
:::

You can also define how often the gap detection should re-check the gap and how long it should wait, in this example we
instantly retry the first time, then we wait 500ms and after that we check a last time after 1 second.

```php
return [
    'subscription' => [
        'gap_detection' => [
            'enabled' => true,
            'retries_in_ms' => [0, 5, 50, 500],
        ],
    ],
];
```
Another config option is to define the detection window. The option defines the timeframe from now if we should check
for a gap. It's defined as an [DateInterval](https://www.php.net/manual/en/class.dateinterval.php) so you need to
provide a valid `string` for it.

```php
return [
    'subscription' => [
        'gap_detection' => [
            'enabled' => true,
            'detection_window' => 'PT5M',
        ],
    ],
];
```
## Command Bus

You can enable the command bus integration to use your aggregates as command handlers.

```php
return [
    'subscription' => [
        'command_bus' => ['enabled' => true],
    ],
];
```
For now, we *do not* provide a laravel/queue integration, but we are open for suggestions.

:::note
You can find out more about the command bus and the aggregate handlers [here](/docs/event-sourcing/latest/command-bus).
:::

### Instant Retry

You can define the default instant retry configuration for the command bus.
This will be used if you don't define a retry configuration for a specific command.

```php
use Patchlevel\EventSourcing\Repository\AggregateOutdated;

return [
    'subscription' => [
        'command_bus' => [
            'enabled' => true,
            'instant_retry' => [
                'default_max_retries' => 3,
                'default_exceptions' => [
                    AggregateOutdated::class,
                ],
            ],
        ],
    ],
];
```
:::note
You can find out more about instant retry [here](/docs/event-sourcing/latest/command-bus#instant-retry).
:::

## Query Bus

You can enable the query bus integration to use queries to retrieve data from your system.

```php
return [
    'subscription' => [
        'query_bus' => ['enabled' => true],
    ],
];
```
For now, we *do not* provide a laravel/queue integration, but we are open for suggestions.

:::note
You can find out more about the query bus [here](/docs/event-sourcing/latest/query-bus).
:::

## Event Bus

You can enable the event bus to listen for events and messages synchronously.
The subscription engine is highly recommended to use instead of the event bus.

```php
return [
    'subscription' => [
        'event_bus' => ['enabled' => true],
    ],
];
```
:::note
Default is the patchlevel [event bus](/docs/event-sourcing/latest/event-bus).
:::

## Snapshot

You only need to tell the aggregate that it should use this snapshot store.

```php
namespace App\Profile\Domain;

use Patchlevel\EventSourcing\Aggregate\BasicAggregateRoot;
use Patchlevel\EventSourcing\Attribute\Aggregate;
use Patchlevel\EventSourcing\Attribute\Snapshot;

#[Aggregate(name: 'profile')]
#[Snapshot('default')]
final class Profile extends BasicAggregateRoot
{
    // ...
}
```
:::note
You can find out more about snapshots [here](/docs/event-sourcing/latest/snapshots).
:::

## Cryptography

You can use the library to encrypt and decrypt personal data.
For this you need to enable the crypto shredding.

```php
return [
    'cryptography' => [
        'enabled' => true,
        'use_encrypted_field_name' => true,
        'fallback_to_field_name' => false,
    ],
];
```
:::tip
You should activate `use_encrypted_field_name` to mark the fields that are encrypted.
That allows you later to migrate not encrypted fields to encrypted fields.
If you have already encrypted fields, you can activate `fallback_to_field_name` to use the old field name as fallback.
:::

If you want to use another algorithm, you can specify this here:

```php
return [
    'cryptography' => [
        'enabled' => true,
        'algorithm' => 'aes256',
    ],
];
```

The cipher keys are stored in the `crypto_keys` table.
You can change the store implementation and the table name:

```php
return [
    'cryptography' => [
        'enabled' => true,
        'store' => 'illuminate', // or 'dbal'
        'options' => ['table_name' => 'crypto_keys'],
    ],
];
```
:::note
You can find out more about sensitive data [here](/docs/event-sourcing/latest/personal-data).
:::

## Clock

The clock is used to return the current time as `DateTimeImmutable`.

### Freeze Clock

You can freeze the clock for testing purposes:

```php
return [
    'clock' => ['freeze' => '2020-01-01 22:00:00'],
];
```
:::note
If freeze is not set, then the system clock is used.
:::

### PSR-20

You can also use your own implementation of your choice.
They only have to implement the interface of the [psr-20](https://www.php-fig.org/psr/psr-20/).
You can then specify this service here:

```php
return [
    'clock' => ['service' => 'my_own_clock_service_id'],
];
```