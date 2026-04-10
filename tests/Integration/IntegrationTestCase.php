<?php

declare(strict_types=1);

namespace Patchlevel\LaravelEventSourcing\Tests\Integration;

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
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
        $migration = require __DIR__ . '/../../database/migrations/create_eventsourcing_tables.php';
        $migration->up();
    }

    public function tearDown(): void
    {
        $this->connection->disconnect();
        SendEmailMock::reset();
        parent::tearDown();
    }
}
