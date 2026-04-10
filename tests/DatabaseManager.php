<?php

declare(strict_types=1);

namespace Patchlevel\LaravelEventSourcing\Tests;

use Illuminate\Database\ConfigurationUrlParser;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

use function getenv;
use function is_string;
use function uniqid;

final class DatabaseManager
{
    public const DEFAULT_DB_NAME = 'event_store';

    public static function createConnection(string $dbName = self::DEFAULT_DB_NAME, bool $forceNewConnection = false): Connection
    {
        $dbUrl = getenv('DB_URL');

        if (!is_string($dbUrl)) {
            throw new RuntimeException('missing DB_URL env');
        }

        $config = (new ConfigurationUrlParser())->parseConfiguration($dbUrl);

        $driver = $config['driver'] ?? null;

        if (!is_string($driver)) {
            throw new RuntimeException('missing driver in DB_URL');
        }

        if ($driver === 'sqlite') {
            if (!$forceNewConnection) {
                return DB::connection();
            }

            return self::makeConnection($config);
        }

        if (!$forceNewConnection) {
            self::recreateDatabase($config, $dbName);
        }

        $config['database'] = $dbName;

        return self::makeConnection($config);
    }

    /** @param array<string, mixed> $config */
    private static function recreateDatabase(array $config, string $dbName): void
    {
        $driver = $config['driver'] ?? null;

        if (!is_string($driver)) {
            throw new RuntimeException('missing driver in DB_URL');
        }

        $adminConfig = $config;

        // Postgres cannot drop the currently connected database.
        if ($driver === 'pgsql') {
            $adminConfig['database'] = 'postgres';
        }

        $adminConnection = self::makeConnection($adminConfig);

        try {
            if ($driver === 'pgsql') {
                $adminConnection->statement(
                    'SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = ? AND pid <> pg_backend_pid()',
                    [$dbName],
                );
            }

            $schemaBuilder = $adminConnection->getSchemaBuilder();
            $schemaBuilder->dropDatabaseIfExists($dbName);
            $schemaBuilder->createDatabase($dbName);
        } finally {
            $adminConnection->disconnect();
        }
    }

    /** @param array<string, mixed> $config */
    private static function makeConnection(array $config): Connection
    {
        if (!isset($config['prefix'])) {
            $config['prefix'] = '';
        }

        $name = 'event-sourcing-' . uniqid('', true);
        config()->set("database.connections.$name", $config);
        DB::purge($name);

        $connection = DB::connection($name);

        if (!$connection instanceof Connection) {
            throw new RuntimeException('No default connection found');
        }

        return $connection;
    }
}
