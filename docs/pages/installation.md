# Installation

This guide will help you to install the package in your laravel project.

## Require package

The first thing to do is to install packet if it has not already been done.

```bash
composer require patchlevel/laravel-event-sourcing
```
!!! note

    how to install [composer](https://getcomposer.org/doc/00-intro.md)
    
## Configuration

Next you need to publish the event sourcing config file.
It will be published to `config/event-sourcing.php`

```bash
php artisan vendor:publish --tag patchlevel-config
```
## Migrations

You can publish the migrations with the following command:

```bash
php artisan vendor:publish --tag patchlevel-migrations
```
And then run the migrations:

```bash
php artisan migrate
```
## Middlewares

Some features need a middleware to work properly.
You should add the middleware to your `bootstrap/app.php` file.

```php
use Patchlevel\LaravelEventSourcing\Middleware\EventSourcingMiddleware;

->withMiddleware(static function (Middleware $middleware): void {
    $middleware->append(EventSourcingMiddleware::class);
})
```
!!! success

    You have successfully installed the package! 
    You can now start using the event sourcing library in your laravel project.
    Start with the [quickstart](./getting_started.md) to get a feeling for the package.
    
!!! note

    This documentation is limited to the package integration.
    You should also read the [library documentation](https://event-sourcing.patchlevel.io/latest/).
    