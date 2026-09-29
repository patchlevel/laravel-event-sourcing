<?php

declare(strict_types=1);

namespace Patchlevel\LaravelEventSourcing\Tests\Application;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\CoversNothing;

use function array_keys;
use function config_path;
use function database_path;
use function file_exists;
use function glob;
use function unlink;

#[CoversNothing]
final class InstallationTest extends ApplicationTestCase
{
    public function tearDown(): void
    {
        foreach ($this->publishedFiles() as $file) {
            unlink($file);
        }

        parent::tearDown();
    }

    public function testConfigIsPublished(): void
    {
        $this->artisan('vendor:publish', ['--tag' => 'patchlevel-config'])->assertSuccessful();

        self::assertFileEquals(__DIR__ . '/../../config/event-sourcing.php', config_path('event-sourcing.php'));
    }

    public function testMigrationIsPublished(): void
    {
        $this->artisan('vendor:publish', ['--tag' => 'patchlevel-migrations'])->assertSuccessful();

        $published = glob(database_path('migrations/*_create_eventsourcing_tables.php')) ?: [];

        self::assertCount(1, $published);
        self::assertFileEquals(
            __DIR__ . '/../../database/migrations/0001_01_01_000000_create_eventsourcing_tables.php',
            $published[0],
        );
    }

    public function testMigrationCreatesTheTables(): void
    {
        self::assertTrue(Schema::hasTable('event_store'));
        self::assertTrue(Schema::hasTable('subscriptions'));
        self::assertTrue(Schema::hasTable('crypto_keys'));
    }

    public function testDbalSchemaCommandsAreNotRegistered(): void
    {
        $commands = array_keys(Artisan::all());

        self::assertContains('event-sourcing:subscription:run', $commands);
        self::assertNotContains('event-sourcing:schema:create', $commands);
        self::assertNotContains('event-sourcing:database:create', $commands);
    }

    /** @return list<string> */
    private function publishedFiles(): array
    {
        $files = glob(database_path('migrations/*_create_eventsourcing_tables.php')) ?: [];

        if (file_exists(config_path('event-sourcing.php'))) {
            $files[] = config_path('event-sourcing.php');
        }

        return $files;
    }
}
