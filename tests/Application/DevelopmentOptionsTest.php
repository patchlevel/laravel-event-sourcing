<?php

declare(strict_types=1);

namespace Patchlevel\LaravelEventSourcing\Tests\Application;

use Patchlevel\LaravelEventSourcing\Facade\CommandBus;
use Patchlevel\LaravelEventSourcing\Tests\Application\Command\CreateProfile;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;

use function array_keys;
use function putenv;

#[CoversNothing]
final class DevelopmentOptionsTest extends ApplicationTestCase
{
    private const OPTIONS = [
        'EVENT_SOURCING_THROW_ON_ERROR' => 'event-sourcing.subscription.throw_on_error.enabled',
        'EVENT_SOURCING_CATCH_UP' => 'event-sourcing.subscription.catch_up.enabled',
        'EVENT_SOURCING_RUN_AFTER_AGGREGATE_SAVE' => 'event-sourcing.subscription.run_after_aggregate_save.enabled',
        'EVENT_SOURCING_AUTO_SETUP' => 'event-sourcing.subscription.auto_setup.enabled',
        'EVENT_SOURCING_REBUILD_AFTER_FILE_CHANGE' => 'event-sourcing.subscription.rebuild_after_file_change.enabled',
    ];

    public function tearDown(): void
    {
        foreach (array_keys(self::OPTIONS) as $name) {
            $this->setEnv($name, null);
        }

        parent::tearDown();
    }

    #[DataProvider('optionProvider')]
    public function testOptionIsEnabledByDefault(string $env, string $configKey): void
    {
        self::assertTrue(config($configKey));
    }

    #[DataProvider('optionProvider')]
    public function testOptionIsDisabledByTheEnvironment(string $env, string $configKey): void
    {
        $this->setEnv($env, 'false');
        $this->reloadApplicationWithConfig([]);

        self::assertFalse(config($configKey));
    }

    /** @return iterable<array{string, string}> */
    public static function optionProvider(): iterable
    {
        foreach (self::OPTIONS as $env => $configKey) {
            yield $env => [$env, $configKey];
        }
    }

    public function testProductionEnvironmentNeedsAWorker(): void
    {
        foreach (array_keys(self::OPTIONS) as $name) {
            $this->setEnv($name, 'false');
        }

        $this->reloadApplicationWithConfig([]);

        CommandBus::dispatch(new CreateProfile(ProfileId::generate(), 'John', 'john@example.com'));

        self::assertSame([], $this->projector()->names());

        $this->artisan('event-sourcing:subscription:run', ['--run-limit' => 1])->assertSuccessful();

        self::assertSame(['John'], $this->projector()->names());
    }

    private function setEnv(string $name, string|null $value): void
    {
        if ($value === null) {
            unset($_ENV[$name], $_SERVER[$name]);
            putenv($name);

            return;
        }

        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
        putenv($name . '=' . $value);
    }
}
