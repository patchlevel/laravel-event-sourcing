<?php

declare(strict_types=1);

namespace Patchlevel\LaravelEventSourcing\Tests\Application;

use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Support\Arr;
use Orchestra\Testbench\TestCase as Orchestra;
use Patchlevel\EventSourcing\Subscription\Engine\SubscriptionEngine;
use Patchlevel\EventSourcing\Subscription\Engine\SubscriptionEngineCriteria;
use Patchlevel\EventSourcing\Subscription\Status;
use Patchlevel\LaravelEventSourcing\EventSourcingServiceProvider;
use Patchlevel\LaravelEventSourcing\Middleware\EventSourcingMiddleware;
use Patchlevel\LaravelEventSourcing\Tests\Application\Processor\SendWelcomeMailProcessor;
use Patchlevel\LaravelEventSourcing\Tests\Application\Projection\ProfileProjector;

use function realpath;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * Uses the package like an application does after following the installation docs: the service provider
 * with the shipped config, the shipped migration, the middleware registered globally, and the default
 * laravel database connection (so `DB_URL` and `DB_CONNECTION` select the database, like in the ci).
 *
 * Tests only interact through facades, artisan and http requests, the services are never wired by hand.
 */
abstract class ApplicationTestCase extends Orchestra
{
    private const CONFIG_KEY = 'event-sourcing';

    /** @var array<string, mixed> */
    private array $configOverrides = [];

    public function setUp(): void
    {
        parent::setUp();

        $this->installEventSourcing();
    }

    protected function getPackageProviders($app): array
    {
        return [
            EventSourcingServiceProvider::class,
        ];
    }

    /**
     * The provider reads most options while it registers, and testbench runs `defineEnvironment()` only
     * after that. `mergeConfigFrom()` merges only the first level, so the whole package config is written.
     *
     * @param \Illuminate\Foundation\Application $app
     */
    protected function resolveApplicationConfiguration($app): void
    {
        parent::resolveApplicationConfiguration($app);

        /** @var array<string, mixed> $config */
        $config = require __DIR__ . '/../../config/event-sourcing.php';

        $overrides = [
            'event-sourcing.aggregates' => [__DIR__],
            'event-sourcing.events' => [__DIR__],
            'event-sourcing.subscribers' => [ProfileProjector::class, SendWelcomeMailProcessor::class],
            ...$this->configOverrides,
        ];

        foreach ($overrides as $key => $value) {
            Arr::set($config, substr($key, strlen(self::CONFIG_KEY . '.')), $value);
        }

        $app['config']->set(self::CONFIG_KEY, $config);
    }

    /** @param \Illuminate\Foundation\Application $app */
    protected function defineEnvironment($app): void
    {
        $app->make(HttpKernel::class)->pushMiddleware(EventSourcingMiddleware::class);
    }

    /** @param \Illuminate\Routing\Router $router */
    protected function defineRoutes($router): void
    {
        $router->get('/', static fn () => 'ok');
    }

    /** @param array<string, mixed> $config */
    protected function reloadApplicationWithConfig(array $config): void
    {
        foreach ($config as $key => $value) {
            if (!str_starts_with($key, self::CONFIG_KEY . '.')) {
                continue;
            }

            $this->configOverrides[$key] = $value;
        }

        $this->reloadApplication();
        $this->installEventSourcing();
    }

    protected function projector(): ProfileProjector
    {
        return $this->app->make(ProfileProjector::class);
    }

    protected function removeSubscription(): void
    {
        $this->artisan('event-sourcing:subscription:remove', ['--id' => ['profile']])->assertSuccessful();
    }

    protected function subscriptionStatus(): Status
    {
        $subscriptions = $this->app->get(SubscriptionEngine::class)->subscriptions(
            new SubscriptionEngineCriteria(['profile']),
        );

        self::assertCount(1, $subscriptions);

        return $subscriptions[0]->status();
    }

    /** What a deployment does: migrate, then set up and boot the subscriptions. */
    private function installEventSourcing(): void
    {
        // `migrate:fresh` only wipes the database if a migrations table exists,
        // so tables left over by other test suites would not be dropped
        $this->artisan('db:wipe', ['--force' => true])->assertSuccessful();

        $this->artisan('migrate', [
            '--path' => realpath(__DIR__ . '/../../database/migrations'),
            '--realpath' => true,
        ])->assertSuccessful();

        $this->artisan('event-sourcing:subscription:setup')->assertSuccessful();
        $this->artisan('event-sourcing:subscription:boot')->assertSuccessful();
    }
}
