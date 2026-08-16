<?php

declare(strict_types=1);

namespace Patchlevel\LaravelEventSourcing\Tests\Unit;

use Illuminate\Support\Arr;
use Orchestra\Testbench\TestCase as Orchestra;
use Patchlevel\LaravelEventSourcing\EventSourcingServiceProvider;

use function str_starts_with;
use function substr;

/**
 * Unit test cases only cover the service registration and configuration.
 * Everything that talks to a database belongs into the integration test suite.
 */
abstract class TestCase extends Orchestra
{
    private const CONFIG_KEY = 'event-sourcing';

    /** @var array<string, mixed> */
    private array $configOverrides = [];

    protected function getPackageProviders($app): array
    {
        return [
            EventSourcingServiceProvider::class,
        ];
    }

    /**
     * The service provider reads most options eagerly while it registers, and testbench runs
     * `defineEnvironment()` only after that happened. So the configuration is seeded here, which
     * runs before the providers are registered.
     *
     * The whole package config is written at once on purpose: `mergeConfigFrom()` merges only the
     * first level, so setting a single nested key would drop all of its siblings.
     *
     * @param \Illuminate\Foundation\Application $app
     */
    protected function resolveApplicationConfiguration($app): void
    {
        parent::resolveApplicationConfiguration($app);

        /** @var array<string, mixed> $config */
        $config = require __DIR__ . '/../../config/event-sourcing.php';

        $overrides = [
            'event-sourcing.connection.url' => 'sqlite3:///:memory:',
            'event-sourcing.aggregates' => [__DIR__ . '/../Fixtures'],
            'event-sourcing.events' => [__DIR__ . '/../Fixtures'],
            ...$this->configOverrides,
        ];

        foreach ($overrides as $key => $value) {
            $prefix = self::CONFIG_KEY . '.';

            if (!str_starts_with($key, $prefix)) {
                $app['config']->set($key, $value);

                continue;
            }

            Arr::set($config, substr($key, strlen($prefix)), $value);
        }

        $app['config']->set(self::CONFIG_KEY, $config);
    }

    protected function setConfig(string $name, mixed $value): void
    {
        $this->resetApplicationWithConfig([$name => $value]);
    }

    /**
     * The provider decides from the config which services it binds, tags and decorates, so a
     * different config means a different container. Therefore the application is rebuilt instead
     * of registering the provider a second time: rebinding drops neither the extenders nor the
     * tags of the previous registration, which would stack them up.
     *
     * @param array<string, mixed> $values
     */
    protected function resetApplicationWithConfig(array $values): void
    {
        foreach ($values as $name => $value) {
            $this->configOverrides[$name] = $value;
        }

        $this->reloadApplication();
    }

    protected function configureDbal(): void
    {
        $this->resetApplicationWithConfig([
            'event-sourcing.connection.type' => 'dbal',
            'event-sourcing.store.type' => 'dbal_aggregate',
            'event-sourcing.subscription.store.type' => 'dbal',
            'event-sourcing.cryptography.store' => 'dbal',
        ]);
    }
}
