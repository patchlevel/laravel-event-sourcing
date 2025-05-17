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
!!! note

    This documentation is limited to the package integration.
    You should also read the [library documentation](https://event-sourcing.patchlevel.io/latest/).
    