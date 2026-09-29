<?php

declare(strict_types=1);

namespace Patchlevel\LaravelEventSourcing\Tests\Integration;

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Patchlevel\LaravelEventSourcing\Tests\DatabaseManager;
use Patchlevel\LaravelEventSourcing\Tests\Integration\BasicImplementation\SendEmailMock;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class IntegrationTestCase extends Orchestra
{
    protected Connection $connection;

    public function setUp(): void
    {
        parent::setUp();

        $this->connection = DatabaseManager::createConnection();
        DB::setDefaultConnection($this->connection->getName());

        Schema::dropIfExists('event_store');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('crypto_keys');
        Schema::dropIfExists('cryptography_keys');
        $migration = require __DIR__ . '/../../database/migrations/0001_01_01_000000_create_eventsourcing_tables.php';
        $migration->up();
    }

    /** The migration ships this table commented out, the user enables it with the hydrator cryptography. */
    protected function createCryptographyKeysTable(): void
    {
        Schema::create('cryptography_keys', static function (Blueprint $table): void {
            $table->string('id', 255);
            $table->string('subject_id', 255);
            $table->string('crypto_key', 255);
            $table->string('crypto_method', 255);
            $table->dateTimeTz('created_at');
            $table->primary('id');
            $table->index('subject_id');
        });
    }

    public function tearDown(): void
    {
        $this->connection->disconnect();
        SendEmailMock::reset();
        parent::tearDown();
    }
}
