# Installation

This guide will help you to install the package in your laravel project.

## Require package

The first thing to do is to install packet if it has not already been done.

```bash
composer require patchlevel/laravel-event-sourcing=1.0.0-beta3
```
:::note
how to install [composer](https://getcomposer.org/doc/00-intro.md)
:::

## Configuration

Next you need to publish the event sourcing config file.
It will be published to `config/event-sourcing.php`

```bash
php artisan vendor:publish --tag patchlevel-config
```
## Schema

How the tables are created depends on the configured [connection](configuration.md#connection) type.

### Illuminate

With the `illuminate` connection type, which is the default, laravel manages the schema.
You can publish the shipped migration with the following command:

```bash
php artisan vendor:publish --tag patchlevel-migrations
```
And then run the migrations:

```bash
php artisan migrate
```
The migration creates the `event_store`, `subscriptions` and `crypto_keys` tables.
It is published into your application, so you can edit it like any other migration,
for example to remove the `crypto_keys` table if you don't use [cryptography](configuration.md#cryptography).
:::warning
The migration uses the default table names.
If you change one of them in the config, you have to adjust the published migration accordingly.
:::

### Dbal

With the `dbal` connection type, the library manages the schema and derives the tables from your config.
There is nothing to publish here, use the schema command instead:

```bash
php artisan event-sourcing:schema:create
```
There are also commands to update or drop the schema and to create or drop the database itself:

```bash
php artisan event-sourcing:schema:update
php artisan event-sourcing:schema:drop
php artisan event-sourcing:database:create
php artisan event-sourcing:database:drop
```
:::note
These commands are only registered for the `dbal` connection type.
With `illuminate` they are not available and you use the published migration instead.
:::

## Middlewares

Some features need a middleware to work properly.
You should add the middleware to your `bootstrap/app.php` file.

```php
use Patchlevel\LaravelEventSourcing\Middleware\EventSourcingMiddleware;

$app->withMiddleware(static function (Middleware $middleware): void {
    $middleware->append(EventSourcingMiddleware::class);
});
```
:::success
You have successfully installed the package!
You can now start using the event sourcing library in your laravel project.
Start with the [quickstart](getting-started.md) to get a feeling for the package.
:::

:::note
This documentation is limited to the package integration.
You should also read the [library documentation](/docs/event-sourcing/latest).
:::
