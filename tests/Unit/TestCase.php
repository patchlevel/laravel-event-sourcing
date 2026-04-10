<?php

declare(strict_types=1);

namespace Patchlevel\LaravelEventSourcing\Tests\Unit;

use Orchestra\Testbench\TestCase as Orchestra;
use Patchlevel\EventSourcing\Schema\SchemaDirector;
use Patchlevel\EventSourcing\Subscription\Engine\SubscriptionEngine;
use Patchlevel\LaravelEventSourcing\EventSourcingServiceProvider;

abstract class TestCase extends Orchestra
{

    public function setUp(): void
    {
        parent::setUp();

        if ($this->app->bound(SchemaDirector::class)) {
            $schemaDirector = $this->app->get(SchemaDirector::class);
            $schemaDirector->create();
        } else {
            $migration = include __DIR__ . '/../../database/migrations/create_eventsourcing_tables.php';
            $migration->up();
        }

        $engine = $this->app->get(SubscriptionEngine::class);
        $engine->setup(skipBooting: true);
    }

    protected function getPackageProviders($app): array
    {
        return [
            EventSourcingServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('event-sourcing.connection.url', 'sqlite3:///:memory:');
        $app['config']->set('event-sourcing.aggregates', [__DIR__ . '/../Fixtures']);
        $app['config']->set('event-sourcing.events', [__DIR__ . '/../Fixtures']);
    }

    protected function setConfig(string $name, $value)
    {
        config()->set($name, $value);

        (new EventSourcingServiceProvider($this->app))->register();
    }

    protected function configureDbal(): void
    {
        $this->setConfig('event-sourcing.connection.type', 'dbal');
        $this->setConfig('event-sourcing.store.type', 'dbal_aggregate');
        $this->setConfig('event-sourcing.subscription.store.type', 'dbal');
        $this->setConfig('event-sourcing.cryptography.store', 'dbal');
    }
}
