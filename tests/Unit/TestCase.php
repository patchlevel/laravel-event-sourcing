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

        $schemaDirector = $this->app->get(SchemaDirector::class);
        $engine = $this->app->get(SubscriptionEngine::class);

        $schemaDirector->create();
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
}
