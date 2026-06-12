# Facades

We offer facades for easy access to event sourcing services.
You can use the facades to access the repositories, the store or manage your aggregates.
This feature is optional, you can still use the services directly via dependency injection.

## Aggregate

If your aggregates extend the laravel package provided `AggregateRoot` class,
you can use the helper methods to load and save your aggregates.

```php
use Patchlevel\EventSourcing\Attribute\Aggregate;
use Patchlevel\LaravelEventSourcing\AggregateRoot;

#[Aggregate(name: 'hotel')]
final class Hotel extends AggregateRoot
{
    // ...
}
```
With the static `load` method of your specific aggregate class you can load your aggregates.

```php
use Patchlevel\EventSourcing\Aggregate\Uuid;

$hotel = Hotel::load(Uuid::fromString('123'));
```
And save them by using the `save` method on the aggregate instance.

```php
$hotel->save();
```
## Repository

You can access the specific repositories using the `get` method of the `Repository` facade.

```php
use Patchlevel\LaravelEventSourcing\Facade\Repository;

$repository = Repository::get(Hotel::class);
```
## Store

You can access the store using the `Store` facade.
There you can save multiple messages at once:

```php
use Patchlevel\LaravelEventSourcing\Facade\Store;

Store::save(/* messages... */);
```
or load messages by criteria:

```php
use Patchlevel\EventSourcing\Store\Criteria\AggregateIdCriterion;
use Patchlevel\EventSourcing\Store\Criteria\Criteria;
use Patchlevel\LaravelEventSourcing\Facade\Store;

$messages = Store::load(
    new Criteria(
        new AggregateIdCriterion('123'),
    ),
);
```
:::note
This documentation is limited to the package integration.
You should also read the [library documentation](/docs/event-sourcing/latest).
:::

## Projection Connection

You can access the projection connection using the `ProjectionConnection` facade.
This facade provides you the `DBAL\Connection` used to connect to the projection database.

:::note
This documentation is limited to the package integration.
You should also read the [dbal documentation](https://www.doctrine-project.org/projects/doctrine-dbal/en/latest/reference/data-retrieval-and-manipulation.html#api).
:::

## CommandBus

You can access the command bus using the `CommandBus` facade. With this facade you can dispatch commands.

```php
CommandBus::dispatch(new BookHotel());
```
Then, the command will be handled by the corresponding command handler specified via `#[Handle]` attribute.

## QueryBus

You can access the query bus using the `QueryBus` facade. With this facade you can dispatch queries.

```php
$result = QueryBus::dispatch(new HotelCountQuery());
```
Then, the query will be handled by the corresponding query handler specified via `#[Answer]` attribute.
